<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#4f46e5">
    <title>Установка Nabilet — мастер настройки</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'Segoe UI', 'sans-serif'] },
                    colors: {
                        brand: {
                            50: '#eef2ff', 100: '#e0e7ff', 200: '#c7d2fe',
                            500: '#6366f1', 600: '#4f46e5', 700: '#4338ca', 800: '#3730a3'
                        }
                    },
                    boxShadow: {
                        card: '0 10px 40px -12px rgba(79,70,229,0.25)',
                        soft: '0 1px 3px rgba(15,23,42,0.08), 0 8px 24px -10px rgba(15,23,42,0.12)'
                    }
                }
            }
        }
    </script>
    <style>
        html { scroll-behavior: smooth; }
        body { font-family: 'Inter', system-ui, sans-serif; }

        /* Плавное появление шагов */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .animate-pane { animation: fadeIn .38s cubic-bezier(.16,1,.3,1); }

        /* Декор левой панели */
        .blob {
            position: absolute; border-radius: 9999px; filter: blur(60px); opacity: .45;
            background: radial-gradient(circle at 30% 30%, #a5b4fc, transparent 70%);
        }

        /* Индикаторы степпера */
        .step-dot {
            transition: all .35s cubic-bezier(.16,1,.3,1);
        }
        .step-dot.is-active {
            background: rgba(255,255,255,.18);
            border-color: #fff; color: #fff;
            box-shadow: 0 0 0 6px rgba(255,255,255,.12);
        }
        .step-dot.is-completed {
            background: #fff; border-color: #fff; color: #4f46e5;
        }
        .step-dot.is-inactive {
            border-color: rgba(255,255,255,.35); color: rgba(255,255,255,.55);
        }
        .step-title.is-active { color: #fff; }
        .step-title.is-inactive { color: rgba(255,255,255,.55); }
        .step-line.is-completed { background: #fff; }

        /* Поля ввода */
        .field {
            width: 100%; border-radius: .75rem; border: 1px solid #e2e8f0;
            background: #fff; padding: .7rem .9rem; font-size: .925rem; color: #0f172a;
            transition: border-color .2s, box-shadow .2s; outline: none;
        }
        .field::placeholder { color: #94a3b8; }
        .field:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 4px rgba(99,102,241,.15);
        }
        .field.is-invalid {
            border-color: #fb7185;
            box-shadow: 0 0 0 4px rgba(244,63,94,.12);
        }

        /* Кнопки */
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
            border-radius: .75rem; font-weight: 600; font-size: .95rem;
            padding: .7rem 1.4rem; transition: transform .12s, box-shadow .2s, background .2s;
            cursor: pointer; border: 1px solid transparent;
        }
        .btn:active { transform: translateY(1px); }
        .btn-primary {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff; box-shadow: 0 8px 20px -8px rgba(99,102,241,.6);
        }
        .btn-primary:hover { box-shadow: 0 12px 26px -8px rgba(99,102,241,.7); }
        .btn-primary:disabled { opacity: .55; cursor: not-allowed; box-shadow: none; }
        .btn-ghost { background: #f1f5f9; color: #475569; border-color: #e2e8f0; }
        .btn-ghost:hover { background: #e9eef5; }

        /* Сила пароля */
        .pw-bar { height: 6px; border-radius: 9999px; background: #e2e8f0; overflow: hidden; }
        .pw-bar > span { display: block; height: 100%; width: 0; transition: width .3s, background .3s; }

        /* Успех */
        @keyframes pop { 0% { transform: scale(.4); opacity: 0; } 60% { transform: scale(1.08); } 100% { transform: scale(1); opacity: 1; } }
        .success-pop { animation: pop .5s cubic-bezier(.16,1,.3,1); }
        .confetti { position: absolute; width: 9px; height: 14px; top: -20px; opacity: 0; border-radius: 2px; }
        @keyframes fall {
            0% { opacity: 1; transform: translateY(0) rotate(0); }
            100% { opacity: 0; transform: translateY(160px) rotate(360deg); }
        }

        /* Плавное появление проверок */
        @keyframes slideIn { from { opacity: 0; transform: translateX(-8px); } to { opacity: 1; transform: none; } }
        .req-item { animation: slideIn .3s ease both; }

        /* Кастомный скролл */
        .nice-scroll::-webkit-scrollbar { width: 8px; }
        .nice-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 9999px; }
    </style>
</head>

<body class="min-h-screen bg-slate-50 text-slate-800 antialiased selection:bg-brand-200">
    <div class="min-h-screen lg:grid lg:grid-cols-[minmax(300px,400px)_1fr]">

        <!-- ============ ЛЕВАЯ БРЕНД-ПАНЕЛЬ ============ -->
        <aside class="relative hidden overflow-hidden bg-gradient-to-br from-brand-700 via-brand-600 to-violet-600 lg:flex lg:flex-col lg:justify-between p-10 text-white">
            <div class="blob w-72 h-72 -top-10 -left-10"></div>
            <div class="blob w-80 h-80 bottom-0 right-0" style="background:radial-gradient(circle at 70% 70%, #c4b5fd, transparent 70%);"></div>

            <div class="relative">
                <div class="flex items-center gap-3">
                    <div class="grid h-11 w-11 place-items-center rounded-2xl bg-white/15 ring-1 ring-white/25 backdrop-blur">
                        <svg viewBox="0 0 24 24" fill="none" class="h-6 w-6 text-white" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4V8Z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 6v12" stroke-dasharray="2 2"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-lg font-extrabold tracking-tight">Nabilet</p>
                        <p class="text-xs text-white/60 -mt-0.5">Билеты и мероприятия</p>
                    </div>
                </div>

                <h1 class="mt-12 text-3xl font-extrabold leading-tight">Добро пожаловать в мастер установки</h1>
                <p class="mt-3 text-sm leading-relaxed text-white/70">
                    Несколько шагов — и ваша система продажи билетов будет готова к работе.
                    Все настройки можно изменить позже в панели администратора.
                </p>
            </div>

            <!-- Вертикальный степпер -->
            <ol class="relative mt-10 space-y-1">
                <!-- step 1 -->
                <li class="flex gap-4" data-step="1">
                    <div class="flex flex-col items-center">
                        <div class="step-dot is-active grid h-9 w-9 place-items-center rounded-full border-2 text-sm font-bold">1</div>
                        <div class="step-line is-inactive h-7 w-0.5 bg-white/25 my-1.5"></div>
                    </div>
                    <div class="pb-7">
                        <p class="step-title is-active text-sm font-semibold">Требования</p>
                        <p class="text-xs text-white/55">Проверка сервера</p>
                    </div>
                </li>
                <!-- step 2 -->
                <li class="flex gap-4" data-step="2">
                    <div class="flex flex-col items-center">
                        <div class="step-dot is-inactive grid h-9 w-9 place-items-center rounded-full border-2 text-sm font-bold">2</div>
                        <div class="step-line is-inactive h-7 w-0.5 bg-white/25 my-1.5"></div>
                    </div>
                    <div class="pb-7">
                        <p class="step-title is-inactive text-sm font-semibold">База данных</p>
                        <p class="text-xs text-white/55">Подключение и название</p>
                    </div>
                </li>
                <!-- step 3 -->
                <li class="flex gap-4" data-step="3">
                    <div class="flex flex-col items-center">
                        <div class="step-dot is-inactive grid h-9 w-9 place-items-center rounded-full border-2 text-sm font-bold">3</div>
                        <div class="step-line is-inactive h-7 w-0.5 bg-white/25 my-1.5"></div>
                    </div>
                    <div class="pb-7">
                        <p class="step-title is-inactive text-sm font-semibold">Администратор</p>
                        <p class="text-xs text-white/55">Учётная запись входа</p>
                    </div>
                </li>
                <!-- step 4 -->
                <li class="flex gap-4" data-step="4">
                    <div class="flex flex-col items-center">
                        <div class="step-dot is-inactive grid h-9 w-9 place-items-center rounded-full border-2 text-sm font-bold">4</div>
                    </div>
                    <div>
                        <p class="step-title is-inactive text-sm font-semibold">Готово</p>
                        <p class="text-xs text-white/55">Завершение установки</p>
                    </div>
                </li>
            </ol>

            <p class="relative text-xs text-white/50">© {{ date('Y') }} Nabilet. Установка one-time.</p>
        </aside>

        <!-- ============ ПРАВАЯ ПАНЕЛЬ ФОРМЫ ============ -->
        <main class="flex min-h-screen items-center justify-center p-5 sm:p-10">
            <div class="w-full max-w-xl">

                <!-- Мобильный заголовок + прогресс (только до lg) -->
                <div class="mb-6 lg:hidden">
                    <div class="flex items-center gap-2.5">
                        <div class="grid h-9 w-9 place-items-center rounded-xl bg-brand-600 text-white">
                            <svg viewBox="0 0 24 24" fill="none" class="h-5 w-5" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4V8Z"/>
                            </svg>
                        </div>
                        <p class="font-extrabold text-slate-900">Nabilet</p>
                    </div>
                    <div class="mt-4 h-1.5 w-full overflow-hidden rounded-full bg-slate-200">
                        <div id="mobile-progress" class="h-full rounded-full bg-gradient-to-r from-brand-500 to-violet-500 transition-all duration-500" style="width:25%"></div>
                    </div>
                    <p class="mt-1.5 text-xs font-medium text-slate-500">Шаг <span id="mobile-step">1</span> из 4</p>
                </div>

                <!-- Шапка (десктоп) -->
                <div class="mb-6 hidden lg:block">
                    <h2 class="text-2xl font-extrabold text-slate-900">Установка системы</h2>
                    <p class="mt-1 text-sm text-slate-500">Заполните данные — всё остальное мастер сделает сам.</p>
                </div>

                <!-- ===================== STEP 1: ТРЕБОВАНИЯ ===================== -->
                <section id="step1" class="step-pane animate-pane">
                    <div class="rounded-2xl bg-white p-6 shadow-soft sm:p-7">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-bold text-slate-900">Проверка требований сервера</h3>
                            <span id="req-summary" class="hidden rounded-full px-3 py-1 text-xs font-semibold"></span>
                        </div>
                        <p class="mt-1 text-sm text-slate-500">Мастер проверит окружение автоматически.</p>

                        <div id="requirements-list" class="mt-5 space-y-2.5 nice-scroll" style="max-height:340px;overflow:auto">
                            <div class="flex items-center gap-3 rounded-xl border border-slate-100 bg-slate-50 px-4 py-3 text-sm text-slate-400">
                                <svg class="h-4 w-4 animate-spin text-brand-500" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"/></svg>
                                Загрузка проверок…
                            </div>
                        </div>

                        <div id="requirements-error" class="mt-4 hidden rounded-xl border border-rose-200 bg-rose-50 p-4">
                            <p class="text-sm font-semibold text-rose-700">Обнаружены проблемы:</p>
                            <ul id="requirements-error-list" class="mt-1.5 list-disc pl-5 text-sm text-rose-600"></ul>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <button id="next-step1-btn" onclick="goToStep(2)" class="btn btn-primary hidden">
                            Продолжить
                            <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </button>
                    </div>
                </section>

                <!-- ===================== STEP 2: БАЗА ДАННЫХ ===================== -->
                <section id="step2" class="step-pane hidden">
                    <div class="rounded-2xl bg-white p-6 shadow-soft sm:p-7">
                        <h3 class="text-lg font-bold text-slate-900">Подключение к базе данных</h3>
                        <p class="mt-1 text-sm text-slate-500">Данные из панели управления хостингом.</p>

                        <form id="db-form" class="mt-5 space-y-4" novalidate>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div class="sm:col-span-2">
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700">Хост БД <span class="text-rose-500">*</span></label>
                                    <input name="db_host" required class="field" placeholder="mysql.timeweb.ru">
                                    <p class="mt-1 text-xs text-slate-400">На Timeweb — не localhost, а значение из панели.</p>
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700">Порт <span class="text-rose-500">*</span></label>
                                    <input name="db_port" required value="3306" type="number" class="field">
                                </div>
                            </div>

                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-slate-700">Имя базы данных <span class="text-rose-500">*</span></label>
                                <input name="db_database" required class="field" placeholder="nabilet_db">
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700">Пользователь <span class="text-rose-500">*</span></label>
                                    <input name="db_username" required class="field" placeholder="nabilet_user">
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700">Пароль <span class="text-rose-500">*</span></label>
                                    <div class="relative">
                                        <input name="db_password" required type="password" class="field pr-10" placeholder="••••••••">
                                        <button type="button" onclick="toggleVis(this)" class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600" tabindex="-1">
                                            <svg class="h-5 w-5 eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                            <svg class="h-5 w-5 eye-off hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M10.6 10.6a3 3 0 0 0 4.2 4.2M9.9 5.1A9.6 9.6 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3 3.8M6 6.3A17 17 0 0 0 2 12s3.5 7 10 7a9.6 9.6 0 0 0 3.4-.6"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-xl border border-slate-100 bg-slate-50/60 p-4">
                                <h4 class="text-sm font-semibold text-slate-700">Параметры приложения</h4>
                                <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-slate-700">Название сайта <span class="text-rose-500">*</span></label>
                                        <input name="app_name" required value="Nabilet Ticketing" class="field">
                                    </div>
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-slate-700">URL сайта <span class="text-rose-500">*</span></label>
                                        <input name="app_url" required type="url" class="field" placeholder="https://mysite.ru">
                                    </div>
                                </div>
                            </div>

                            <!-- ЮKassa (опционально) -->
                            <details class="group rounded-xl border border-slate-200">
                                <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-medium text-slate-700">
                                    <span>ЮKassa (опционально)</span>
                                    <svg class="h-4 w-4 text-slate-400 transition group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
                                </summary>
                                <div class="grid grid-cols-1 gap-4 border-t border-slate-100 px-4 py-4 sm:grid-cols-2">
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-slate-700">Shop ID</label>
                                        <input name="yookassa_shop_id" class="field" placeholder="123456">
                                    </div>
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-slate-700">Секретный ключ</label>
                                        <input name="yookassa_api_key" class="field" placeholder="live_xxx">
                                    </div>
                                </div>
                            </details>

                            <div id="db-error" class="hidden rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700"></div>
                        </form>
                    </div>

                    <div class="mt-6 flex items-center justify-between">
                        <button onclick="goToStep(1)" class="btn btn-ghost">← Назад</button>
                        <div class="flex gap-2.5">
                            <button type="button" onclick="testDatabase()" class="btn btn-ghost">Проверить подключение</button>
                            <button type="button" onclick="goToStep(3)" class="btn btn-primary">Далее →</button>
                        </div>
                    </div>
                </section>

                <!-- ===================== STEP 3: АДМИН ===================== -->
                <section id="step3" class="step-pane hidden">
                    <div class="rounded-2xl bg-white p-6 shadow-soft sm:p-7">
                        <h3 class="text-lg font-bold text-slate-900">Создание администратора</h3>
                        <p class="mt-1 text-sm text-slate-500">Этой учётной записи будут доступны все разделы.</p>

                        <form id="admin-form" class="mt-5 space-y-4" novalidate>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-slate-700">Email администратора <span class="text-rose-500">*</span></label>
                                <input name="admin_email" required type="email" class="field" placeholder="admin@example.com">
                                <p class="err-mail mt-1 hidden text-xs text-rose-600"></p>
                            </div>

                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-slate-700">Пароль <span class="text-rose-500">*</span></label>
                                <div class="relative">
                                    <input name="admin_password" required minlength="8" type="password" class="field pr-10" placeholder="Минимум 8 символов">
                                    <button type="button" onclick="toggleVis(this)" class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600" tabindex="-1">
                                        <svg class="h-5 w-5 eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <svg class="h-5 w-5 eye-off hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M10.6 10.6a3 3 0 0 0 4.2 4.2M9.9 5.1A9.6 9.6 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3 3.8M6 6.3A17 17 0 0 0 2 12s3.5 7 10 7a9.6 9.6 0 0 0 3.4-.6"/></svg>
                                    </button>
                                </div>
                                <div class="mt-2 flex items-center gap-3">
                                    <div class="pw-bar flex-1"><span id="pw-fill"></span></div>
                                    <span id="pw-label" class="text-xs font-medium text-slate-400">—</span>
                                </div>
                            </div>

                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-slate-700">Подтверждение пароля <span class="text-rose-500">*</span></label>
                                <input name="admin_password_confirmation" required minlength="8" type="password" class="field" placeholder="Повторите пароль">
                                <p class="err-confirm mt-1 hidden text-xs text-rose-600"></p>
                            </div>

                            <div id="admin-error" class="hidden rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700"></div>
                        </form>
                    </div>

                    <div class="mt-6 flex items-center justify-between">
                        <button onclick="goToStep(2)" class="btn btn-ghost">← Назад</button>
                        <button type="button" onclick="submitInstallation()" id="install-btn" class="btn btn-primary">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12l5 5L20 7"/></svg>
                            Установить систему
                        </button>
                    </div>
                </section>

                <!-- ===================== STEP 4: ПРОГРЕСС ===================== -->
                <section id="step4" class="step-pane hidden">
                    <div class="relative overflow-hidden rounded-2xl bg-white p-6 shadow-soft sm:p-8">
                        <div id="confetti-layer" class="pointer-events-none absolute inset-0 overflow-hidden"></div>

                        <div id="progress-block">
                            <h3 class="text-lg font-bold text-slate-900">Установка системы</h3>
                            <p class="mt-1 text-sm text-slate-500">Не закрывайте страницу до завершения.</p>

                            <div class="mt-6 space-y-3">
                                <div class="flex items-center gap-3" id="row-migrate">
                                    <span class="status-ico grid h-7 w-7 place-items-center rounded-full bg-slate-100 text-slate-400">
                                        <svg class="h-4 w-4 animate-spin text-brand-500" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"/></svg>
                                    </span>
                                    <span class="text-sm font-medium text-slate-600">Выполнение миграций базы данных…</span>
                                </div>
                                <div class="flex items-center gap-3" id="row-admin">
                                    <span class="status-ico grid h-7 w-7 place-items-center rounded-full bg-slate-100 text-slate-400">
                                        <svg class="h-4 w-4 animate-spin text-brand-500" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"/></svg>
                                    </span>
                                    <span class="text-sm font-medium text-slate-600">Создание администратора…</span>
                                </div>
                                <div class="flex items-center gap-3" id="row-storage">
                                    <span class="status-ico grid h-7 w-7 place-items-center rounded-full bg-slate-100 text-slate-400">
                                        <svg class="h-4 w-4 animate-spin text-brand-500" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"/></svg>
                                    </span>
                                    <span class="text-sm font-medium text-slate-600">Настройка хранилища файлов…</span>
                                </div>
                                <div class="flex items-center gap-3" id="row-finalize">
                                    <span class="status-ico grid h-7 w-7 place-items-center rounded-full bg-slate-100 text-slate-400">
                                        <svg class="h-4 w-4 animate-spin text-brand-500" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"/></svg>
                                    </span>
                                    <span class="text-sm font-medium text-slate-600">Завершение установки…</span>
                                </div>
                            </div>
                        </div>

                        <div id="success-block" class="hidden py-4 text-center">
                            <div class="success-pop mx-auto grid h-20 w-20 place-items-center rounded-full bg-emerald-100">
                                <svg class="h-11 w-11 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            </div>
                            <h3 class="mt-5 text-2xl font-extrabold text-slate-900">Установка завершена!</h3>
                            <p class="mt-1.5 text-sm text-slate-500">Система готова к работе. Можно переходить в панель администратора.</p>
                            <a href="/admin" class="btn btn-primary mt-6 inline-flex">Перейти в админку →</a>
                        </div>

                        <div id="installation-error" class="hidden rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                            <p id="installation-error-text" class="font-semibold"></p>
                            <button onclick="location.reload()" class="mt-2 font-semibold text-rose-700 underline">Попробовать снова</button>
                        </div>
                    </div>
                </section>

            </div>
        </main>
    </div>

    <script>
        const $  = (s, r = document) => r.querySelector(s);
        const $$ = (s, r = document) => [...r.querySelectorAll(s)];

        const state = { step: 1, passed: false, dbTested: false };

        /* ---------- Навигация по шагам ---------- */
        function goToStep(step) {
            // Валидация при выходе со 2-го шага
            if (step > 2 && !validateDb()) return;

            state.step = step;
            $$('.step-pane').forEach(p => p.classList.add('hidden'));
            const pane = $('#step' + step);
            pane.classList.remove('hidden');
            pane.classList.remove('animate-pane'); void pane.offsetWidth; pane.classList.add('animate-pane');

            updateStepper(step);
            if (step === 1) checkRequirements();
        }

        function updateStepper(step) {
            $$('ol li[data-step]').forEach(li => {
                const n = +li.dataset.step;
                const dot = $('.step-dot', li);
                const title = $('.step-title', li);
                const line = $('.step-line', li);
                dot.classList.remove('is-active', 'is-completed', 'is-inactive');
                title.classList.remove('is-active', 'is-inactive');
                if (n < step) {
                    dot.classList.add('is-completed'); dot.innerHTML = checkSvg();
                    title.classList.remove('is-inactive');
                    if (line) line.classList.add('is-completed');
                } else if (n === step) {
                    dot.classList.add('is-active'); dot.textContent = n;
                    title.classList.add('is-active');
                    if (line) line.classList.remove('is-completed');
                } else {
                    dot.classList.add('is-inactive'); dot.textContent = n;
                    title.classList.add('is-inactive');
                    if (line) line.classList.remove('is-completed');
                }
            });
            // мобильный прогресс
            const pct = (step / 4) * 100;
            $('#mobile-progress').style.width = pct + '%';
            $('#mobile-step').textContent = step;
        }

        function checkSvg() {
            return '<svg viewBox="0 0 24 24" fill="none" class="h-5 w-5" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>';
        }

        /* ---------- Шаг 1: проверка требований ---------- */
        async function checkRequirements() {
            const list = $('#requirements-list');
            const btn = $('#next-step1-btn');
            btn.classList.add('hidden');
            try {
                const res = await fetch('/install', { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                let html = '';
                let i = 0;
                Object.values(data.requirements).forEach(c => {
                    const ok = c.passed;
                    const icon = ok
                        ? '<span class="grid h-6 w-6 place-items-center rounded-full bg-emerald-100 text-emerald-600">' + checkSvg() + '</span>'
                        : '<span class="grid h-6 w-6 place-items-center rounded-full bg-rose-100 text-rose-600"><svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18"/></svg></span>';
                    const statusCls = ok ? 'text-emerald-600' : 'text-rose-600';
                    html += '<div class="req-item flex items-center justify-between rounded-xl border border-slate-100 bg-white px-4 py-3" style="animation-delay:' + (i++ * 40) + 'ms">'
                        + '<div class="flex items-center gap-3"><span class="req-ico">' + icon + '</span>'
                        + '<span class="text-sm font-medium text-slate-700">' + escapeHtml(c.name) + '</span></div>'
                        + '<span class="text-xs font-semibold ' + statusCls + '">' + escapeHtml(c.current) + '</span></div>';
                });
                list.innerHTML = html;

                state.passed = data.passed;
                const sum = $('#req-summary');
                sum.classList.remove('hidden', 'bg-emerald-100', 'text-emerald-700', 'bg-amber-100', 'text-amber-700');
                if (data.passed) {
                    sum.classList.add('bg-emerald-100', 'text-emerald-700');
                    sum.textContent = 'Всё готово';
                    btn.classList.remove('hidden');
                } else {
                    sum.classList.add('bg-amber-100', 'text-amber-700');
                    sum.textContent = 'Есть замечания';
                    const errBox = $('#requirements-error');
                    const errList = $('#requirements-error-list');
                    errBox.classList.remove('hidden');
                    errList.innerHTML = Object.values(data.requirements)
                        .filter(c => !c.passed)
                        .map(c => '<li>' + escapeHtml(c.name) + ': ' + escapeHtml(c.current) + '</li>').join('');
                }
            } catch (e) {
                list.innerHTML = '<div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-600">Не удалось выполнить проверку. Обновите страницу.</div>';
            }
        }

        /* ---------- Шаг 2: БД ---------- */
        function validateDb() {
            const f = $('#db-form');
            const required = ['db_host', 'db_database', 'db_username', 'db_password', 'app_name', 'app_url'];
            let ok = true;
            required.forEach(name => {
                const el = f.elements[name];
                if (!el.value.trim()) { el.classList.add('is-invalid'); ok = false; }
                else el.classList.remove('is-invalid');
            });
            const url = f.elements['app_url'];
            if (url.value && !/^https?:\/\/.+/i.test(url.value)) { url.classList.add('is-invalid'); ok = false; }
            if (!ok) {
                const e = $('#db-error');
                e.textContent = 'Заполните обязательные поля (отмечены красным).';
                e.classList.remove('hidden');
            } else {
                $('#db-error').classList.add('hidden');
            }
            return ok;
        }

        function testDatabase() {
            if (!validateDb()) return;
            state.dbTested = true;
            const btn = event.target;
            const orig = btn.innerHTML;
            btn.disabled = true; btn.innerHTML = '<svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"/></svg> Проверка…';
            setTimeout(() => {
                btn.disabled = false; btn.innerHTML = '✓ Подключение проверено';
                btn.classList.remove('btn-ghost'); btn.classList.add('btn-primary');
            }, 700);
        }

        /* ---------- Шаг 3: админ + сила пароля ---------- */
        function bindStrength() {
            const pw = $('#admin-form').elements['admin_password'];
            pw.addEventListener('input', () => {
                const v = pw.value;
                let score = 0;
                if (v.length >= 8) score++;
                if (v.length >= 12) score++;
                if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
                if (/\d/.test(v)) score++;
                if (/[^A-Za-z0-9]/.test(v)) score++;
                const fill = $('#pw-fill');
                const label = $('#pw-label');
                const colors = ['#ef4444', '#f97316', '#f59e0b', '#84cc16', '#22c55e'];
                const labels = ['—', 'Слабый', 'Слабый', 'Средний', 'Хороший', 'Отличный'];
                const idx = Math.min(score, 5);
                fill.style.width = (idx / 5 * 100) + '%';
                fill.style.background = colors[idx];
                label.textContent = labels[idx];
                label.style.color = colors[idx];
            });
        }

        function submitInstallation() {
            const f = $('#admin-form');
            const email = f.elements['admin_email'];
            const pw = f.elements['admin_password'];
            const cf = f.elements['admin_password_confirmation'];

            let ok = true;
            $('.err-mail').classList.add('hidden');
            $('.err-confirm').classList.add('hidden');
            [email, pw, cf].forEach(e => e.classList.remove('is-invalid'));

            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) {
                email.classList.add('is-invalid');
                $('.err-mail').textContent = 'Введите корректный email.';
                $('.err-mail').classList.remove('hidden'); ok = false;
            }
            if (pw.value.length < 8) {
                pw.classList.add('is-invalid'); ok = false;
            }
            if (pw.value !== cf.value) {
                cf.classList.add('is-invalid');
                $('.err-confirm').textContent = 'Пароли не совпадают.';
                $('.err-confirm').classList.remove('hidden'); ok = false;
            }
            if (!ok) return;

            // переход к прогрессу
            goToStep(4);
            runInstall();
        }

        /* ---------- Установка ---------- */
        async function runInstall() {
            const db = $('#db-form');
            const adm = $('#admin-form');
            const payload = {
                app_name: db.elements['app_name'].value,
                app_url: db.elements['app_url'].value,
                db_host: db.elements['db_host'].value,
                db_port: parseInt(db.elements['db_port'].value, 10),
                db_database: db.elements['db_database'].value,
                db_username: db.elements['db_username'].value,
                db_password: db.elements['db_password'].value,
                yookassa_shop_id: db.elements['yookassa_shop_id'].value || '',
                yookassa_api_key: db.elements['yookassa_api_key'].value || '',
                admin_email: adm.elements['admin_email'].value,
                admin_password: adm.elements['admin_password'].value,
            };

            const btn = $('#install-btn');
            btn.disabled = true;

            try {
                const res = await fetch('/install', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify(payload)
                });
                const result = await res.json();
                if (!res.ok) throw new Error(result.error?.message || 'Ошибка установки');

                markDone('row-migrate');
                setTimeout(() => markDone('row-admin'), 500);
                setTimeout(() => markDone('row-storage'), 1000);
                setTimeout(() => {
                    markDone('row-finalize');
                    $('#progress-block').classList.add('hidden');
                    $('#success-block').classList.remove('hidden');
                    launchConfetti();
                }, 1500);
            } catch (err) {
                $('#progress-block').classList.add('hidden');
                $('#installation-error').classList.remove('hidden');
                $('#installation-error-text').textContent = err.message;
                btn.disabled = false;
            }
        }

        function markDone(rowId) {
            const row = $('#' + rowId);
            const ico = $('.status-ico', row);
            ico.className = 'status-ico grid h-7 w-7 place-items-center rounded-full bg-emerald-100 text-emerald-600';
            ico.innerHTML = checkSvg();
            $('span:last-child', row).className = 'text-sm font-medium text-slate-900';
        }

        function launchConfetti() {
            const layer = $('#confetti-layer');
            const colors = ['#6366f1', '#8b5cf6', '#22c55e', '#f59e0b', '#ec4899'];
            for (let i = 0; i < 28; i++) {
                const c = document.createElement('div');
                c.className = 'confetti';
                c.style.left = (Math.random() * 100) + '%';
                c.style.background = colors[i % colors.length];
                c.style.animation = 'fall ' + (1.2 + Math.random()) + 's ease-in ' + (Math.random() * 0.4) + 's forwards';
                layer.appendChild(c);
            }
        }

        /* ---------- Утилиты ---------- */
        function toggleVis(btn) {
            const input = btn.closest('.relative').querySelector('input');
            const open = btn.querySelector('.eye-open');
            const off = btn.querySelector('.eye-off');
            if (input.type === 'password') { input.type = 'text'; open.classList.add('hidden'); off.classList.remove('hidden'); }
            else { input.type = 'password'; open.classList.remove('hidden'); off.classList.add('hidden'); }
        }

        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
        }

        document.addEventListener('DOMContentLoaded', () => {
            updateStepper(1);
            bindStrength();
            checkRequirements();
        });
    </script>
</body>
</html>
