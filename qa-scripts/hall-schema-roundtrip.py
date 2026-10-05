"""Сквозная проверка конструктора схем залов (включая «стол с местами» и type round-trip).

Шаги:
  1. логин админа;
  2. создание зала;
  3. черновик в редакторском формате (сидячий сектор + стол-кольцо + стоячий);
  4. round-trip: type не теряется ни при сохранении, ни при автосейве (ФИКС);
  5. публикация;
  6. создание сеанса → генерация инвентаря → стол даёт продаваемые места ('seat');
  7. импорт битого файла через validateSchema (логика клиента) — отклоняется.

Запуск: python qa-scripts/hall-schema-roundtrip.py
"""
import json
import sys
import time
import urllib.error
import urllib.request

BASE = "http://127.0.0.1:8000/api/v1"


def call(method, path, body=None, token=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(BASE + path, data=data, method=method)
    req.add_header("Accept", "application/json")
    if data:
        req.add_header("Content-Type", "application/json")
    if token:
        req.add_header("Authorization", "Bearer " + token)
    try:
        with urllib.request.urlopen(req) as r:
            return r.status, json.loads(r.read().decode() or "null")
    except urllib.error.HTTPError as e:
        raw = e.read().decode()
        try:
            return e.code, json.loads(raw)
        except json.JSONDecodeError:
            return e.code, raw


def main():
    ok = True

    st, res = call("POST", "/auth/login", {"email": "admin@nabilet.local", "password": "admin123"})
    token = res["data"]["token"]

    st, res = call("GET", "/venues?limit=1")
    inner = res["data"]
    venue = (inner["data"] if isinstance(inner, dict) else inner)[0]
    venue_id = venue["id"]

    st, res = call("POST", "/halls", {"venue_id": venue_id, "name": f"QA table+type {int(time.time())}"}, token)
    print("3b. hall create ->", st, str(res)[:200])
    hall = res.get("data", res)
    hall_pid = hall["public_id"]

    # Кольцо мест стола (shape='table'), как формирует createTableAt().
    def ring(count, cx0=58):
        seats = []
        import math
        for i in range(count):
            a = -math.pi / 2 + (i * 2 * math.pi) / count
            seats.append({
                "id": f"t{i}", "row": 1, "number": i + 1, "kind": "standard",
                "x": round(cx0 + 48 * math.cos(a) - 8),
                "y": round(cx0 + 48 * math.sin(a) - 8),
            })
        return seats

    payload = {
        "version": "1.0",
        "canvas": {"width": 900, "height": 520},
        "sectors": [
            {
                "id": "s1", "name": "Партер", "x": 80, "y": 100,
                "priceMinor": 150000, "type": "seated", "shape": "grid",
                "seats": [
                    {"id": f"a{i}", "row": 1 + i // 3, "number": i % 3 + 1, "kind": "standard",
                     "x": (i % 3) * 22, "y": (i // 3) * 28} for i in range(6)],
            },
            {
                "id": "s2", "name": "Стол 1", "x": 400, "y": 300,
                "priceMinor": 120000, "type": "seated", "shape": "table",
                "seats": ring(8),
            },
            {
                "id": "s3", "name": "Танцпол", "x": 600, "y": 100,
                "priceMinor": 80000, "type": "standing", "shape": "grid",
                "seats": [{"id": f"d{i}", "row": 1, "number": i + 1, "kind": "standard",
                           "x": 0, "y": 0} for i in range(20)],
            },
        ],
        "staticObjects": [{"id": "st1", "kind": "stage", "x": 300, "y": 20, "width": 260, "height": 34, "text": "СЦЕНА"}],
    }

    st, res = call("POST", f"/halls/{hall_pid}/schema-versions/draft", {"payload": payload}, token)
    ver = res["data"] if "data" in res else res
    draft_id = ver["id"]

    st, res = call("GET", f"/halls/{hall_pid}/schema-versions", token=token)
    draft = [v for v in res["data"] if v["status"] == "draft"][0]
    stored = draft["schema"]
    if isinstance(stored, str):
        stored = json.loads(stored)
    types = {s["name"]: s.get("type") for s in stored["sectors"]}
    shapes = {s["name"]: s.get("shape") for s in stored["sectors"]}
    print(f"4. saved types   -> {types}")
    print(f"   saved shapes  -> {shapes}")
    if types.get("Танцпол") != "standing" or types.get("Стол 1") != "seated":
        print("   !! FAIL type"); ok = False
    if shapes.get("Стол 1") != "table":
        print("   !! FAIL shape table"); ok = False

    # Симуляция ФИКСА: автосейв теперь шлёт `type`. Проверяем, что type не теряется.
    fixed = json.loads(json.dumps(payload))  # клиент теперь включает type
    st, res = call("POST", f"/halls/{hall_pid}/schema-versions/draft", {"payload": fixed, "version_id": draft_id}, token)
    st2, res2 = call("GET", f"/halls/{hall_pid}/schema-versions", token=token)
    d2 = [v for v in res2["data"] if v["id"] == draft_id][0]
    s2 = d2["schema"]
    if isinstance(s2, str):
        s2 = json.loads(s2)
    t2 = {s["name"]: s.get("type") for s in s2["sectors"]}
    print(f"5. after autosave-> {t2}")
    if t2.get("Танцпол") != "standing":
        print("   !! ДЕФЕКТ: type потерялся при автосейве"); ok = False

    st, res = call("POST", f"/halls/{hall_pid}/schema-versions/{draft_id}/publish", {}, token)
    pub = (res.get("data") or {})
    pub_id = pub.get("id")
    print(f"6. publish        -> {st} id={pub_id} status={pub.get('status')}")
    if st not in (200, 201) or pub.get("status") != "published":
        ok = False

    # Сеанс -> генерация инвентаря. Стол должен дать 8 продаваемых мест.
    st, res = call("POST", "/sessions", {
        "event_id": 2, "hall_id": hall["id"], "schema_version_id": pub_id,
        "starts_at": "2026-12-01T19:00:00", "status": "on_sale",
    }, token)
    session = (res.get("data") or {})
    sid = session.get("id")
    print(f"7. session        -> {st} id={sid}")
    if st not in (200, 201) or not sid:
        ok = False

    st, res = call("GET", f"/inventory?session_id={sid}&per_page=500")
    raw = res.get("data")
    items = raw if isinstance(raw, list) else (raw.get("data", []) if isinstance(raw, dict) else [])
    types_inv = {}
    cap_inv = {}
    for it in items:
        t = it.get("type")
        types_inv[t] = types_inv.get(t, 0) + 1
        cap_inv[t] = cap_inv.get(t, 0) + int(it.get("capacity") or it.get("seats_count") or 0)
    print(f"8. inventory      -> {len(items)} items: {types_inv} (capacity: {cap_inv})")
    if types_inv.get("seat", 0) < 14:
        print("   !! FAIL: мало seat-позиций (Партер 6 + Стол 1 8 = 14)"); ok = False
    # Стоячая зона (Танцпол) — один sellable-элемент с capacity, а не 20 отдельных мест.
    if types_inv.get("standing", 0) != 1:
        print("   !! FAIL: стоячая зона должна дать ровно 1 standing-позицию"); ok = False
    if cap_inv.get("standing", 0) < 20:
        print("   !! FAIL: capacity стоячей зоны < 20"); ok = False

    # 9. validateSchema (логика клиента): битый файл отклоняется.
    bad = [
        ("пустой JSON-объект", {}),
        ("без секторов", {"canvas": {"width": 900}}),
        (" sector без имени", {"sectors": [{"seats": []}]}),
        ("сектор без мест", {"sectors": [{"name": "X"}]}),
    ]
    print("9. validateSchema:")
    for label, data in bad:
        errs = validate_schema(data)
        print(f"   {label:22} -> {'ОТКЛОНЁН (' + str(len(errs)) + ')' if errs else 'OK (пропущен!)'}")
        if not errs:
            ok = False

    print("\nИТОГ:", "OK" if ok else "ЕСТЬ ДЕФЕКТЫ")
    return 0 if ok else 1


def validate_schema(data):
    """Копия логики validateSchema() из HallEditorPage.vue для офлайн-проверки."""
    errors = []
    if data is None or not isinstance(data, dict) or isinstance(data, list):
        return ["не объект"]
    root = data.get("schema") if isinstance(data.get("schema"), dict) else data
    if "sectors" not in root and "staticObjects" not in root and "background" not in root:
        return ["нет sectors/staticObjects/background"]
    sectors = root.get("sectors")
    if sectors is not None and not isinstance(sectors, list):
        return ["sectors не массив"]
    lst = sectors if isinstance(sectors, list) else []
    if not lst and "staticObjects" not in root and "background" not in root:
        return ["пусто"]
    for i, raw in enumerate(lst):
        if not isinstance(raw, dict):
            errors.append(f"sectors[{i}] не объект"); continue
        if not isinstance(raw.get("name"), str) or raw.get("name", "").strip() == "":
            errors.append(f"sectors[{i}] нет имени")
        has_seats = isinstance(raw.get("seats"), list) and len(raw["seats"]) > 0
        has_rows = isinstance(raw.get("rows"), list) and len(raw["rows"]) > 0
        if not has_seats and not has_rows:
            errors.append(f"sectors[{i}] нет мест")
    return errors


if __name__ == "__main__":
    sys.exit(main())
