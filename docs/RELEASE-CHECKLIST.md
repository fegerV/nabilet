# Чек-лист выпуска релиза

## 1. Подготовка

1. Обновить версию в `config/nabilet.php` и `package.json` (SemVer).
2. Добавить датированный раздел `## [X.Y.Z] - YYYY-MM-DD` в `CHANGELOG.md`.
3. Проверить: `php tools/verify-release-version.php --version=X.Y.Z`.
4. Убедиться, что все обязательные PR'ы смержены в один коммит и CI зелёный
   для него же (`.github/workflows/ci.yml`).

## 2. Сборка

Локально (Windows или Linux — один и тот же сборщик):

```bash
npm ci
npm run typecheck
npm run test
npm run build
php tools/verify-release-version.php --version=X.Y.Z
php scripts/build-hosting-zip.php --version=X.Y.Z --output-dir=releases
```

В CI: `.github/workflows/release-hosting.yml` по тегу `vX.Y.Z` или вручную
(`workflow_dispatch`). Он сам прогоняет `ci.yml` через `workflow_call`, удаляет
`dist/`, пересобирает SPA, ставит prod-зависимости по lock, собирает ZIP,
SHA-256 и manifest, проверяет архив распаковкой и чистой установкой.

Обязательные условия:
- версии config/package/CHANGELOG совпадают;
- `dist/` собран заново, не взят из прошлой сборки;
- в архиве нет `.env`, логов, бэкапов, runtime `storage/`, dev-пакетов,
  `tests/`, `tools/`, `docs/`, `.git`;
- `public_html/index.php` есть, `index.php` в корне отсутствует;
- manifest содержит `version`, `commit_sha`, `released_at`, `sha256`.

## 3. Подпись (обязательна для автоматических обновлений)

Секрет `NABILET_UPDATE_PRIVATE_KEY_PEM` (PEM, `\n` допустимы как литералы).
Без него `signature` пустая: ручная загрузка ZIP по SHA-256 работает,
лента обновлений отвергает манифест (`UPDATE_SIGNATURE_MISSING`).
Для strict-режима: `workflow_dispatch` с `require_signature=true`.

## 4. Приёмка

1. Распаковать в отдельный каталог сайта, прогнать `docs/TIMEWEB-ACCEPTANCE.md`.
2. Проверить smoke из `docs/deploy-timeweb.md` или CI-job `smoke`.
3. Сверить `/api/v1/health` с версией релиза и `GET /install` → 404.
4. Обновление предыдущего релиза → актуальный; проверить сохранность данных.
5. Только после подписания акта — боевые ключи и открытие продаж.

Публикация: GitHub Release по тегу (artifact загружается всегда) + `manifest.json`.
