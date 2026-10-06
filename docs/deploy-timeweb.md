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

На TimeWeb докумень (DocumentRoot) жёстко зафиксирован как `public_html/`
в корне аккаунта. Приложение кладётся **на уровень выше**:

```
/home/uXXXXX/                      ← корень аккаунта = корень репозитория
├── app/
├── bootstrap/
├── config/
├── database/
├── vendor/                        (ставится composer install на сервере)
├── .env                           (создаётся на сервере, НЕ в git)
├── storage/                       (симлинк из public_html/storage)
├── public/                        (канонический фронт-контроллер Laravel)
└── public_html/                   ← DocumentRoot (то, что видно по HTTP)
    ├── index.php                  ← НОВЫЙ тонкий вход (требует ../public/index.php)
    ├── .htaccess                  ← НОВЫЙ rewrite на index.php
    ├── hall-editor.html           (стендэлоун-страница)
    ├── ticket-builder.html        (стендэлоун-страница)
    └── build/                     (скомпилированный фронтенд, отслеживается в git)
```

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
| `.user.ini` | PHP 8.3-настройки (выкл. `display_errors`, `expose_php=Off`, лимиты загрузки, secure session cookies). |

`.user.ini` применяется к web-запросам из `public_html/` и ниже. На CLI (`artisan`)
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

# 1. Выложить код в корень аккаунта так, чтобы public_html/ стал DocumentRoot:
git -C /home/uXXXXX pull origin main

# 2. Зависимости (vendor/ в git НЕ входит — ставим на сервере; мастер его не ставит):
cd /home/uXXXXX
composer install --no-dev --optimize-autoloader

# 3. Права (мастер проверит сам, но заранее лучше открыть):
chmod -R ug+w storage bootstrap/cache

# 4. Открыть в браузере:  https://ваш-домен/install
```

Шаги мастера:

1. **Требования** — PHP ≥ 8.1, расширения (pdo/mbstring/openssl/json/xml/curl/zip),
   права на запись, отсутствие `.env`. Если что-то красное — поправить на сервере.
2. **База данных** — хост/порт/имя/пользователь/пароль. **На TimeWeb хост БД — НЕ
   `localhost`** (берётся в панели управления, напр. `mysql.timeweb.ru`), порт обычно
   `3306`. Там же — необязательные ЮKassa Shop ID и секретный ключ.
3. **Администратор** — email и пароль (мин. 8 символов).
4. **Прогресс** — миграции → админ → storage → финализация → «Установка завершена».

Мастер выполняет под капотом именно то, что раньше делалось вручную, и закрывает
ряд ловушек шаред-хостинга:

- пишет `.env` со сгенерированным `APP_KEY`;
- **подменяет in-memory-конфиг БД перед `migrate`** — иначе миграции уходили на
  boot-значения (`127.0.0.1`/`forge`) и падали;
- создаёт пользователя-админа **и его организацию** (приложение multi-tenant: без
  организации tenant-scoped запросы падают с `TenantContextMissingError`);
- `storage:link` и `db:seed` запускаются в fail-soft режиме.

После успеха создаётся `storage/install.lock` — повторный заход на `/install`
блокируется (403).

### Ограничения и нюансы (важно)

- **Триггеры БД могут не создаться на шаред-хостинге.** Миграции
  `2026_09_20_001100_*` и `2026_10_06_000100_*` создают MySQL-триггеры через
  `CREATE TRIGGER`; на шаред-хостинге это часто падает с `ERROR 1419` (binary
  logging + нет прав SUPER). Мастер **не прерывается** — триггеры опциональны
  (defense-in-depth), те же правила защищены на уровне приложения.
- **`composer install` обязателен** — `vendor/` не коммитится.
- **ЮKassa опциональна** — поля можно оставить пустыми; платежи заработают после
  заполнения в настройках.
- **CSRF на `/install` отключён** (`bootstrap/app.php` →
  `validateCsrfTokens(except: ['install','install/*'])`): это одноразовый pre-auth
  эндпоинт, доступный только до `install.lock`.
- **Кеши.** Мастер их не прогревает. Для прод-режима после установки:
  ```bash
  cd /home/uXXXXX
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
  ```

### Ручной fallback (если мастер недоступен)

```bash
cd /home/uXXXXX
cp .env.example .env
#   отредактировать .env: APP_URL, DB_*, YOOKASSA_*, MAIL_*, redis (predis)
php artisan key:generate
php artisan migrate --force
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

После этого `https://ваш-домен/` отдаёт Laravel через `public_html/index.php`.

---

## 5. Фронтенд (замечание по сборке)

В репозитории **две** vite-конфигурации:

- `vite.config.ts` (активная) — выводит в `dist/` (локальная разработка / SPA).
- `vite.config.js` (legacy) — выводит в `public_html/build/` с `manifest: true`;
  именно её выхлоп и отслеживается в git для шаред-хостинга.

Чтобы обновить ассеты на сервере без npm на хосте, соберите локально и закоммитьте:

```bash
npm ci
npx vite build --config vite.config.js     # → public_html/build/{assets,*.html}
git add public_html/build && git commit && git push
```

`public_html/build/index.php` — вестigial-копия `public/index.php`, оставшаяся от
сборки; не используется маршрутизацией (root ведёт на `public_html/index.php`).
Можно удалить при следующей чистке, на работу не влияет.

---

## 6. Очереди / фон (опционально)

Если используются очереди/расписание, на шаред-хостинге они ставятся через
cron в панели TimeWeb, например:

```cron
* * * * * cd /home/uXXXXX && php artisan schedule:run >> /dev/null 2>&1
```

---

## 7. Проверка после деплоя

- `https://домен/` открывается (не 500, не листинг каталога).
- `https://домен/build/assets/*.js` отдаётся как статикa (200).
- `https://домен/.env` → **403/404** (dot-файлы заблокированы `.htaccess`).
- `php artisan --version` на сервере показывает Laravel 13.x на PHP 8.3.x.
