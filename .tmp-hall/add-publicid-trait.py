#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Добавить HasPublicId девяти моделям, чьи таблицы требуют public_id."""
import io
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
IMPORT = 'use Nabilet\\Modules\\Core\\Models\\Concerns\\HasPublicId;'
TRAIT_LINE = '    use HasPublicId;'

FILES = [
    'app/Modules/Analytics/Models/AbExperiment.php',
    'app/Modules/Content/Models/Page.php',
    'app/Modules/Events/Models/EventCategory.php',
    'app/Modules/Orders/Models/SeatHold.php',
    'app/Modules/Organizations/Models/Organization.php',
    'app/Modules/System/Models/ApiKey.php',
    'app/Modules/System/Models/Webhook.php',
    'app/Modules/Tickets/Models/Ticket.php',
    'app/Modules/Tickets/Models/TicketScan.php',
]

report = []


def read(path):
    with io.open(path, 'r', encoding='utf-8', newline='') as fh:
        return fh.read()


def write(path, text):
    with io.open(path, 'w', encoding='utf-8', newline='') as fh:
        fh.write(text)


for rel in FILES:
    path = os.path.join(ROOT, rel)
    text = read(path)
    original = text
    eol = '\r\n' if '\r\n' in text else '\n'
    lines = text.splitlines(keepends=True)

    # 1. Импорт — в алфавитном порядке среди use Nabilet\...
    if IMPORT not in text:
        idx = None
        last = None
        for i, line in enumerate(lines):
            s = line.strip()
            if not s.startswith('use Nabilet\\'):
                continue
            last = i
            if idx is None and s.rstrip(';') > IMPORT.rstrip(';'):
                idx = i
        if idx is None:
            if last is None:
                # Файл без единого `use Nabilet\…`: ставим после последнего
                # импорта вообще — `Nabilet\…` идёт после `Illuminate\…`.
                for i, line in enumerate(lines):
                    if line.startswith('use '):
                        last = i
                if last is None:
                    raise SystemExit('нет ни одного use в %s' % rel)
                idx = last + 1
            else:
                idx = last + 1
        lines.insert(idx, IMPORT + eol)

    # 2. use HasPublicId; сразу после открывающей скобки класса
    class_idx = None
    for i, line in enumerate(lines):
        if re.match(r'^(final )?class \w+ extends ', line.strip()):
            class_idx = i
            break
    if class_idx is None:
        raise SystemExit('не найдено объявление класса в %s' % rel)

    brace_idx = None
    for i in range(class_idx, min(class_idx + 4, len(lines))):
        if lines[i].strip() == '{':
            brace_idx = i
            break
    if brace_idx is None:
        raise SystemExit('не найдена скобка класса в %s' % rel)

    if lines[brace_idx + 1].strip() != TRAIT_LINE.strip():
        lines.insert(brace_idx + 1, TRAIT_LINE + eol)
        lines.insert(brace_idx + 2, eol)

    new = ''.join(lines)
    if new == original:
        report.append('%-58s без изменений' % rel)
        continue
    write(path, new)
    report.append('%-58s trait+import' % rel)

print('\n'.join(report))
