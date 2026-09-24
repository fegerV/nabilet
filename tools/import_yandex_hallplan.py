"""
Импортёр схемы зала из Яндекс.Афиши (widget hallplan JSON) в наш формат.

Как работает:
1. Открыть в браузере виджет: https://widget.afisha.yandex.ru/w/sessions/<KEY>?clientKey=...
2. В DevTools (Сеть) скопировать URL запроса:
   https://cdnr4lqyuk6qqjhirf4n.svc.cdn.yandex.net/hallplans/<HASH>
   (это gzip+JSON; или запрос hallplan?req_number=N — тоже JSON)
3. Сохранить в файл (raw) и запустить:
   python tools/import_yandex_hallplan.py in.bin out.json [--hall HALL]

Формат источника (Яндекс):
  result.hallplan.levels[].seats[]:
    { seat: { xCoord, yCoord, row?, place?, linkedSeats }, categoryId, priceInfo:{total:{value}} }
  result.hallplan.categories[]: { id, name, priceInfos[] }

Формат цели (наш schema_json):
  { name, width, height, sectors: [{ name, code, type, x, y, width, height,
      rows: [{ number, label, price_amount, seats: [{ number, label, type, x, y }] }] }] }
"""
import gzip, json, sys, math

def load(path: str) -> dict:
    raw = open(path, 'rb').read()
    # пытаемся распаковать gzip, иначе — plain json
    try:
        return json.loads(gzip.decompress(raw))
    except Exception:
        return json.loads(raw)

def norm_coords(seats, W=60, H=40, pad=1):
    """Нормализуем пиксельные координаты Яндекса в сетку WxH (с отступом)."""
    xs = [s['seat']['xCoord'] for s in seats]
    ys = [s['seat']['yCoord'] for s in seats]
    minx, maxx = min(xs), max(xs)
    miny, maxy = min(ys), max(ys)
    rx = (maxx - minx) or 1
    ry = (maxy - miny) or 1
    def to_grid(v, lo, rng, size, pad):
        return pad + round((v - lo) / rng * (size - 2 * pad))
    out = []
    for s in seats:
        seat = s['seat']
        out.append({
            'x': to_grid(seat['xCoord'], minx, rx, W, pad),
            'y': to_grid(seat['yCoord'], miny, ry, H, pad),
        })
    return out

def convert(data: dict, hall_name: str) -> dict:
    hp = data['result']['hallplan']
    cats = {c['id']: c for c in hp['categories']}
    sectors = []
    seat_id = 1  # наши места в inventory получают id из БД; здесь — локальный

    for lv in hp['levels']:
        seats = lv['seats']
        # Если есть structured rows (зал с рядами вроде Ледового, 1770 мест) —
        # разворачиваем их в места (координаты ряда + seatCount мест по линии).
        structured_rows = lv.get('rows', []) if isinstance(lv.get('rows'), list) else []
        if structured_rows and len(seats) <= len(structured_rows):
            expanded = []
            for r in structured_rows:
                count = r.get('seatCount', 0)
                (rx, ry) = r.get('xCoord', 0), r.get('yCoord', 0)
                # цена ряда: ищем в lv['categories'][i]['prices']? нет — цена места из lv['prices'] (по индексу)
                price = 0
                # Категория ряда: берём категорию уровня (это dict {id, name,...} — извлекаем id)
                cat_obj = lv.get('categories', [None])[0]
                cat_id = cat_obj.get('id') if isinstance(cat_obj, dict) else cat_obj
                # Разложим места по линии X: центр ряда ± шаг (чтобы не лежали друг на друге)
                step = 14
                x0 = rx - step * (count - 1) / 2
                for i in range(count):
                    expanded.append({
                        'seat': {'row': r.get('row', str(i + 1)), 'place': str(i + 1),
                                 'xCoord': round(x0 + i * step), 'yCoord': ry, 'linkedSeats': []},
                        'categoryId': cat_id,
                        'priceInfo': {'price': {'value': price}, 'total': {'value': price}},
                    })
            # Возьмём цену из lv['prices'] (список {currencyCode, value})
            lv_prices = lv.get('prices') or []
            if lv_prices:
                p0 = lv_prices[0]
                price0 = p0.get('value', 0) if isinstance(p0, dict) else 0
                for s in expanded:
                    s['priceInfo']['price']['value'] = price0
                    s['priceInfo']['total']['value'] = price0
            seats = expanded
        if not seats:
            continue
        # Определяем категорию (первый seat — обычно источник, но лучше по преобладающей)
        from collections import Counter
        cat_counter = Counter(s['categoryId'] for s in seats)
        cat_id = cat_counter.most_common(1)[0][0]
        cat = cats.get(cat_id, {'name': lv['name'] or 'Сектор'})
        cat_name = cat['name']

        # Есть ли ряды?
        has_rows = any('row' in s['seat'] and s['seat']['row'] for s in seats)
        if has_rows:
            # Группируем по рядам
            rows_map = {}
            for s in seats:
                seat = s['seat']
                row = seat['row']
                rows_map.setdefault(row, []).append(s)
            rows = []
            for row, rseats in sorted(rows_map.items(), key=lambda kv: int(kv[0]) if str(kv[0]).isdigit() else kv[0]):
                # Цена ряда = цена места (все места ряда одной цены у Яндекса)
                price_amount = rseats[0].get('priceInfo', {}).get('price', {}).get('value', 0)
                ns = norm_coords(rseats)
                seats_out = []
                for i, s in enumerate(rseats):
                    seat = s['seat']
                    seats_out.append({
                        'number': seat.get('place', str(i + 1)),
                        'label': f"Ряд {row} Место {seat.get('place', i+1)}",
                        'type': 'regular',
                        'x': ns[i]['x'],
                        'y': ns[i]['y'],
                    })
                rows.append({'number': row, 'label': f'Ряд {row}', 'price_amount': price_amount, 'seats': seats_out})
            sectors.append({
                'name': cat_name,
                'code': f"{cat_name[:1]}{len(sectors)+1}",
                'type': 'seated',
                'x': 0, 'y': 0,
                'width': 60, 'height': 40,
                'rows': rows,
            })
        else:
            # Точки (танцпол, стоячие): один «ряд» с координатами
            ns = norm_coords(seats)
            seats_out = []
            for i, s in enumerate(seats):
                seats_out.append({
                    'number': str(i + 1),
                    'label': f"{cat_name} {i+1}",
                    'type': 'regular',
                    'x': ns[i]['x'],
                    'y': ns[i]['y'],
                })
            # Цена стоячего «ряда»: из первого места (у танцпола цена едина)
            price_amount = seats[0].get('priceInfo', {}).get('price', {}).get('value', 0)
            sectors.append({
                'name': cat_name,
                'code': f"{cat_name[:1]}{len(sectors)+1}",
                'type': 'standing',  # танцпол — стоячие
                'x': 0, 'y': 0,
                'width': 60, 'height': 40,
                'rows': [{'number': '1', 'label': cat_name, 'price_amount': price_amount, 'seats': seats_out}],
            })

    return {
        'name': hall_name,
        'width': 60,
        'height': 40,
        'sectors': sectors,
    }

if __name__ == '__main__':
    if len(sys.argv) < 3:
        print(__doc__)
        sys.exit(1)
    src, dst = sys.argv[1], sys.argv[2]
    hall = sys.argv[3] if len(sys.argv) > 3 else 'Зал (импорт из Яндекс.Афиши)'
    try:
        data = load(src)
        out = convert(data, hall)
        with open(dst, 'w', encoding='utf-8') as f:
            json.dump(out, f, ensure_ascii=False, indent=2)
        n_seats = sum(len(r['seats']) for s in out['sectors'] for r in s['rows'])
        print(f"OK: секторов={len(out['sectors'])}, мест={n_seats}, файл={dst}")
        print("Сектора:")
        for s in out['sectors']:
            print(f"  {s['name']!r} ({s['type']}): {sum(len(r['seats']) for r in s['rows'])} мест, цена={s['rows'][0]['price_amount']}")
    except Exception as e:
        print(f"ОШИБКА: {e}")
        sys.exit(2)