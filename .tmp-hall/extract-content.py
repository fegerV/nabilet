"""Вынести «тексты страницы» из EventPageController в EventPageContent.

Тот же приём, что и с оболочкой: блоки берутся по номерам строк и переносятся
байт-в-байт, кроме `private function` → `public function` у четырёх входных
точек (остальные восемь остаются private — их зовут только изнутри класса).

Почему не перепечатка: комментарии здесь объясняют, ПОЧЕМУ текст такой
(«прошедшее событие не должно обещать покупку»), и опечатка в объяснении
переживёт рефакторинг, выглядя авторитетно.

`--check` печатает границы, контрольные суммы и внешние символы, ничего не меняя.
"""
import hashlib
import io
import re
import sys

SRC = 'app/Modules/Events/Http/Controllers/EventPageController.php'
DST = 'app/Modules/Events/Http/EventPageContent.php'

# Порядок как в файле: он же порядок в новом классе.
MOVE = [
    'venuePayload',
    'buildTitle',
    'statusNote',
    'statusBanner',
    'bodyFallback',
    'contentFallback',
    'artistLinks',
    'sessionLine',
    'venueLine',
    'leadText',
    'priceLine',
    'buildDescription',
]

# Единственные методы, которые зовёт контроллер.
PUBLIC = {'venuePayload', 'buildTitle', 'buildDescription', 'bodyFallback'}

lines = io.open(SRC, encoding='utf-8').read().split('\n')


def docblock_start(decl_line: int) -> int:
    """Начало docblock метода (1-based) либо сам номер объявления.

    Идём вверх до ближайшего `*/`, затем до парного `/**`. Наивный поиск
    «первой строки с /**» проскакивает свой docblock и цепляется к предыдущему
    методу — эта ошибка уже была допущена на первом выносе.
    """
    i = decl_line - 2
    while i > 0 and lines[i].strip() == '':
        i -= 1
    if lines[i].strip() != '*/':
        return decl_line
    while i > 0 and not lines[i].strip().startswith('/**'):
        i -= 1
    return i + 1


def find_method(name: str):
    decl = None
    for idx, l in enumerate(lines):
        if re.match(r'    (?:public|private|protected)(?: static)? function ' + name + r'\(', l):
            decl = idx + 1
            break
    if decl is None:
        raise SystemExit(f'метод {name} не найден')

    depth = 0
    started = False
    for j in range(decl - 1, len(lines)):
        depth += lines[j].count('{') - lines[j].count('}')
        if '{' in lines[j]:
            started = True
        if started and depth == 0:
            return docblock_start(decl), j + 1
    raise SystemExit(f'не найден конец метода {name}')


blocks = []
for name in MOVE:
    s, e = find_method(name)
    text = '\n'.join(lines[s - 1:e])
    blocks.append((name, s, e, text))
    print(f'{name:20s} {s:5d}-{e:5d}  {e - s + 1:4d} строк  sha1={hashlib.sha1(text.encode()).hexdigest()[:12]}')

ordered = sorted(blocks, key=lambda b: b[1])
for a, b in zip(ordered, ordered[1:]):
    if a[2] >= b[1]:
        raise SystemExit(f'блоки пересекаются: {a[0]} и {b[0]}')
    # Между блоками допустима ровно одна пустая строка: иначе в диапазон
    # попал метод, которого нет в MOVE, и он исчезнет из файла молча.
    if b[1] - a[2] != 2:
        raise SystemExit(
            f'между {a[0]} (до {a[2]}) и {b[0]} (с {b[1]}) не пустая строка — '
            'в диапазоне есть метод, не указанный в MOVE'
        )

lo, hi = ordered[0][1], ordered[-1][2]
print(f'\nдиапазон целиком: {lo}-{hi}  ({hi - lo + 1} строк, {len(blocks)} методов)')

# Внешние символы: что придётся импортировать в новый класс.
body = '\n'.join(t for _, _, _, t in blocks)
code = re.sub(r'/\*.*?\*/', '', body, flags=re.S)
code = re.sub(r'//[^\n]*', '', code)
syms = set()
for m in re.finditer(r'(?<![\w\\$>:])([A-Z][A-Za-z0-9_]*)::', code):
    syms.add(m.group(1) + '::')
for m in re.finditer(r'(?<![\w\\$>:])([A-Z][A-Za-z0-9_]*)\s*\(', code):
    if m.group(1) not in ('If', 'Else', 'ENT_QUOTES'):
        syms.add(m.group(1) + '()')
print('внешние символы:', sorted(syms))
print('self::', sorted(set(re.findall(r'self::(\w+)', code))))

if len(sys.argv) > 1 and sys.argv[1] == '--check':
    raise SystemExit(0)

# --- новый класс ---------------------------------------------------------
chunks = []
for name, s, e, text in blocks:
    if name in PUBLIC:
        text = text.replace(f'    private function {name}(', f'    public function {name}(', 1)
        if f'public function {name}(' not in text:
            raise SystemExit(f'не удалось сделать {name} public')
    chunks.append(text)

header = '''<?php

declare(strict_types=1);

namespace Nabilet\\Modules\\Events\\Http;

use Nabilet\\Core\\Support\\Money;
use Nabilet\\Modules\\Events\\Domain\\EventStatus;
use Nabilet\\Modules\\Events\\Models\\Event;

/**
 * Тексты страницы события: заголовок, описание, серверный блок и площадка.
 *
 * Вынесено из `EventPageController` вторым шагом разбиения (первым была
 * `EventPageShell` — сборка самого HTML-документа). Контроллер разбирал запрос,
 * собирал разметку, форматировал даты, цены и адреса — и всё это в одном файле
 * на 1480 строк, где правка формулировки требовала чтения маршрутизации.
 *
 * ЧТО ЗДЕСЬ ВАЖНО ПОМНИТЬ
 *   Тексты зависят от ЖИЗНЕННОГО ЦИКЛА события: прошедшее и отменённое не
 *   должны обещать покупку билета. Поэтому `buildTitle()`/`buildDescription()`
 *   читают статус, а `statusNote()`/`statusBanner()` существуют отдельно —
 *   сниппет сообщает статус, страница рассказывает о событии.
 *   Ровно один `<h1>` на страницу в любом статусе: заголовок печатает либо
 *   блок статуса, либо контентный блок (см. `bodyFallback()`).
 *
 * Зависимостей от репозиториев и сервисов нет: только модель события, её
 * связи (`artists`, `sessions.venue`) и глобальные `route()`/`url()`.
 */
final class EventPageContent
{
'''

footer = '}\n'

io.open(DST, 'w', encoding='utf-8', newline='\n').write(header + '\n'.join(chunks) + '\n' + footer)

# --- вырезать диапазон из контроллера ------------------------------------
# 0-based: [lo-1, hi) — сами методы; плюс следующая пустая строка, чтобы не
# осталось двух подряд (одна уже есть перед диапазоном).
remaining = lines[:lo - 1] + lines[hi + 1:]
ctrl = '\n'.join(remaining)
ctrl = re.sub(r'\n{3,}', '\n\n', ctrl)

ctrl = ctrl.replace('$this->buildTitle(', '$this->content->buildTitle(')
ctrl = ctrl.replace('$this->buildDescription(', '$this->content->buildDescription(')
ctrl = ctrl.replace('$this->venuePayload(', '$this->content->venuePayload(')
ctrl = ctrl.replace('$this->bodyFallback(', '$this->content->bodyFallback(')
ctrl = ctrl.replace(
    '        private readonly EventPageShell $shell\n    ) {}',
    '        private readonly EventPageShell $shell,\n'
    '        private readonly EventPageContent $content\n    ) {}',
    1,
)
ctrl = ctrl.replace(
    'use Nabilet\\Modules\\Events\\Http\\EventPageShell;',
    'use Nabilet\\Modules\\Events\\Http\\EventPageContent;\nuse Nabilet\\Modules\\Events\\Http\\EventPageShell;',
    1,
)
io.open(SRC, 'w', encoding='utf-8', newline='\n').write(ctrl)
print('\nготово')
