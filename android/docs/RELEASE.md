# Сборка, подпись и выдача

## Локальная сборка

```bash
cd android

./gradlew testDebugUnitTest   # JVM-тесты домена, эмулятор не нужен
./gradlew lintDebug           # отчёт: app/build/reports/lint-results-debug.html
./gradlew assembleDebug       # app/build/outputs/apk/debug/app-debug.apk
./gradlew assembleRelease     # без секретов — неподписанный APK (проверка R8)
```

Debug-вариант подписан стандартным debug-ключом и разрешает `http://` — это
единственный вариант, где допустим локальный сервер без TLS. В release
`usesCleartextTraffic=false` и `ALLOW_CLEARTEXT=false`: адрес с `http://`
отклоняется ещё при вводе.

## Подпись релиза

Четыре переменные окружения включают подпись. Если хотя бы одной нет,
`assembleRelease` собирает неподписанный APK и не падает — так CI без секретов
может проверять компиляцию.

| Переменная | Содержимое |
| --- | --- |
| `NABILET_KEYSTORE_PATH` | путь к `.jks` на машине сборки |
| `NABILET_KEYSTORE_PASSWORD` | пароль хранилища |
| `NABILET_KEY_ALIAS` | алиас ключа |
| `NABILET_KEY_PASSWORD` | пароль ключа |

Создание хранилища (один раз, хранить в менеджере секретов, не в git):

```bash
keytool -genkeypair -v \
  -keystore nabilet-checker.jks \
  -alias nabilet-checker \
  -keyalg RSA -keysize 4096 -validity 10000 \
  -storetype JKS
```

Версия задаётся свойствами Gradle, а не правкой файла:

```bash
./gradlew assembleRelease -PappVersionCode=12 -PappVersionName=1.2.0
```

По умолчанию `versionCode=1`, `versionName=1.0.0`.

## GitHub Actions

### `.github/workflows/android.yml`

На каждый push и pull request, затрагивающий `android/**`:

1. `testDebugUnitTest` — доменные тесты;
2. `lintDebug` — отчёт загружается артефактом, сборку не валит
   (`abortOnError = false`: lint на другой версии SDK не должен блокировать
   выпуск APK, но его отчёт нужен);
3. `assembleDebug` и `assembleRelease` — второй проверяет, что R8 не сломает
   релизный вариант.

Артефакты: `nabilet-checker-debug` (APK), `nabilet-checker-lint` (HTML-отчёт),
`nabilet-checker-tests` (отчёты JUnit).

### `.github/workflows/android-release.yml`

Запускается тегом `checker-v*` (например `checker-v1.2.0`) или вручную
(`workflow_dispatch`, версия вводится строкой). Что делает:

1. Декодирует keystore из секрета `NABILET_KEYSTORE_BASE64` в `$RUNNER_TEMP`.
2. Экспортирует четыре переменные подписи.
3. Собирает `assembleRelease` с `versionCode` из номера запуска и `versionName`
   из тега.
4. Проверяет, что APK действительно подписан (`apksigner verify`).
5. Публикует GitHub Release с APK и `mapping.txt` (для разбора стектрейсов R8).
6. Удаляет keystore в `if: always()`.

Секреты репозитория:

| Секрет | Как получить |
| --- | --- |
| `NABILET_KEYSTORE_BASE64` | `base64 -w0 nabilet-checker.jks` (Windows: `[Convert]::ToBase64String([IO.File]::ReadAllBytes("nabilet-checker.jks"))`) |
| `NABILET_KEYSTORE_PASSWORD` | пароль хранилища |
| `NABILET_KEY_ALIAS` | алиас ключа |
| `NABILET_KEY_PASSWORD` | пароль ключа |

Сменить ключ после публикации нельзя: Android не даст обновить приложение,
подписанное другим ключом. Потеря keystore = потеря возможности обновлять
приложение.

## Чек-лист перед выдачей устройства

1. В админке создано устройство проверяющего, токен `nbck_…` скопирован.
2. Устройство активно и привязано к событию; бандл собран (не «пустой»).
3. На телефоне установлен release APK, дата и время идут автоматически
   (иначе истечение бандла и `scanned_at` будут врать).
4. Введён адрес сервера, токен проверен (`Сохранить и проверить` → «токен
   принят»).
5. Скачан бандл, на экране видны событие, сеанс, число билетов и срок действия.
6. Проведён тестовый скан: валидный билет → зелёная карточка; повторный скан
   того же билета → «уже использован».
7. Проверен офлайн: авиарежим → скан → карточка появляется, счётчик
   «не отправлено» растёт; сеть возвращается → очередь уходит в ноль.
8. Экран статистики показывает те же числа, что и после перезапуска
   приложения.
9. Телефон заряжен, камера протёрта, звук включён (вердикт дублируется звуком и
   вибрацией).

## Известные ограничения выпуска

- Подпись бандла не проверяется офлайн (см. [`DESIGN.md`](DESIGN.md), раздел 4).
- Список бандлов устройству недоступен: ID вводится вручную (см.
  [`API.md`](API.md), раздел 5).
- `minSdk 26`: на более старых телефонах приложение не установится.
- Краш-репортинга нет: разбор проблем идёт по `adb logcat` и локальному журналу
  сканов.
