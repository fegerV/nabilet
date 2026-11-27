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

Один раз сгенерировать пару ключей (вне репозитория):

```bash
php scripts/generate-update-keys.php --out-dir=/secure/path
```

- `update-private.pem` → секрет GitHub `NABILET_UPDATE_PRIVATE_KEY_PEM`
  (никогда не коммитить и не класть в архив).
- `update-public.pem` → `NABILET_UPDATE_PUBLIC_KEY` на сайте (окружение хоста).

Без ключа `signature` пустая: ручная загрузка ZIP по SHA-256 работает, лента
обновлений отвергает манифест (`UPDATE_SIGNATURE_MISSING`). Релизы **по тегу
`v*` теперь не публикуются без подписи**; для ручного `workflow_dispatch`
доступен `require_signature=true`. При утечке ключа сгенерируйте новую пару и
переподпишите актуальный релиз.

## 4. Приёмка

1. Распаковать в отдельный каталог сайта, прогнать `docs/TIMEWEB-ACCEPTANCE.md`.
2. Проверить smoke из `docs/deploy-timeweb.md` или CI-job `smoke`.
3. Сверить `/api/v1/health` с версией релиза и `GET /install` → 404.
4. Обновление предыдущего релиза → актуальный; проверить сохранность данных.
5. Только после подписания акта — боевые ключи и открытие продаж.

Публикация: GitHub Release по тегу (artifact загружается всегда) + `manifest.json`.
