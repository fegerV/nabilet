"""Перенос семи моделей из App\\Models в их модули.

Зачем: модули импортировали `App\\Models\\*` — инверсия зависимостей, из-за
которой `app/Models` оставался «слоем, на который все ссылаются», хотя это
должен быть только совместимостный слой с шимами.

ЧТО ЗДЕСЬ ЛЕГКО СЛОМАТЬ МОЛЧА
  1. `belongsTo(User::class)` внутри `App\\Models` резолвится через ОБЩИЙ
     namespace. После переноса то же имя указывает в новый namespace, где такого
     класса нет: PHP упадёт только при вызове связи, а связи
     `Consent::user()`, `PrivacyRequest::user()`, `Notification::user()` сейчас
     не вызывает никто — значит тесты этого не увидят. Поэтому каждой модели,
     которой нужен User, дописывается явный импорт.
  2. Замена `App\\Models\\Notification` испортила бы
     `App\\Models\\NotificationTemplate` (первое — префикс второго), а
     `App\\Models\\Webhook` — `App\\Models\\WebhookDelivery`. Замены идут от
     длинных имён к коротким.
  3. Ссылки бывают не только в `use`: `FollowNotification` пишет
     `\\App\\Models\\Notification::class` прямо в теле.

`--check` печатает план и ничего не меняет.
"""
import io
import os
import re
import sys

# name -> (target dir, new namespace, extra imports)
MOVES = [
    ('Setting',            'app/Modules/Core/Models',          'Nabilet\\Modules\\Core\\Models',          []),
    ('Notification',       'app/Modules/Notifications/Models', 'Nabilet\\Modules\\Notifications\\Models', ['Nabilet\\Modules\\Core\\Users\\Models\\User']),
    ('NotificationTemplate', 'app/Modules/Notifications/Models', 'Nabilet\\Modules\\Notifications\\Models', []),
    ('Webhook',            'app/Modules/Webhooks/Models',      'Nabilet\\Modules\\Webhooks\\Models',      []),
    ('WebhookDelivery',    'app/Modules/Webhooks/Models',      'Nabilet\\Modules\\Webhooks\\Models',      []),
    ('Consent',            'app/Modules/Privacy/Models',       'Nabilet\\Modules\\Privacy\\Models',       ['Nabilet\\Modules\\Core\\Users\\Models\\User']),
    ('PrivacyRequest',     'app/Modules/Privacy/Models',       'Nabilet\\Modules\\Privacy\\Models',       ['Nabilet\\Modules\\Core\\Users\\Models\\User']),
]

SKIP_DIRS = {'vendor', 'node_modules', '.git', '.shots', '.tmp-hall', 'outputs', 'dist', 'public', 'storage'}
EXT = ('.php', '.json', '.neon', '.yaml', '.yml')

check_only = len(sys.argv) > 1 and sys.argv[1] == '--check'

# ── 1. проверка исходников ────────────────────────────────────────────────
plan = []
for name, target_dir, new_ns, extra in MOVES:
    src = f'app/Models/{name}.php'
    if not os.path.exists(src):
        raise SystemExit(f'нет файла {src}')
    text = io.open(src, encoding='utf-8').read()
    if 'namespace App\\Models;' not in text:
        raise SystemExit(f'{src}: не найден ожидаемый namespace')

    # Связи, которые резолвятся через общий namespace и после переноса сломаются.
    refs = sorted(set(re.findall(r'\b([A-Z][A-Za-z0-9_]*)::class', text)))
    broken = [r for r in refs if r in ('User', 'Webhook', 'Consent', 'PrivacyRequest', 'Notification', 'Setting', 'NotificationTemplate', 'WebhookDelivery')]
    need = [r for r in broken if r == 'User' and 'Nabilet\\Modules\\Core\\Users\\Models\\User' not in extra]
    if need:
        raise SystemExit(f'{src}: нужен импорт User, но его нет в extra')
    same_ns = [r for r in broken if r != 'User']
    print(f'{name:22s} -> {target_dir:36s} связи: {", ".join(refs) or "-"}')

    plan.append((name, src, os.path.join(target_dir, f'{name}.php'), new_ns, extra, text))

# ── 2. карта замен (длинные имена первыми) ────────────────────────────────
replacements = sorted(
    ((f'App\\Models\\{name}', new_ns + '\\' + name) for name, _, _, new_ns, _, _ in plan),
    key=lambda p: -len(p[0]),
)

# ── 3. сбор ссылок ────────────────────────────────────────────────────────
hits = []
for dirpath, dirnames, filenames in os.walk('.'):
    dirnames[:] = [d for d in dirnames if d not in SKIP_DIRS]
    for fn in filenames:
        if not fn.endswith(EXT):
            continue
        p = os.path.join(dirpath, fn)
        try:
            text = io.open(p, encoding='utf-8').read()
        except UnicodeDecodeError:
            continue
        if not any(old in text for old, _ in replacements):
            continue
        for i, line in enumerate(text.split('\n'), 1):
            for old, new in replacements:
                if old in line:
                    hits.append((p.replace('\\', '/'), i, old, new, line.strip()[:100]))

print(f'\nфайлов со ссылками: {len({h[0] for h in hits})}, ссылок: {len(hits)}')
for p, i, old, new, line in hits:
    print(f'  {p}:{i}  {old} -> {new.split(chr(92))[-2]}\\{new.split(chr(92))[-1]}')

if check_only:
    raise SystemExit(0)

# ── 4. перенос файлов ─────────────────────────────────────────────────────
for name, src, dst, new_ns, extra, text in plan:
    text = text.replace('namespace App\\Models;', f'namespace {new_ns};', 1)

    for imp in extra:
        if f'use {imp};' in text:
            continue
        lines = text.split('\n')
        last_use = max(i for i, l in enumerate(lines) if re.match(r'^use [^;]+;$', l))
        lines.insert(last_use + 1, f'use {imp};')
        text = '\n'.join(lines)

    os.makedirs(os.path.dirname(dst), exist_ok=True)
    io.open(dst, 'w', encoding='utf-8', newline='\n').write(text)
    os.remove(src)
    print(f'перенесено: {src} -> {dst}')

# ── 5. обновить ссылки ────────────────────────────────────────────────────
touched = set()
for dirpath, dirnames, filenames in os.walk('.'):
    dirnames[:] = [d for d in dirnames if d not in SKIP_DIRS]
    for fn in filenames:
        if not fn.endswith(EXT):
            continue
        p = os.path.join(dirpath, fn)
        try:
            text = io.open(p, encoding='utf-8').read()
        except UnicodeDecodeError:
            continue
        new_text = text
        for old, new in replacements:
            new_text = new_text.replace(old, new)
        if new_text != text:
            io.open(p, 'w', encoding='utf-8', newline='\n').write(new_text)
            touched.add(p.replace('\\', '/'))

print(f'\nобновлено файлов: {len(touched)}')
for t in sorted(touched):
    print('  ', t)
