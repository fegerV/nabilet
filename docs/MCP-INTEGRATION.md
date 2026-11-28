# Подключение внешнего ИИ-агента (MCP)

## Адрес и аутентификация

- Endpoint: `POST https://ваш-домен/mcp` (Streamable HTTP, одного сервера —
  второго параллельного MCP нет).
- Аутентификация: `Authorization: Bearer <токен>` (Sanctum).
- После чистой установки владелец автоматически попадает в
  `NABILET_PLATFORM_ADMINS`; администраторы организаций права системного
  администратора не наследуют. Глобальная диагностика дополнительно требует
  `agent:system:read`.
- Полномочия назначаются ЯВНО (раньше `agent:read` добавлялся молча):
  - `agent:read` — бизнес-данные своей организации;
  - `agent:system:read` — глобальная системная диагностика, только
    системный администратор (`NABILET_PLATFORM_ADMINS`);
  - `agent:content:read` — чтение контента своей организации;
  - `agent:content:write` — подготовка изменений контента;
  - `agent:events:write`, `agent:schemas:publish`, `agent:refunds:write`,
    `agent:actions:confirm` — специализированные write-полномочия.
- Инструменты без полномочия **скрываются из `tools/list`**; вызов через
  `tools/call` возвращает «Tool not found». Без токена → 401, не-сотрудник или
  токен без любого agent-полномочия → 403. Лимит — 60 вызовов/мин на токен.
- Системные журналы, очередь, бэкапы, пути сервера и глобальные настройки
  недоступны обычному сотруднику организации. Контакты покупателя в
  `get_order` частично маскируются.

Токен выпускается в админке «ИИ-агент» (plaintext показывается один раз) или
командой `php artisan nabilet:agent-token <email> [--abilities=…] [--list|--rotate|--revoke-all]`.
Отзыв действует немедленно, но не «отматывает» уже скопированные агентом данные.

## Инструменты

Базовые (9): `sales_summary`, `search_events`, `get_event`, `search_orders`,
`get_order`, `create_event`, `publish_schema`, `refund`, `confirm_agent_action`.

Контентные и валидаторы (13, read-only): `search_pages`, `get_page`,
`search_media`, `get_site_settings`, `get_theme_settings`, `get_seo_report`,
`search_venues`, `search_halls`, `get_hall_schema`, `validate_hall_schema`,
`validate_event`, `validate_ticket_template`, `validate_payment_configuration`.
`search_pages` → `get_page`, `search_halls` → `get_hall_schema` — последовательные
шаги. Настройки возвращаются с замаскированными ключами; валидаторы не пишут
в БД и используют те же правила, что редактор залов и домен билета.

Диагностические (16, read-only): `get_system_status`,
`get_system_requirements`, `get_cron_status`, `get_queue_status`,
`get_recent_errors`, `get_failed_jobs`, `get_database_status`,
`get_module_status`, `get_integration_status`, `get_backup_status`,
`get_update_status`, `get_application_version`, `get_api_capabilities`,
`get_installation_report`, `run_readonly_selfcheck`, `get_route_status`.

Подготовка контента (двухфазная, требуется `agent:content:write`):
`prepare_create_page`, `prepare_update_page`, `prepare_update_theme`,
`prepare_update_seo`, `prepare_update_event`. Страницы создаются только
черновиками, тема объединяется с существующим конфигом (посторонние ключи не
трогаются), SEO и мероприятия проверяются на принадлежность организации токена.

Write-инструменты двухфазные: подготовка создаёт неизменяемый снимок в
`agent_actions`, человек одобряет его в админке, `confirm_agent_action`
исполняет ровно снимок. Повторный confirm идемпотентен, срок — 30 минут.

## Границы безопасности

| Запрещено | Как обеспечено |
|---|---|
| Произвольный SQL, PHP, shell, `.env`, запись файлов | таких инструментов нет; реестр tools фиксирован в `NabiletAgentServer` |
| Платежные ключи, пароли, QR-секреты | `AgentDiagnostics` возвращает только факты «настроено/не настроено» |
| Персональные данные | скоуп organizations владельца токена; логи проходят `RedactSensitiveData`; payload/exception failed jobs и стектейсы не выдаются |
| Подмена одобренного действия | confirm исполняет сохранённый снимок, а не новые аргументы |
| Расширение прав по пути | отдельные ability, проверка на маршруте и внутри write-инструментов |
| Скрытая запись при «чтении» | все диагностические tools помечены `#[IsReadOnly]` + `#[IsIdempotent]`, покрыты тестом |

## Проверка подключения

```json
{
  "mcp": {
    "nabilet": {
      "type": "remote",
      "url": "https://ваш-домен/mcp",
      "headers": { "Authorization": "Bearer {env:NABILET_AGENT_TOKEN}" },
      "enabled": true
    }
  }
}
```

Первый вызов — `initialize`, затем `tools/list`: должно быть 43 инструмента
(9 базовых + 16 диагностических + 13 контентных + 5 подготовок контента).
Быстрая проверка работоспособности — `run_readonly_selfcheck`, затем
`get_installation_report`. Агент не должен обещать, что изменение применено,
пока `confirm_agent_action` не вернул результат.

## Админка

Раздел «ИИ-агент»: выпуск/перевыпуск/отзыв токена, ability, список ожидающих
подтверждения действий и журнал. Добавлена панель **«Подключение MCP»**: адрес
сервера, транспорт, заголовок авторизации, готовый конфиг клиента, фактический
перечень всех инструментов из реестра сервера (с пометкой read-only) и проверка
состояния — зарегистрирован ли маршрут `/mcp` и есть ли активный токен с
`agent:read`. Данные приходят с `GET /api/v1/admin/agent/connection` под обычной
админской сессией; токены и секреты в ответе отсутствуют.
