"""Скан одинаковых тел методов по app/.

Грубый, но достаточный: идём по строкам, ищем `function name(` и берём тело
до парной закрывающей скобки на нулевом отступе метода (4 пробела + `}`).
Нормализуем пробелы и комментарии, чтобы «одинаково» значило «одинаково по сути».
"""
import io
import os
import re
import sys
from collections import defaultdict

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'app')
ROOT = os.path.normpath(ROOT)

FN = re.compile(r'^\s{0,8}(?:public|protected|private|final|static|\s)*function\s+(\w+)\s*\(')


def strip_comments(text: str) -> str:
    out = []
    i = 0
    n = len(text)
    while i < n:
        c = text[i]
        if c == '/' and i + 1 < n and text[i + 1] == '/':
            while i < n and text[i] != '\n':
                i += 1
        elif c == '/' and i + 1 < n and text[i + 1] == '*':
            i += 2
            while i + 1 < n and not (text[i] == '*' and text[i + 1] == '/'):
                i += 1
            i += 2
        elif c in ('"', "'"):
            q = c
            out.append(c)
            i += 1
            while i < n:
                if text[i] == '\\':
                    out.append(text[i:i + 2])
                    i += 2
                    continue
                out.append(text[i])
                if text[i] == q:
                    i += 1
                    break
                i += 1
        else:
            out.append(c)
            i += 1
    return ''.join(out)


def methods(path: str):
    with io.open(path, encoding='utf-8', errors='replace') as fh:
        src = fh.read().replace('\r\n', '\n')
    lines = src.split('\n')
    i = 0
    while i < len(lines):
        m = FN.match(lines[i])
        if not m:
            i += 1
            continue
        name = m.group(1)
        # ищем открывающую скобку тела
        j = i
        while j < len(lines) and '{' not in lines[j]:
            j += 1
        if j >= len(lines):
            break
        start = j
        depth = 0
        k = j
        while k < len(lines):
            depth += lines[k].count('{') - lines[k].count('}')
            if depth <= 0:
                break
            k += 1
        body = '\n'.join(lines[start:k + 1])
        yield name, i + 1, body
        i = k + 1


groups = defaultdict(list)
for dirpath, _dirnames, filenames in os.walk(ROOT):
    for fn in filenames:
        if not fn.endswith('.php'):
            continue
        path = os.path.join(dirpath, fn)
        rel = os.path.relpath(path, ROOT).replace('\\', '/')
        for name, lineno, body in methods(path):
            clean = strip_comments(body)
            clean = re.sub(r'\s+', ' ', clean).strip()
            if len(clean) < 120:
                continue
            groups[clean].append((rel, name, lineno, len(clean)))

dupes = {k: v for k, v in groups.items() if len(v) > 1}
rows = sorted(dupes.items(), key=lambda kv: -len(kv[1]) * kv[1][0][3])
print(f'групп одинаковых тел: {len(rows)}')
for clean, sites in rows[:25]:
    print('---')
    print(f'  размер {sites[0][3]} симв., копий {len(sites)}')
    for rel, name, lineno, _ in sites:
        print(f'    {rel}:{lineno}  {name}()')
