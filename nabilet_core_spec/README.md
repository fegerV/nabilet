# NABILET Core v1 — developer specification bundle

Состав:

- `NABILET_Core_Technical_Spec_v1.md` — основная техническая спецификация.
- `migrations.sql` — MySQL 8.4+ DDL/migrations для v1.
- `openapi.yaml` — API contract OpenAPI 3.1 для публичного/admin/checker/embed API.
- `state-diagrams.md` — state machines для Hold/Order/Payment/Ticket/Check-in.

Рекомендуемый стек:

- PHP 8.3+
- Laravel 13
- Vue 3 + TypeScript + Vite
- MySQL 8.4 LTS
- Redis optional/production recommended
- Konva.js для Hall Editor
- Kotlin + Jetpack Compose + CameraX + Room для Android Checker

Источники для актуальных интеграционных ограничений:

- Laravel release/support: https://laravel.com/framework/docs/releases
- YooKassa API: https://yookassa.ru/developers/api
- YooKassa webhooks: https://yookassa.ru/developers/using-api/webhooks
- YooKassa OpenAPI: https://yookassa.ru/developers/using-api/openapi-specification
- Telegram Mini Apps: https://core.telegram.org/bots/webapps
