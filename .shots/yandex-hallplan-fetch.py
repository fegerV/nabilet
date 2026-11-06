"""
Загрузка плана зала из публичного API виджета Яндекс Билетов.

Цепочка (выяснена перехватом сети настоящего браузера):
  1) GET /api/tickets/v1/sessions/<внешний ключ сеанса> → внутренний session.key;
  2) GET /api/tickets/v4/sessions/<key>/hallplan → {status: running|completed, url};
     план готовится на сервере, поэтому опрашиваем до completed;
  3) GET <url> → готовый JSON со всеми уровнями и местами.

Внешний ключ — `<префикс>@<event id>`: префикс и clid виджета лежат в открытом
runtimeConfig сайта кассы (yandexWidgetSessionPrefix / yandexWidgetClid).

Запуск: python .shots/yandex-hallplan-fetch.py <event_id> [файл результата]
"""
import json
import sys
import time
import urllib.request
from urllib.parse import quote

CLID = '12071c6f-75d0-42e7-9900-54a347efb482'
PREFIX = 'ticketsteam-669'
BASE = 'https://widget.afisha.yandex.ru/api/tickets'


def get(url: str, timeout: int = 30) -> dict:
    req = urllib.request.Request(url, headers={
        'Accept': 'application/json',
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                      '(KHTML, like Gecko) Chrome/126.0 Safari/537.36',
    })
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return json.loads(resp.read().decode('utf-8'))


def fetch(event_id: str) -> dict:
    ext = quote(f'{PREFIX}@{event_id}', safe='')
    session = get(f'{BASE}/v1/sessions/{ext}?clientKey={CLID}')
    key = session['result']['session']['key']

    for attempt in range(30):
        plan = get(f'{BASE}/v4/sessions/{key}/hallplan?clientKey={CLID}', timeout=25)
        status = plan.get('status')
        if status == 'completed' and plan.get('url'):
            return get(plan['url'], timeout=60)
        if status not in ('running', 'pending'):
            raise RuntimeError(f'unexpected hallplan status: {status}')
        time.sleep(1)
    raise RuntimeError('hallplan не собрался за 30 попыток')


if __name__ == '__main__':
    event = sys.argv[1]
    out = sys.argv[2] if len(sys.argv) > 2 else f'.shots/yandex-hallplan-{event}.json'
    data = fetch(event)
    with open(out, 'w', encoding='utf-8') as fh:
        json.dump(data, fh, ensure_ascii=False)
    hall = data['result']['hallplan']
    print(f'ok: {out}')
    print(f"saleStatus={data['result']['saleStatus']} мест={hall['totalSeatCount']} "
          f"свободно={hall['availableSeatCount']}")
    for level in hall['levels']:
        print(f"  {level['name']}: мест в выдаче {len(level['seats'])}, "
              f"рядов {len(level['rows'])}, всего по рядам "
              f"{sum(r['seatCount'] for r in level['rows'])}")
