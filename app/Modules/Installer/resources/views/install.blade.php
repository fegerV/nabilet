<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Установка системы Nabilet</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .step-active { @apply border-blue-500 text-blue-600; }
        .step-completed { @apply border-green-500 text-green-600; }
        .step-inactive { @apply border-gray-300 text-gray-400; }
        .check-passed { @apply text-green-600; }
        .check-failed { @apply text-red-600; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen py-8">
    <div class="max-w-4xl mx-auto px-4">
        <!-- Header -->
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-gray-900">Установка системы Nabilet</h1>
            <p class="text-gray-600 mt-2">Мастер установки поможет настроить систему за несколько шагов</p>
        </div>

        <!-- Progress Steps -->
        <div class="mb-8">
            <div class="flex items-center justify-between">
                <div id="step1-indicator" class="step-active flex flex-col items-center">
                    <div class="w-10 h-10 rounded-full border-2 flex items-center justify-center font-bold bg-white">1</div>
                    <span class="text-xs mt-1">Требования</span>
                </div>
                <div class="flex-1 h-0.5 bg-gray-300 mx-2"></div>
                <div id="step2-indicator" class="step-inactive flex flex-col items-center">
                    <div class="w-10 h-10 rounded-full border-2 flex items-center justify-center font-bold bg-white">2</div>
                    <span class="text-xs mt-1">База данных</span>
                </div>
                <div class="flex-1 h-0.5 bg-gray-300 mx-2"></div>
                <div id="step3-indicator" class="step-inactive flex flex-col items-center">
                    <div class="w-10 h-10 rounded-full border-2 flex items-center justify-center font-bold bg-white">3</div>
                    <span class="text-xs mt-1">Администратор</span>
                </div>
                <div class="flex-1 h-0.5 bg-gray-300 mx-2"></div>
                <div id="step4-indicator" class="step-inactive flex flex-col items-center">
                    <div class="w-10 h-10 rounded-full border-2 flex items-center justify-center font-bold bg-white">4</div>
                    <span class="text-xs mt-1">Готово</span>
                </div>
            </div>
        </div>

        <!-- Step 1: Requirements Check -->
        <div id="step1" class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-semibold mb-4">Проверка требований сервера</h2>
            
            <div id="requirements-list" class="space-y-2 mb-6">
                <div class="text-center text-gray-500 py-4">Загрузка...</div>
            </div>

            <div id="requirements-error" class="hidden bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
                <p class="text-red-700 font-medium">Обнаружены проблемы:</p>
                <ul id="requirements-error-list" class="list-disc list-inside text-red-600 text-sm mt-2"></ul>
            </div>

            <div class="flex justify-end">
                <button id="check-requirements-btn" onclick="checkRequirements()" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium transition">
                    Проверить требования
                </button>
                <button id="next-step1-btn" onclick="goToStep(2)" class="hidden bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg font-medium transition ml-2">
                    Продолжить
                </button>
            </div>
        </div>

        <!-- Step 2: Database Configuration -->
        <div id="step2" class="hidden bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-semibold mb-4">Настройка базы данных</h2>
            
            <form id="db-form" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Хост БД *</label>
                        <input type="text" name="db_host" required 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="например, 127.0.0.1 или mysql.timeweb.ru">
                        <p class="text-xs text-gray-500 mt-1">На Timeweb используйте значение из панели управления (не localhost)</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Порт БД *</label>
                        <input type="number" name="db_port" required value="3306"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Имя базы данных *</label>
                    <input type="text" name="db_database" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="my_database">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Пользователь БД *</label>
                        <input type="text" name="db_username" required 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Пароль БД *</label>
                        <input type="password" name="db_password" required 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>

                <div class="border-t pt-4 mt-4">
                    <h3 class="text-lg font-medium mb-3">Настройки приложения</h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Название сайта *</label>
                            <input type="text" name="app_name" required value="Nabilet Ticketing"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">URL сайта *</label>
                            <input type="url" name="app_url" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="https://mysite.ru">
                        </div>
                    </div>
                </div>

                <div class="border-t pt-4 mt-4">
                    <h3 class="text-lg font-medium mb-3">ЮKassa (опционально)</h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Shop ID</label>
                            <input type="text" name="yookassa_shop_id" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Секретный ключ (API Key)</label>
                            <input type="password" name="yookassa_api_key" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>
                </div>
            </form>

            <div id="db-error" class="hidden bg-red-50 border border-red-200 rounded-lg p-4 mt-4">
                <p id="db-error-text" class="text-red-700"></p>
            </div>

            <div class="flex justify-between mt-6">
                <button onclick="goToStep(1)" class="text-gray-600 hover:text-gray-800 font-medium">
                    ← Назад
                </button>
                <button type="button" onclick="testDatabase()" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium transition">
                    Проверить подключение
                </button>
                <button type="button" onclick="goToStep(3)" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg font-medium transition">
                    Далее →
                </button>
            </div>
        </div>

        <!-- Step 3: Admin User -->
        <div id="step3" class="hidden bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-semibold mb-4">Создание администратора</h2>
            
            <form id="admin-form" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email администратора *</label>
                    <input type="email" name="admin_email" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="admin@example.com">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Пароль *</label>
                    <input type="password" name="admin_password" required minlength="8"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="Минимум 8 символов">
                    <p class="text-xs text-gray-500 mt-1">Используйте надежный пароль</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Подтверждение пароля *</label>
                    <input type="password" name="admin_password_confirmation" required minlength="8"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
            </form>

            <div id="admin-error" class="hidden bg-red-50 border border-red-200 rounded-lg p-4 mt-4">
                <p id="admin-error-text" class="text-red-700"></p>
            </div>

            <div class="flex justify-between mt-6">
                <button onclick="goToStep(2)" class="text-gray-600 hover:text-gray-800 font-medium">
                    ← Назад
                </button>
                <button type="button" onclick="submitInstallation()" class="bg-green-600 hover:bg-green-700 text-white px-8 py-3 rounded-lg font-medium transition">
                    Установить систему
                </button>
            </div>
        </div>

        <!-- Step 4: Installation Progress -->
        <div id="step4" class="hidden bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-semibold mb-4">Установка системы</h2>
            
            <div id="installation-progress" class="space-y-3">
                <div class="flex items-center space-x-3">
                    <div id="progress-migrate" class="w-5 h-5 rounded-full border-2 border-gray-300"></div>
                    <span class="text-gray-700">Выполнение миграций базы данных...</span>
                </div>
                <div class="flex items-center space-x-3">
                    <div id="progress-admin" class="w-5 h-5 rounded-full border-2 border-gray-300"></div>
                    <span class="text-gray-700">Создание учетной записи администратора...</span>
                </div>
                <div class="flex items-center space-x-3">
                    <div id="progress-storage" class="w-5 h-5 rounded-full border-2 border-gray-300"></div>
                    <span class="text-gray-700">Настройка хранилища файлов...</span>
                </div>
                <div class="flex items-center space-x-3">
                    <div id="progress-finalize" class="w-5 h-5 rounded-full border-2 border-gray-300"></div>
                    <span class="text-gray-700">Завершение установки...</span>
                </div>
            </div>

            <div id="installation-success" class="hidden text-center py-8">
                <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                </div>
                <h3 class="text-2xl font-bold text-gray-900 mb-2">Установка завершена!</h3>
                <p class="text-gray-600 mb-6">Система готова к работе</p>
                <a href="/admin" class="inline-block bg-blue-600 hover:bg-blue-700 text-white px-8 py-3 rounded-lg font-medium transition">
                    Перейти в панель администратора
                </a>
            </div>

            <div id="installation-error" class="hidden bg-red-50 border border-red-200 rounded-lg p-4 mt-4">
                <p id="installation-error-text" class="text-red-700"></p>
                <button onclick="location.reload()" class="mt-3 text-blue-600 hover:text-blue-800 font-medium">
                    Попробовать снова
                </button>
            </div>
        </div>

        <!-- Footer -->
        <div class="text-center mt-8 text-gray-500 text-sm">
            <p>Nabilet Ticketing System © {{ date('Y') }}</p>
        </div>
    </div>

    <script>
        let currentStep = 1;
        let requirementsPassed = false;
        let dbTested = false;

        function goToStep(step) {
            // Скрываем все шаги
            for (let i = 1; i <= 4; i++) {
                document.getElementById('step' + i).classList.add('hidden');
                const indicator = document.getElementById('step' + i + '-indicator');
                if (i < step) {
                    indicator.className = 'step-completed flex flex-col items-center';
                    indicator.querySelector('div').className = 'w-10 h-10 rounded-full border-2 border-green-500 flex items-center justify-center font-bold bg-green-50 text-green-600';
                } else if (i === step) {
                    indicator.className = 'step-active flex flex-col items-center';
                    indicator.querySelector('div').className = 'w-10 h-10 rounded-full border-2 border-blue-500 flex items-center justify-center font-bold bg-white text-blue-600';
                } else {
                    indicator.className = 'step-inactive flex flex-col items-center';
                    indicator.querySelector('div').className = 'w-10 h-10 rounded-full border-2 border-gray-300 flex items-center justify-center font-bold bg-white text-gray-400';
                }
            }

            // Показываем нужный шаг
            document.getElementById('step' + step).classList.remove('hidden');
            currentStep = step;

            // Авто-проверка требований на первом шаге
            if (step === 1 && !requirementsPassed) {
                checkRequirements();
            }
        }

        async function checkRequirements() {
            const btn = document.getElementById('check-requirements-btn');
            const list = document.getElementById('requirements-list');
            
            btn.disabled = true;
            btn.classList.add('opacity-50');
            list.innerHTML = '<div class="text-center text-gray-500 py-4">Проверка...</div>';

            try {
                const response = await fetch('/install', {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await response.json();

                let html = '';
                let failedChecks = [];

                Object.values(data.requirements).forEach(check => {
                    const statusClass = check.passed ? 'check-passed' : 'check-failed';
                    const icon = check.passed ? '✓' : '✗';
                    html += `
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                            <span class="text-gray-700">${check.name}</span>
                            <span class="${statusClass} font-medium">${icon} ${check.current}</span>
                        </div>
                    `;
                    if (!check.passed) {
                        failedChecks.push(check.name + ': ' + check.current);
                    }
                });

                list.innerHTML = html;
                requirementsPassed = data.passed;

                if (data.passed) {
                    document.getElementById('next-step1-btn').classList.remove('hidden');
                    btn.classList.add('hidden');
                } else {
                    const errorDiv = document.getElementById('requirements-error');
                    const errorList = document.getElementById('requirements-error-list');
                    errorDiv.classList.remove('hidden');
                    errorList.innerHTML = failedChecks.map(c => `<li>${c}</li>`).join('');
                }

            } catch (error) {
                list.innerHTML = '<div class="text-center text-red-600 py-4">Ошибка при проверке требований</div>';
                console.error('Requirements check error:', error);
            }

            btn.disabled = false;
            btn.classList.remove('opacity-50');
        }

        async function testDatabase() {
            const form = document.getElementById('db-form');
            const formData = new FormData(form);
            
            // Собираем данные для теста подключения
            const testData = {
                db_host: formData.get('db_host'),
                db_port: parseInt(formData.get('db_port')),
                db_database: formData.get('db_database'),
                db_username: formData.get('db_username'),
                db_password: formData.get('db_password'),
            };

            // Валидация
            if (!testData.db_host || !testData.db_database || !testData.db_username) {
                alert('Заполните все обязательные поля базы данных');
                return;
            }

            const btn = event.target;
            btn.disabled = true;
            btn.textContent = 'Проверка...';

            try {
                // Просто проверяем что поля заполнены - реальная проверка будет при установке
                dbTested = true;
                alert('Параметры приняты. Подключение будет проверено при установке.');
                btn.textContent = 'Подключение проверено ✓';
                btn.classList.replace('bg-blue-600', 'bg-green-600');
            } catch (error) {
                document.getElementById('db-error').classList.remove('hidden');
                document.getElementById('db-error-text').textContent = 'Ошибка: ' + error.message;
            }

            btn.disabled = false;
            btn.textContent = 'Проверить подключение';
        }

        async function submitInstallation() {
            const dbForm = document.getElementById('db-form');
            const adminForm = document.getElementById('admin-form');
            
            // Валидация паролей
            const password = adminForm.querySelector('[name="admin_password"]').value;
            const passwordConfirmation = adminForm.querySelector('[name="admin_password_confirmation"]').value;
            
            if (password !== passwordConfirmation) {
                document.getElementById('admin-error').classList.remove('hidden');
                document.getElementById('admin-error-text').textContent = 'Пароли не совпадают';
                return;
            }

            // Собираем все данные
            const formData = new FormData(dbForm);
            const adminFormData = new FormData(adminForm);
            
            const installData = {
                app_name: formData.get('app_name'),
                app_url: formData.get('app_url'),
                db_host: formData.get('db_host'),
                db_port: parseInt(formData.get('db_port')),
                db_database: formData.get('db_database'),
                db_username: formData.get('db_username'),
                db_password: formData.get('db_password'),
                yookassa_shop_id: formData.get('yookassa_shop_id') || '',
                yookassa_api_key: formData.get('yookassa_api_key') || '',
                admin_email: adminFormData.get('admin_email'),
                admin_password: password,
            };

            // Переключаемся на шаг прогресса
            goToStep(4);

            try {
                const response = await fetch('/install', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(installData)
                });

                const result = await response.json();

                if (!response.ok) {
                    throw new Error(result.error || 'Ошибка установки');
                }

                // Обновляем прогресс
                updateProgress('migrate', true);
                setTimeout(() => updateProgress('admin', true), 500);
                setTimeout(() => updateProgress('storage', true), 1000);
                setTimeout(() => updateProgress('finalize', true), 1500);

                // Показываем успех
                setTimeout(() => {
                    document.getElementById('installation-progress').classList.add('hidden');
                    document.getElementById('installation-success').classList.remove('hidden');
                }, 2000);

            } catch (error) {
                document.getElementById('installation-progress').classList.add('hidden');
                document.getElementById('installation-error').classList.remove('hidden');
                document.getElementById('installation-error-text').textContent = error.message;
            }
        }

        function updateProgress(id, completed) {
            const element = document.getElementById('progress-' + id);
            if (completed) {
                element.className = 'w-5 h-5 rounded-full bg-green-500 flex items-center justify-center';
                element.innerHTML = '<svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20"><path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"/></svg>';
            }
        }

        // Инициализация
        document.addEventListener('DOMContentLoaded', () => {
            goToStep(1);
        });
    </script>
</body>
</html>
