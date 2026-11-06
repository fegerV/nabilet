"""Ищет литеральные списки строк (словари), выписанные в нескольких файлах.

Метод сканирования тел исчерпан (0 групп), но у класса дефекта есть второй
вход: не скопированный МЕТОД, а скопированный СПИСОК. `['admin','manager']`
в пяти файлах — тот же дефект, только тексты в нём короче и заметить их труднее.
"""

import io
import os
import re
import sys
from collections import defaultdict

ROOT = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'app')

# Литерал: [ 'a', 'b', ... ] или [ "a", "b", ... ] — только строки.
ARRAY = re.compile(r'\[\s*((?:[\'"][^\'"]+[\'"]\s*,\s*)+[\'"][^\'"]+[\'"]\s*,?\s*)\]')
STRING = re.compile(r'[\'"]([^\'"]+)[\'"]')
COMMENTS = re.compile(r'/\*.*?\*/|//[^\n]*', re.S)


def files():
    for base, _, names in os.walk(ROOT):
        for name in names:
            if name.endswith('.php'):
                yield os.path.join(base, name)


def main():
    seen = defaultdict(list)
    for path in files():
        src = io.open(path, encoding='utf-8', errors='replace').read()
        src = COMMENTS.sub('', src)
        for match in ARRAY.finditer(src):
            items = STRING.findall(match.group(1))
            if len(items) < 3:
                continue
            key = tuple(sorted(items))
            rel = os.path.relpath(path, os.path.dirname(ROOT)).replace('\\', '/')
            if rel not in [p for p, _ in seen[key]]:
                seen[key].append((rel, match.group(0)[:120]))

    groups = {k: v for k, v in seen.items() if len(v) >= 2}
    print(f'списков из >=3 строк: {len(seen)}; встречаются в >=2 файлах: {len(groups)}')
    for key, places in sorted(groups.items(), key=lambda kv: -len(kv[1])):
        print()
        print('  ' + ', '.join(key))
        for rel, text in places:
            print(f'    {rel}  {text}')
    if not groups:
        print('(дублей нет)')


if __name__ == '__main__':
    sys.exit(main())
