#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""Импорт схема зала со скриншота виджета (Яндекс.Афиша и любые другие).

ЗАЧЕМ. Готовая схема у организатора живёт только внутри окна покупки
(Яндекс.Билеты, kassa-ugra и пр.), API закрыт CSRF + antibot, поэтому
единственный практичный источник геометрии — СКРИНШОТ карты мест.

ЧТО ДЕЛАЕТ. Найдёт кружки мест (HoughCircles), соберёт их в ряды
(цепочки ближайших соседей), разобьёт крупные разрывы на секторы и
выдаст JSON в формате конструктора схем NABILET (тот же, что принимает
«Импорт» в редакторе схемы зала) плюс PNG-оверлей для визуальной
проверки: найденные места обведены, рядом — номер ряда.

КАК ПОЛЬЗОВАТЬСЯ
    python tools/scheme-from-screenshot.py shot.png --out schema.json
    python tools/scheme-from-screenshot.py shot.png --out schema.json \
        --stage bottom --min-r 5 --max-r 18

    1. Снять скриншот карты мест в окне покупки (Win+Shift+S), так чтобы
       места занимали большую часть кадра.
    2. Запустить скрипт; открыть overlay.png и сверить с оригиналом.
    3. Импортировать schema.json в редакторе схемы («Импорт»), при
       необходимости поправить в конструкторе (дуги, цены, сцена,
       подписи секторов), опубликовать.

ПАРАМЕТРЫ
    --stage top|bottom  где сцена: номером 1 нумеруется ближний к сцене ряд
                        (по умолчанию top — сцена сверху, как в Афише).
    --min-r / --max-r   диапазон радиуса места в пикселях скриншота.
    --sector-gap        разрыв (px) между цепочками, начиная с которого
                        места считаются разными секторами.

Результат предназначен как черновик: цены и названия секторов правятся
уже в конструкторе (§50), а «Выровнять все столы» чинит дуги.
"""
from __future__ import annotations

import argparse
import json
import math
import os
import sys

import cv2
import numpy as np


def detect_circles(gray: np.ndarray, min_r: int, max_r: int) -> np.ndarray:
    """(x, y, r) всех кругов, похожих на места.

    Основной путь — контуры (устойчив и к залитым, и к контурным местам);
    HoughCircles — резерв на «шумных» скриншотах, где контуры сливаются.
    """
    blurred = cv2.GaussianBlur(gray, (5, 5), 1.0)
    # Фиксированный порог: фон светлый, места рисуются тёмным/цветным.
    # (Otsu смещается к фону, когда линий мало — теряются тонкие кольца.)
    _, binary = cv2.threshold(blurred, 190, 255, cv2.THRESH_BINARY_INV)
    contours, _ = cv2.findContours(binary, cv2.RETR_LIST, cv2.CHAIN_APPROX_NONE)

    found: list[tuple[float, float, float]] = []
    lo_area = math.pi * max(4, min_r ** 2) * 0.45
    hi_area = math.pi * (max_r + 2) ** 2 * 1.6
    for cnt in contours:
        area = cv2.contourArea(cnt)
        if area < lo_area or area > hi_area:
            continue
        (x, y), r = cv2.minEnclosingCircle(cnt)
        if r < min_r or r > max_r + 2:
            continue
        # Круглость: площадь контура против площади описанной окружности.
        if area / (math.pi * r * r) < 0.55:
            continue
        found.append((x, y, r))

    found.sort(key=lambda c: -c[2])
    deduped: list[tuple[float, float, float]] = []
    for x, y, r in found:
        # Кольцо даёт два контура (внешний и внутренний) с тем же центром —
        # оставляем только больший (внешний).
        if all(math.hypot(x - px, y - py) > 0.6 * max(r, pr) for px, py, pr in deduped):
            deduped.append((x, y, r))

    if len(deduped) >= 6:
        return np.array(deduped, dtype=float)

    circles = cv2.HoughCircles(
        blurred, cv2.HOUGH_GRADIENT, dp=1.2,
        minDist=max(6, int(min_r * 1.6)), param1=70, param2=16,
        minRadius=min_r, maxRadius=max_r,
    )
    if circles is None:
        return np.array(deduped, dtype=float)
    merged = np.vstack([np.array(deduped, dtype=float), circles[0]]) if deduped else circles[0]
    return merged


def filter_outliers(circles: np.ndarray) -> np.ndarray:
    """Отбрасывает круги с нетипичным радиусом (логотипы, буквы)."""
    if len(circles) < 3:
        return circles
    med_r = float(np.median(circles[:, 2]))
    keep = np.abs(circles[:, 2] - med_r) <= max(2.0, 0.45 * med_r)
    return circles[keep]


def nearest_chain(seats: np.ndarray, used: np.ndarray, start: int, max_step: float) -> list[int]:
    """Ряд = жадная цепочка от start до тех пор, пока шаг ≤ max_step."""
    chain = [start]
    used[start] = True

    while True:
        tail = seats[chain[-1]]
        best, best_d = -1, max_step
        for i in range(len(seats)):
            if used[i]:
                continue
            d = math.hypot(seats[i][0] - tail[0], seats[i][1] - tail[1])
            if d < best_d:
                best, best_d = i, d
        if best < 0:
            return chain
        chain.append(best)
        used[best] = True


def split_chains(chain: list[int], seats: np.ndarray, sector_gap: float) -> list[list[int]]:
    """Разрезает цепочку на секторы по большому горизонтальному зазору."""
    if len(chain) < 2:
        return [chain]
    out: list[list[int]] = [[chain[0]]]
    for prev, cur in zip(chain, chain[1:]):
        if abs(seats[cur][0] - seats[prev][0]) > sector_gap:
            out.append([cur])
        else:
            out[-1].append(cur)
    return out


def build_rows(seats: np.ndarray, r_med: float, sector_gap: float,
               stage: str) -> list[dict]:
    """Все ряды всех секторов: [{'cx','cy','seats':[idx по порядку]}]."""
    n = len(seats)
    used = np.zeros(n, dtype=bool)
    rows: list[list[int]] = []
    max_step = 2.6 * r_med

    # Начинаём с левых точек: так цепочки идут вдоль ряда, а не поперёк.
    order = sorted(range(n), key=lambda i: (seats[i][1], seats[i][0]))
    for i in order:
        if used[i]:
            continue
        for part in split_chains(nearest_chain(seats, used, i, max_step), seats, sector_gap):
            if len(part) >= 2:
                rows.append(part)

    # Сцена: ближний к ней ряд получает номер 1 (сортировка по y цепочки).
    rows.sort(key=lambda idxs: float(np.mean([seats[i][1] for i in idxs])),
              reverse=(stage == 'bottom'))
    return rows


def to_editor_schema(rows: list[dict], seats: np.ndarray, canvas_w: int,
                     canvas_h: int) -> dict:
    """Формат конструктора: sectors[].seats[].{row,x,y} + канвас.

    Ряды одного сектора объединяются в один сектор: места — по
    исходным координатам screenshot (масштаб 1:1), чтобы дуги сохранились.
    """
    SEAT = 16  # константа конструктора (seatGeometry.ts)
    sectors: list[dict] = []
    sector_rows: list[list[dict]] = []

    def row_width_gap(idxs: list[int]) -> float:
        xs = sorted(seats[i][0] for i in idxs)
        gaps = [b - a for a, b in zip(xs, xs[1:])]
        return float(np.median(gaps)) if gaps else 0.0

    # Секторы: ряды, у которых нет прямого вертикального продолжения
    # (первым рядом newer sector служит ряд, сильно удалённый от
    # предыдущего). Разделение: вертикальный зазор > 3.2 * высота ряда.
    cur: list[list[int]] = []
    prev_bottom = None
    vertical_gap_split = 3.2 * 2.0  # уточняется после расчёта средних шагов ниже

    # Оценка междурядья по всем парам соседних рядов.
    centers = [float(np.mean([seats[i][1] for i in idxs])) for idxs in rows]
    if len(centers) > 1:
        gaps = sorted(b - a for a, b in zip(centers, centers[1:]))
        vertical_gap_split = max(3.2, gaps[len(gaps) // 2] * 2.6)

    for idxs in rows:
        top = float(np.mean([seats[i][1] for i in idxs]))
        if cur and top - prev_bottom > vertical_gap_split:
            sector_rows.append(cur)
            cur = []
        cur.append(idxs)
        prev_bottom = top
    if cur:
        sector_rows.append(cur)

    seat_id = 0
    for s_idx, sector in enumerate(sector_rows, start=1):
        payload_seats = []
        for row_no, idxs in enumerate(sector, start=1):
            ordered = sorted(idxs, key=lambda i: seats[i][0])
            for x, y in seats[ordered][:, :2]:
                seat_id += 1
                payload_seats.append({
                    'id': f'seat-{seat_id}',
                    'row': row_no,
                    'number': len(payload_seats) + 1,
                    'x': int(round(float(x) - SEAT / 2)),
                    'y': int(round(float(y) - SEAT / 2)),
                })
        xs = [s['x'] for s in payload_seats]
        ys = [s['y'] for s in payload_seats]
        sectors.append({
            'id': f'sector-{s_idx}',
            'name': f'Сектор {s_idx}',
            'x': min(xs) if xs else 0,
            'y': min(ys) if ys else 0,
            'priceMinor': 0,
            'type': 'seated',
            'shape': 'grid',
            'seats': payload_seats,
        })

    return {
        'version': 1,
        'canvas': {'width': canvas_w, 'height': canvas_h},
        'background': None,
        'backgrounds': [],
        'sectors': sectors,
        'staticObjects': [],
    }


def draw_overlay(image: np.ndarray, rows: list[dict], seats: np.ndarray,
                 out_path: str) -> None:
    """Контрольная картинка: места обведены, у ряда слева — его номер."""
    overlay = image.copy()
    for row_no, idxs in enumerate(rows, start=1):
        color = (0, 200, 90) if row_no % 2 else (60, 170, 255)
        for i in idxs:
            x, y, r = (int(v) for v in seats[i])
            cv2.circle(overlay, (x, y), r, color, 2)
        left = min(idxs, key=lambda i: seats[i][0])
        x, y, r = (int(v) for v in seats[left])
        cv2.putText(overlay, str(row_no), (max(2, x - 34), y + 4),
                    cv2.FONT_HERSHEY_SIMPLEX, 0.5, (0, 0, 0), 3)
        cv2.putText(overlay, str(row_no), (max(2, x - 34), y + 4),
                    cv2.FONT_HERSHEY_SIMPLEX, 0.5, (255, 255, 255), 1)
    cv2.imwrite(out_path, overlay)


def main() -> int:
    ap = argparse.ArgumentParser(description='Схема зала со скриншота → JSON конструктора')
    ap.add_argument('image')
    ap.add_argument('--out', default='schema.json')
    ap.add_argument('--overlay', default=None)
    ap.add_argument('--stage', choices=['top', 'bottom'], default='top',
                    help='где сцена: номер 1 получает ближний к сцене ряд')
    ap.add_argument('--min-r', type=int, default=5)
    ap.add_argument('--max-r', type=int, default=22)
    ap.add_argument('--sector-gap', type=float, default=140.0)
    args = ap.parse_args()

    image = cv2.imread(args.image)
    if image is None:
        print(f'Не удалось прочитать изображение: {args.image}', file=sys.stderr)
        return 1

    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    circles = filter_outliers(detect_circles(gray, args.min_r, args.max_r))
    if len(circles) == 0:
        print('Места не найдены: подберите --min-r/--max-r (радиус места в px).', file=sys.stderr)
        return 2

    r_med = float(np.median(circles[:, 2]))
    rows = build_rows(circles, r_med, args.sector_gap, args.stage)
    h, w = image.shape[:2]
    schema = to_editor_schema(rows, circles, w, h)

    out = os.path.abspath(args.out)
    with open(out, 'w', encoding='utf-8') as fh:
        json.dump(schema, fh, ensure_ascii=False, indent=1)

    overlay_path = os.path.abspath(args.overlay or
                                   os.path.splitext(out)[0] + '-overlay.png')
    draw_overlay(image, rows, circles, overlay_path)

    total = sum(len(s['seats']) for s in schema['sectors'])
    print(f'рядов: {len(rows)}, мест: {total}, секторов: {len(schema["sectors"])}')
    print(f'schema:   {out}')
    print(f'overlay:  {overlay_path}  — сверьте глазами места и ряды')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
