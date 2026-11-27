# Подключение внешнего ИИ-агента (MCP)

## Адрес и аутентификация

- Endpoint: `POST https://ваш-домен/mcp` (Streamable HTTP, одного сервера —
  второго параллельного MCP нет).
- Аутентификация: `Authorization: Bearer <токен>` (Sanctum).
- Минимальное полномочие: `agent:read`. Write-способности выдаются узко:
  `agent:events:write`, `agent:schemas:publish`, `agent:refunds:write`,
  `agent:actions:confirm`.
- Без токена → 401; не-сотрудник или токен без `agent:read` → 403 (включая
  `tools/list`). Лимит — 60 вызовов/мин на токен.

Токен выпускается в админке «ИИ-агент» (plaintext показывается один раз) или
командой `php artisan nabilet:agent-token <email> [--list|--rotate|--revoke-all]`.
Отзыв действует немедленно, но не «отматывает» уже скопированные агентом данные.

## Инструменты

Базовые (9): `sales_summary`, `search_events`, `get_event`, `search_orders`,
`get_order`, `create_event`, `publish_schema`, `refund`, `confirm_agent_action`.

Диагностические (16, read-only): `get_system_status`,
`get_system_requirements`, `get_cron_status`, `get_queue_status`,
`get_recent_errors`, `get_failed_jobs`, `get_database_status`,
`get_module_status`, `get_integration_status`, `get_backup_status`,
`get_update_status`, `get_application_version`, `get_api_capabilities`,
`get_installation_report`, `run_readonly_selfcheck`, `get_route_status`.

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

Первый вызов — `initialize`, затем `tools/list`: должно быть 25 инструментов.
Быстрая проверка работоспособности — `run_readonly_selfcheck`, затем
`get_installation_report`. Агент не должен обещать, что изменение применено,
пока `confirm_agent_action` не вернул результат.

## Админка

Раздел «ИИ-агент»: выпуск/перевыпуск/отзыв токена, ability, список ожидающих
подтверждения действий и журнал. Отдельной страницы «адрес + перечень
инструментов» пока нет — адрес и состав инструментов фиксируются этим
документом и `get_api_capabilities` (см. KNOWN-LIMITATIONS).
