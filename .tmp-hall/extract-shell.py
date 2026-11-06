"""Вынести «оболочку страницы» из EventPageController в отдельный класс.

Перенос механический: блоки берутся из файла по номерам строк и переносятся
байт-в-байт (кроме `private function` → `public function` у двух входных точек).
Комментарии — половина ценности этого кода, поэтому перепечатывать их вручную
нельзя: любая опечатка в объяснении «почему так» переживёт рефакторинг и будет
выглядеть авторитетно.

Скрипт печатает контрольные суммы перенесённых блоков, чтобы проверить перенос
независимо от git.
"""
import hashlib
import io
import re
import sys

SRC = 'app/Modules/Events/Http/Controllers/EventPageController.php'
DST = 'app/Modules/Events/Http/EventPageShell.php'

lines = io.open(SRC, encoding='utf-8').read().split('\n')


def docblock_start(decl_line: int) -> int:
    """Номер строки (1-based) начала docblock для метода.

    Идём вверх до ближайшего `*/`, затем до парного `/**`. Наивный поиск
    «первой строки, начинающейся с /**» проскакивает мимо своего docblock и
    приклеивается к предыдущему методу — так у renderSpaShell находился
    docblock buildDescription.
    """
    i = decl_line - 2  # индекс строки перед объявлением
    while i > 0 and lines[i].strip() == '':
        i -= 1
    if lines[i].strip() != '*/':
        return decl_line
    while i > 0 and not lines[i].strip().startswith('/**'):
        i -= 1
    return i + 1


def find_method(name: str):
    for idx, l in enumerate(lines):
        if re.match(r'    (?:public|private|protected)(?: static)? function ' + name + r'\(', l):
            decl = idx + 1
            break
    else:
        raise SystemExit(f'метод {name} не найден')

    start = docblock_start(decl)
    depth = 0
    started = False
    for j in range(decl - 1, len(lines)):
        depth += lines[j].count('{') - lines[j].count('}')
        if '{' in lines[j]:
            started = True
        if started and depth == 0:
            return start, j + 1
    raise SystemExit(f'не найден конец метода {name}')


MOVE = [
    'seoVerificationAndGeoTags',
    'spaShellPath',
    'renderSpaShell',
    'dataLayerEventPageSnippet',
    'ogImageTags',
    'breadcrumbScript',
    'safeJsonLd',
]

blocks = []
for name in MOVE:
    s, e = find_method(name)
    text = '\n'.join(lines[s - 1:e])
    blocks.append((name, s, e, text))
    print(f'{name:28s} {s:5d}-{e:5d}  {e - s + 1:4d} строк  sha1={hashlib.sha1(text.encode()).hexdigest()[:12]}')

# Проверка: блоки не пересекаются.
ordered = sorted(blocks, key=lambda b: b[1])
for a, b in zip(ordered, ordered[1:]):
    if a[2] >= b[1]:
        raise SystemExit(f'блоки пересекаются: {a[0]} и {b[0]}')

if len(sys.argv) > 1 and sys.argv[1] == '--check':
    raise SystemExit(0)

# --- новый класс ---------------------------------------------------------
shell = []
for name, s, e, text in blocks:
    if name == 'renderSpaShell':
        text = text.replace('private function renderSpaShell(', 'public function render(', 1)
        text = text.replace('$this->spaShellPath()', '$this->path()')
    elif name == 'spaShellPath':
        text = text.replace('private function spaShellPath(', 'public function path(', 1)
        text = text.replace(
            ' * Путь к собранной витрине. Отдельным методом, потому что его читают двое:\n'
            ' * проверка «собрано ли» в `show()` и сам `renderSpaShell()`.',
            ' * Путь к собранной витрине. Читают двое: проверка «собрано ли» в\n'
            ' * `EventPageController::show()` и сам `render()`.',
            1,
        )
    shell.append(text)

header = '''<?php

declare(strict_types=1);

namespace Nabilet\\Modules\\Events\\Http;

/**
 * Сборка HTML-документа страницы события для поисковых роботов.
 *
 * Вынесено из `EventPageController` механическим переносом: контроллер был
 * 1480 строк и 33 методов, и половина из них собирала документ, а не
 * разбирала запрос. Здесь только «взять index.html и подставить SEO-блоки» —
 * ни одной зависимости от модели события, репозитория или сервисов, поэтому
 * класс не требует ни DI, ни БД и проверяется тестами страницы как раньше.
 *
 * ЧТО ЗДЕСЬ ВАЖНО ПОМНИТЬ
 *   `render()` подставляет блоки в собранный Vite `index.html` строковыми
 *   заменами, ограниченными первым вхождением (`limit = 1`). Это не стиль, а
 *   условие: без лимита замена `<title>` задела бы и заголовки внутри
 *   инлайн-скриптов, а `<div id="app">` — упоминания в комментариях.
 *   Подробности — в docblock самого `render()`.
 */
final class EventPageShell
{
'''

footer = '}\n'

io.open(DST, 'w', encoding='utf-8', newline='\n').write(header + '\n'.join(shell) + '\n' + footer)

# --- убрать блоки из контроллера, снизу вверх ---------------------------
remaining = list(lines)
for name, s, e, text in sorted(blocks, key=lambda b: -b[1]):
    # s — 1-based номер первой строки блока, поэтому индекс = s-1.
    # Съедаем пустые строки-разделители ПЕРЕД блоком, но не строку кода:
    # первая версия брала `del remaining[s-2:e]` и уносила закрывающую `}`
    # предыдущего метода — файл переставал парситься.
    from_ = s - 1
    while from_ > 0 and remaining[from_ - 1].strip() == '':
        from_ -= 1
    del remaining[from_:e]

ctrl = '\n'.join(remaining)
ctrl = ctrl.replace('$this->renderSpaShell(', '$this->shell->render(')
ctrl = ctrl.replace('$this->spaShellPath()', '$this->shell->path()')
ctrl = ctrl.replace(
    '        private readonly StructuredDataService $structuredData\n    ) {}',
    '        private readonly StructuredDataService $structuredData,\n'
    '        private readonly EventPageShell $shell\n    ) {}',
    1,
)
io.open(SRC, 'w', encoding='utf-8', newline='\n').write(ctrl)
print('\nготово')
