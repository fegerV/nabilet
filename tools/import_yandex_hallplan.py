"""
Импорт schemes Яндекс.Афиши (тонкая обёртка над PHP-конвертером).

Раньше этот файл содержал СОБСТВЕННЫЙ упрощённый конвертер: нормализация в
60×40, жёсткий шаг 14 px, деление по категориям, никакой реконструкции и
никаких source_id. Его результат расходился с PHP-конвертером и regex-дальше
не проходил валидатор (числа мест строками). Теперь единственный источник
алгоритма — `tools/hallplan-to-schema.php` → `YandexHallplanConverter`.

Использование:
    python tools/import_yandex_hallplan.py in.bin out.json

`in.bin` может быть gzip-ответом hallplan, plain JSON или полным envelope
`{status, result:{hallplan:...}}`.
"""
import gzip
import json
import os
import subprocess
import sys
import tempfile


def load(path: str) -> dict:
    with open(path, 'rb') as handle:
        raw = handle.read()
    try:
        return json.loads(gzip.decompress(raw))
    except Exception:
        return json.loads(raw)


def main() -> int:
    if len(sys.argv) < 3:
        print(__doc__)
        return 1
    src, dst = sys.argv[1], sys.argv[2]

    data = load(src)
    if not isinstance(data, dict):
        raise ValueError('Ожидается JSON-объект hallplan')
    payload = data if 'hallplan' in data.get('result', {}) else {'result': {'hallplan': data}}

    root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    converter = os.path.join(root, 'tools', 'hallplan-to-schema.php')
    if not os.path.isfile(converter):
        raise FileNotFoundError(converter)

    tmp_path = None
    try:
        with tempfile.NamedTemporaryFile('w', suffix='.json', delete=False, encoding='utf-8') as tmp:
            json.dump(payload, tmp, ensure_ascii=False)
            tmp_path = tmp.name
        subprocess.run(['php', converter, tmp_path, '--out', dst], check=True)
    finally:
        if tmp_path and os.path.isfile(tmp_path):
            os.unlink(tmp_path)

    with open(dst, 'r', encoding='utf-8') as handle:
        schema = json.load(handle)
    report = schema.get('import', {})
    print(f"OK: секторов={report.get('result_sectors')}, "
          f"известных мест={report.get('known_seats')}, "
          f"восстановлено={report.get('reconstructed_seats')}, файл={dst}")
    for warning in report.get('warnings', []):
        print('ВНИМАНИЕ: ' + warning)
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except Exception as error:
        print(f'ОШИБКА: {error}')
        sys.exit(2)
