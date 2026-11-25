# NABILET — отчёт аудита конструктора залов и смежных модулей

Дата: 2026-11-26. Ветка: `fix/hall-editor-audit`. Исходная версия: `main` @ `d435ab57`.

## 1. Что сделано

Проведён аудит цепочки «конструктор → сохранение → валидация → публикация → сеанс → инвентарь → витрина → бронирование → билет» и смежных модулей. Исправления внесены последовательными коммитами, каждое — с регрессионными тестами.

| Этап | Результат |
|---|---|
| Атомарность сеанса и инвентаря | сеанс и генерация в одной транзакции, локи строк, откат при ошибке, истёкшие холды не блокируют, живые — блокируют |
| Изоляция организаций | черновики/архив схем доступны только сотруднику владеющей организации; список сеансов и «все залы» scoped |
| `halls` → `venues` | запись переведена на реальную таблицу, VIEW пересоздана с потерянными полями, восстановлен DB-барьер удаления |
| Стоячие зоны | стабильные id, вместимость без `array_fill`, геометрия доходит до витрины, серверный лимит |
| Валидация | версия формата, типы/id объектов, лимиты, подложки, стабильный код ошибки и id объекта |
| Версионирование | `base_revision` обязателен при публикации; запоздавшие ответы API не перезаписывают редактор |
| Безопасность репозитория | токены диагностических стендов и старые сборки убраны из индекса |

Подробности — `BUG-REGISTER.md`, `SECURITY-REPORT.md`, `MIGRATION-REPORT.md`.

## 2. Верификация (фактические прогоны)

Команды выполнялись на машине разработки; Feature-тесты — только на disposable БД `nabilet_test_suite` (см. `phpunit.xml`), не на рабочей базе.

| Проверка | Результат |
|---|---|
| `php -d memory_limit=1G vendor/bin/phpunit` | **1469 тестов / 6396 утверждений, 0 падений** |
| `php -d memory_limit=1G tests/run.php` | 1091 метод / 2795 утверждений, 0 падений |
| `npm run test -- --run` | 56 файлов / 599 тестов, 0 падений |
| `npm run typecheck` | успешно |
| `npm run build` | успешно, `dist/` собран |
| `vendor/bin/phpstan analyse --no-progress` | `No errors` |
| `php tools/verify-migrations.php` | 16 проверок, 0 падений |
| `php tools/verify-models-schema.php` | PASS, новых расхождений нет |

Базовый прогон до изменений: PHPUnit 1461 тест, Vitest 593 теста. Прирост объясняется новыми регрессионными тестами.

## 3. Границы доказательности

- Браузерный E2E (Playwright) в этом проходе **не запускался**: в проекте нет готовой Playwright-инфраструктуры, а требование «не добавлять зависимости без необходимости» означает, что её внедрение — отдельная задача. Сценарии из раздела 12 ТЗ частично покрыты Feature/Vitest, но не считаются браузерно подтверждёнными.
- Замеры производительности 500/2000/5000/10000/20000 мест **не проводились**. `PERFORMANCE-REPORT.md` фиксирует отсутствие данных, а не выдуманные показатели.
- Полный рефакторинг `HallEditorPage.vue` (4130 строк) не выполнен: выделен один composable, остальная декомпозиция описана в `HALL-EDITOR-ARCHITECTURE.md` и `REMAINING-ISSUES.md`.
- Импорт/экспорт имеет тесты round-trip в Vitest и PHP, но не автоматический HTTP-round-trip на реальных фикстурах залов.
- Описанные в `SECURITY-REPORT.md` риски (platform-admin fallback, публичные байты медиа, перечисление организаций) требуют решения владельца продукта: они меняют публичные контракты/бизнес-правила.

## 4. Коммиты ветки

```
096ceaae fix(sessions): encapsulate session + inventory in one transaction
58601bf6 fix(tenancy): close cross-organization schema and session reads
b7c785f2 fix(venues): finish the halls to venues view migration
390a9d7c fix(schema): make standing zones first-class and harden validation
3eb67931 fix(storefront): render standing zones at their designed coordinates
79045e3b chore(security): stop tracking local debug tokens and stale builds
01365bba chore(ci): keep PHPStan green after audit fixes
e6f6a04a docs(audit): add findings, contract and honest status reports
```

Слияние с `main` не выполнялось — требуется согласование.
