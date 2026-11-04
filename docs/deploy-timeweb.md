# Деплой NABILET Core на шаред-хостинг TimeWeb

Целевой стек (заявлен TimeWeb на момент написания):

| Компонент | Ограничение | Влияние на проект |
|-----------|-------------|-------------------|
| PHP       | **8.3** — максимальная доступная версия | Код уже целится в `^8.3`, переделок не требует |
| ionCube   | присутствует на хосте | **Не нужен** — в репозитории нет ионкод-файлов |
| Python    | 3.4 (устаревший) | **Не используется** — приложение чисто PHP/Laravel |

> Вывод: «переработка под стек» сводится к **деплой-адаптации под шаред-хостинг**
> (фиксированный докумень `public_html/`, отсутствие composer/npm на сервере),
> а не к даунгрейду кода. Сам код PHP 8.3-совместим из коробки.

---

## 1. Модель размещения

Докумень (DocumentRoot) на TimeWeb жёстко зафиксирован как `public_html/` внутри
**каталога сайта** — у каждого домена он свой, например
`/home/c/ce99429/vertex-art.ru/`. Приложение кладётся **в каталог сайта**, т.е.
на уровень выше `public_html/` (это НЕ «корень аккаунта» `/home/c/ce99429/` и НЕ
сам `public_html/`):

```
/home/c/ce99429/vertex-art.ru/     ← каталог сайта = корень приложения
├── app/
├── bootstrap/
├── config/
├── database/
├── vendor/                        (ставится composer install на сервере)
├── dist/                          (SPA-витрина: dist/index.html + dist/assets)
├── .env                           (создаётся на сервере, НЕ в git)
├── storage/                       (симлинк из public_html/storage)
├── public/                        (канонический фронт-контроллер Laravel)
└── public_html/                   ← DocumentRoot (то, что видно по HTTP)
    ├── index.php                  ← НОВЫЙ тонкий вход (требует ../public/index.php)
    ├── .htaccess                  ← НОВЫЙ rewrite на index.php
    ├── .user.ini                  ← лимиты PHP (обязан лежать ВНУТРИ DocumentRoot)
    └── build/                     (скомпилированный фронтенд, отслеживается в git)
```

Если в аккаунте несколько сайтов — у каждого свой каталог-домен, и приложение
каждого лежит рядом со своим `public_html/`. В `public_html/` допустимы только
статика и тонкий `index.php`; `.env`, `vendor/`, `storage/`, `app/` там оказаться
не должны.

Чувствительные каталоги (`app/`, `config/`, `.env`, `vendor/`, `storage/`) лежат
**вне** DocumentRoot → недоступны по HTTP. Это правильная изоляция; менять её не надо.

В репозитории `public_html/build/` **намеренно отслеживается** (см. `.gitignore`):
на шаред-хостинге часто нет npm, поэтому собранные ассеты приезжают вместе с кодом
через `git pull`.

---

## 2. Что добавлено в репозиторий

| Файл | Назначение |
|------|------------|
| `public_html/index.php` | Тонкий вход: `require ../public/index.php`. Единый источник правды — канонический `public/index.php` Laravel. |
| `public_html/.htaccess` | Стандартный rewrite Laravel на `index.php` + блокировка dot-файлов (`Require all denied`, Apache 2.4). |
| `public_html/.user.ini` | PHP 8.3-настройки (выкл. `display_errors`, `expose_php=Off`, лимиты загрузки, secure session cookies). Лежит **внутри** DocumentRoot — иначе PHP его не прочитает (скан останавливается на DOCUMENT_ROOT). |

`.user.ini` применяется к web-запросам из `public_html/` и ниже — поэтому он и
лежит в `public_html/` (копия выше DocumentRoot не читается PHP вовсе; web-доступ
к файлу закрыт правилом `<FilesMatch "^\.">` в `.htaccess`). На CLI (`artisan`)
он **не** действует — при необходимости продублируйте лимиты в окружении cron/ssh.

---

## 3. Проверка PHP 8.3-совместимости (pre-flight)

Код объявляет `"php": "^8.3"` в `composer.json`. Дополнительно проверено отсутствие
PHP 8.4-специфичного синтаксиса:

- property hooks (`private(set)`, `public/get`) — **0** вхождений в `app/`;
- asymmetric visibility — **0** вхождений.

На сервере финально подтвердите после `composer install`:

```bash
composer check-platform-reqs
php -v            # ожидаем 8.3.x
```

---

## 4. Установка через мастер (WordPress-стиль, рекомендуемый путь)

После выкладки кода открываем в браузере `https://ваш-домен/install` — пошаговый
мастер сам напишет `.env`, прогонит миграции, создаст администратора и его
организацию, слинкует `storage`. Это основной сценарий деплоя на шаред-хостинг.

```bash
# 0. В панели TimeWeb выбрать PHP 8.3, затем по SSH:
php -v

# 1. Выложить код в каталог сайта — тот, что СОДЕРЖИТ public_html
#    (напр. /home/c/ce99429/vertex-art.ru/), не в public_html и не в /home/c/ce99429:
git -C /home/c/ce99429/vertex-art.ru pull origin main

# 2. Зависимости (vendor/ в git НЕ входит — ставим на сервере; мастер его не ставит):
cd /home/c/ce99429/vertex-art.ru
composer install --no-dev --optimize-autoloader

# 3. Права (мастер проверит сам, но заранее лучше открыть):
chmod -R ug+w storage bootstrap/cache

# 4. Открыть в браузере:  https://ваш-домен/install
```

Шаги мастера:

1. **Требования** — PHP ≥ 8.3 (мастер требует именно 8.3: `composer.json` объявляет
   `"php": "^8.3"` — на 8.1/8.2 Composer не поставит зависимости, а мастер
   свалился бы позже на первом же классе с новым синтаксисом; проверено
   smoke-прогоном 2026-11-04), расширения (pdo/mbstring/openssl/json/xml/curl/zip),
   права на запись, отсутствие `.env`. Если что-то красное — поправить на сервере.
2. **База данных** — хост/порт/имя/пользователь/пароль. **На TimeWeb хост БД — НЕ
   `localhost`** (берётся в панели управления, напр. `mysql.timeweb.ru`), порт обычно
   `3306`. Там же — необязательные ЮKassa Shop ID и секретный ключ.
3. **Администратор** — email и пароль (мин. 8 символов).
4. **Прогресс** — миграции → админ → storage → финализация → «Установка завершена».

Мастер выполняет под капотом именно то, что раньше делалось вручную, и закрывает
ряд ловушек шаред-хостинга (все — проверены e2e-прогоном на чистой копии без
`.env`, 2026-11-04):

- пишет `.env` со сгенерированным `APP_KEY`; в шаблон также входят
  `DB_CHARSET=utf8mb4` / `DB_COLLATION=utf8mb4_unicode_ci` (без них MySQL 8 падал
  `Unknown collation: ''` на первом шаге `migrate`), `CACHE_STORE=database` с
  флагом `NABILET_ALLOW_DATABASE_CACHE=true` (разрешение сторожа прод-конфига);
- **гарантирует рабочий `APP_KEY` до записи `.env`** (`PreinstallAppKey`): ключ
  генерируется на буте и персистится в `storage/`, иначе `GET /install` отвечал
  500 `MissingAppKeyException` — форма, которая этот ключ и создаёт, была
  недостижима;
- **включает файловые драйверы сессий/кеша до записи `.env`**
  (`PreinstallDrivers`): дефолты Laravel — `database`, а хост БД на этом шаге ещё
  неизвестен, каждый запрос падал `SQLSTATE[HY000] [2002]`; каталоги
  `storage/framework/{sessions,cache/data}` создаются явно;
- **подменяет in-memory-конфиг БД перед `migrate`** (включая charset/collation) —
  иначе миграции уходили на boot-значения (`127.0.0.1`/`forge`) и падали;
- снимает лимит `max_execution_time` на время установки (`set_time_limit(0)`):
  bcrypt-сидеры на шаред-хостинге не влезают в дефолтные 30 секунд HTTP-запроса;
- создаёт пользователя-админа **и его организацию** (приложение multi-tenant: без
  организации tenant-scoped запросы падают с `TenantContextMissingError`);
- `storage:link` и `db:seed` запускаются в fail-soft режиме;
- таблицы `cache`/`cache_locks` создаёт миграция `2026_09_19_000000_000_cache` —
  throttle на `POST /install` и кеш работают на дефолтном `CACHE_STORE=database`
  сразу после установки.

После успеха создаётся `storage/install.lock` — повторный заход на `/install`
и повторный `POST` блокируются (403 `ALREADY_INSTALLED`).

### Ограничения и нюансы (важно)

- **Триггеры БД могут не создаться на шаред-хостинге.** Миграции
  `2026_09_20_001100_*` и `2026_10_06_000100_*` создают MySQL-триггеры через
  `CREATE TRIGGER`; на шаред-хостинге это часто падает с `ERROR 1419` (binary
  logging + нет прав SUPER). Мастер **не прерывается** — триггеры опциональны
  (defense-in-depth), те же правила защищены на уровне приложения.
- **`composer install` обязателен при git-выкладке** — `vendor/` не коммитится.
  Альтернатива — готовый архив установки (собирается командой релиза,
  см. `PRODUCTION-READINESS.md` §3 от 2026-11-04): в нём `vendor/` и собранная
  витрина `dist/` уже внутри, `composer install` и `npm run build` на хостинге
  не нужны; каталоги `tests/`, `tools/`, `docs/`, `.git` в архив не входят.
  Внутри архив — папка `nabilet/`; её содержимое раскладывается в **каталог
  сайта** (родитель `public_html/`), при этом `nabilet/public_html/*` сливается
  с существующим `public_html/` (дефолтную заглушку `index.html` удалить).
  Пример для `/home/c/ce99429/vertex-art.ru/`:

  ```bash
  cd /home/c/ce99429
  unzip -q nabilet-hosting-v1.0.0-20261104.zip          # -> ./nabilet/
  rm -f vertex-art.ru/public_html/index.html     # заглушка хостинга, если есть
  cp -a nabilet/. vertex-art.ru/                 # app/ vendor/ dist/ public_html/ ... -> каталог сайта
  rm -rf nabilet
  # проверка: public_html/index.php и ../app на месте, публичного .env нет
  chmod -R ug+w vertex-art.ru/storage vertex-art.ru/bootstrap/cache
  ```
- **ЮKassa опциональна** — поля можно оставить пустыми; платежи заработают после
  заполнения в настройках.
- **CSRF на `/install` отключён** (`bootstrap/app.php` →
  `validateCsrfTokens(except: ['install','install/*'])`): это одноразовый pre-auth
  эндпоинт, доступный только до `install.lock`.
- **Кеши.** Мастер их не прогревает. Для прод-режима после установки:
  ```bash
  cd /home/c/ce99429/vertex-art.ru
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
  ```

### Ручной fallback (если мастер недоступен)

```bash
cd /home/c/ce99429/vertex-art.ru
cp .env.example .env
#   отредактировать .env: APP_URL, DB_*, YOOKASSA_*, MAIL_*, redis (predis)
php artisan key:generate
php artisan migrate --force
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

После этого `https://ваш-домен/` отдаёт Laravel через `public_html/index.php`.

---

## 5. Фронтенд (активная сборка)

- `vite.config.ts` — активная конфигурация приложения: `npm run build` создаёт
  `dist/index.html` и `dist/assets/*`. Laravel отдаёт этот SPA-файл из `routes/web.php`
  и SEO-роутов; после изменения Vue-кода именно `dist/` должен попасть в релиз.
- `vite.config.js` — legacy-конфигурация для отдельного набора ассетов в
  `public_html/build/`; она не является источником активной страницы SPA. Не
  запускайте её вместо `vite.config.ts` для production-сборки.
- Демо-страницы `ticket-builder.html` и `hall-editor.html` вместе с их копиями и
  ассетами удалены; отдельно публиковать их больше не нужно.

```bash
npm ci
npm run build
```

---

## 6. Очередь и расписание (обязательно для писем и вебхуков)

Транзакционные письма и исходящие вебхуки ставятся в database queue. После
развёртывания примените миграции, включая `jobs` и `failed_jobs`:

```bash
php artisan migrate --force
```

Настройте `.env` — значения ниже взять у вашего SMTP-провайдера; реальные
пароли не хранить в git:

```dotenv
QUEUE_CONNECTION=database
MAIL_MAILER=smtp
MAIL_HOST=<SMTP-хост>
MAIL_PORT=587
MAIL_USERNAME=<SMTP-пользователь>
MAIL_PASSWORD=<SMTP-пароль>
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=<подтверждённый адрес отправителя>
MAIL_FROM_NAME="${APP_NAME}"
```

Планировщик запускает worker очереди порциями (50 секунд каждую минуту) и
поднимает отложенные повторы вебхуков. Установите cron в панели TimeWeb:

```cron
* * * * * cd /home/c/ce99429/vertex-art.ru && php artisan schedule:run >> /dev/null 2>&1
```

При первом развёртывании (и после добавления новых шаблонов) создайте системные
шаблоны писем; существующие тексты сидер не перезаписывает:

```bash
php artisan db:seed --class='Database\\Seeders\\NotificationTemplateSeeder' --force
```

После изменения `.env` очистите/пересоберите config cache, затем проверьте
`php artisan schedule:list` и выполните тест-покупку на реальный SMTP-ящик.

### Каталог площадок Сургута

Мастер установки запускает `db:seed --force`, а `DatabaseSeeder` первым делом
вызывает `SurgutVenuesSeeder` — 39 площадок Сургута (филармония, ДИ «Нефтяник»,
СурГУ, клубы и залы) уже заведены, отдельная команда не нужна.

Это только строка `venues`: ни залов, ни схем, ни событий. Публичные страницы
`/venue/{slug}` и `/sitemap-venues.xml` появляются позже — когда в площадке
заведено хотя бы одно опубликованное событие (пустая страница отвечает 404, и
адрес в карте сайта, ведущий на 404, — ошибка для поисковика).

Повторный запуск безопасен и ничего не затирает: ключ — `slug`, а уже
существующие площадки пропускаются, поэтому правки описаний и адресов,
сделанные в админке, сохраняются. Запустить вручную (например, чтобы вернуть
удалённую по ошибке площадку):

```bash
php artisan db:seed --class='Database\\Seeders\\SurgutVenuesSeeder' --force
```

Площадки ложатся в организацию администратора; если в базе их несколько и нужна
конкретная — задайте `NABILET_SEED_VENUES_ORGANIZATION_ID=<id организации>`.

---

---

## 7. Резервное копирование и восстановление (G-1, аудит готовности)

Команда `backup:run` уже в расписании (`routes/console.php`: ежедневно 03:00,
`withoutOverlapping`): полный дамп БД через `mysqldump --defaults-extra-file`
(пароль не светится в `ps`) + загрузка дампа на Яндекс.Диск.

**Перед первым запуском:** в `.env` должны быть `YANDEX_DISK_OAUTH_TOKEN=...`
(OAuth-токен диска) — без него команда падает громко, а не «морчит про успех».

**Алерты самопроверки:** команда `system:selfcheck` идёт каждые 10 минут; при
изменении состояния (БД/Redis/очередь, заполнение диска, отсутствие или
протухший бэкап) сигнал пишется в канал `alerts` (`storage/logs/alerts-*.log`,
он же — вкладка «Логи» в админке), а при заданной переменной
`NABILET_ALERT_EMAIL=ops@example.com` дублируется письмом — синхронно, мимо
очереди (алерт «очередь сломана» должен доходить, когда сломана именно
очередь). Тот же состав проблем повторно не отправляется — только появление,
эскалация/смена состава и восстановление; повторить вручную —
`php artisan system:selfcheck --force`.

Разовый дамп вручную (например, перед рискованным обновлением):

```bash
php artisan backup:run
```

**Восстановление** (чистая БД на хостинге; данные в яндексе — файл вида
`nabilet-YYYY-MM-DD.sql.gz`):

```bash
# 1) скачать последний дамп с Яндекса (любой WebDAV-клиент или curl с OAuth-токеном)
curl -H "Authorization: OAuth $YANDEX_DISK_OAUTH_TOKEN" \
  "https://cloud-api.yandex.net/v1/disk/resources/download?path=disk%3A/<путь>/<файл>.sql.gz"

# 2) распаковать и накатить в пустую базу
gunzip -c nabilet.sql.gz | mysql -h<host> -u<user> -p <dbname>
```

**Сверка после восстановления** (что сравнивать с источником):

```sql
SELECT COUNT(*) FROM orders;
SELECT COUNT(*) FROM tickets;
SELECT status, COUNT(*) FROM payments GROUP BY status;
SELECT code, used_count FROM promo_codes WHERE used_count > 0;
```

Чего дамп НЕ содержит: `.env` (секреты — храните их отдельно) и содержимое
`storage/app/public` (медиатека — она восстанавливается отдельным копированием
каталога). Каталог медиатеки регулярно копируйте рядом с дампом: дамп без
файлов афиши — половина бэкапа.

**Проверка свежести** должна быть в мониторинге: файл дампа на диске не старше
26 часов, иначе алерт (аудит P0-1).

## 8. Проверка после деплоя

- `https://домен/` открывается (не 500, не листинг каталога).
- `https://домен/build/assets/*.js` отдаётся как статикa (200).
- `https://домен/.env` → **403/404** (dot-файлы заблокированы `.htaccess`).
- `php artisan --version` на сервере показывает Laravel 13.x на PHP 8.3.x.

---

## 9. Версионирование релизов и обновление системы

### Версионирование

- Текущая версия приложения: **1.0.0** (`config/nabilet.php` → `'version'`,
  отдаётся в `/api/v1/health` и `SystemStatusController`).
- Git-теги релизов: **SemVer** — `vX.Y.Z` (пример: `v1.0.0` на коммите `ac145ab2`).
- `CHANGELOG.md` (Keep a Changelog + SemVer): `## [X.Y.Z] - YYYY-MM-DD`.
- Правила: `fix:` → PATCH (1.0.0 → 1.0.1), `feat:` → MINOR (→ 1.1.0), breaking → MAJOR
  (→ 2.0.0).

### Процесс релиза

1. bump версии (`config/nabilet.php`, `package.json`);
2. `CHANGELOG.md` += новый релизный блок;
3. commit (`fix:`/`feat:`/`docs(release):`) → тег `vX.Y.Z` → push (`git push origin main vX.Y.Z`);
4. пересборка архива: `npm run build` + деплой-сборка → `nabilet-hosting-vX.Y.Z-YYYYMMDD.zip`;
5. e2e на архиве → удаление старого zip → обновление имени в этом документе.

### Обновление установленной системы

- Через архив (рекомендуется): распаковка в каталог сайта, `cp -a nabilet/. …`,
  `chmod -R ug+w storage bootstrap/cache`. Мастер при `storage/install.lock` —
  откажет `403 ALREADY_INSTALLED` — это норма.
- Через git: `git pull origin main` + `composer install --no-dev --optimize-autoloader`.
- После обновления: `php artisan migrate --force`; при изменении `.env` —
  `php artisan config:cache && php artisan route:cache && php artisan view:cache`;
  cron `schedule:run`; проверка `/api/v1/health` → 200, `/` → 200, повторный `/install`
  → 403 `ALREADY_INSTALLED`.
