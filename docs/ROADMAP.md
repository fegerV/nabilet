# NABILET Roadmap

> Актуальный production-аудит: [`PRODUCTION-READINESS.md`](PRODUCTION-READINESS.md).
> Исторические заметки и первоначальный этап плана ниже — не источник текущих
> незакрытых задач; актуальные статусы собраны в конце документа.

## Дерево проекта (текущие компоненты)

```
C:\Project\nabilet
├─ app/Modules/            ← Laravel API и предметные модули
├─ resources/js/           ← Vue 3 + Vite + Pinia
│  ├─ lib/                 ← api.ts, inventory.ts, hall.ts, metrika.ts, tickets.ts
│  ├─ components/ui/       ← UI-кит
│  ├─ components/seat/     ← SeatMap, SeatLegend, OrderSummary
│  ├─ pages/storefront/    ← витрина и покупка
│  ├─ pages/admin/         ← админка, включая email-шаблоны и webhook-подписки
│  └─ router/              ← hash-режим
├─ dist/                   ← активный Vite build (outDir=dist)
└─ docs/
   ├─ ROADMAP.md
   ├─ PRODUCTION-READINESS.md
   └─ openapi.yaml
```

## Реализовано

- Публичный каталог и покупка работают через API; SEO-страницы по slug и JSON-LD.
- Холд, checkout, оплата YooKassa, выпуск билетов и check-in — сквозной контур.
- Hall editor: импорт схем, редактирование мест и цены, сведения о площадке.
- Админские редакторы транзакционных email-шаблонов и исходящих webhook-подписок.
- P0-код доставки почты и вебхуков реализован; шаблоны по статусам заказа;
  вебхуки подписаны HMAC-SHA256 и защищены от SSRF.
- Удалены неиспользуемый `lib/mock.ts` и демо HTML/ассеты.

## Оставшиеся релизные задачи

Сверяться с `PRODUCTION-READINESS.md`; главное перед production:

1. Заполнить SMTP в `.env`, применить queue-миграцию, поставить минутный cron
   `php artisan schedule:run`, проверить получение email на реальный ящик.
2. Тестировать HMAC и повторную доставку на endpoint партнёра в тестовом режиме.
3. Закрыть Auth reset/verify email заглушки, оценить оставшиеся placeholder-разделы.
4. Добавить PHPUnit/Vitest/Vite build в CI; закончить production smoke на целевом хостинге.

## История

Ниже оставлен первоначальный план миграции админки и реализации этапов. Отмеченные
в нём старые пункты не считать актуальными задачами, если их статус не отражён
в разделе «Оставшиеся релизные задачи» выше.

---

