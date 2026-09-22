<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Сообщения валидатора (ru)
|--------------------------------------------------------------------------
|
| `APP_FALLBACK_LOCALE=ru`, поэтому этот файл — не «перевод ради перевода»:
| без него `__('validation.required')` возвращает сам ключ, и клиент получает
| `{"message":"validation.required"}`. Ключи ниже повторяют набор Laravel 13
| (сверено с `lang/en/validation.php`) — при обновлении фреймворка набор нужно
| сверять заново, иначе новый ключ снова «протечёт» в ответ как строка ключа.
|
| `attributes` переводит имена полей API в человекочитаемые подписи, чтобы
| сообщение было «Поле «Электронная почта» обязательно», а не «email обязательно».
|
*/

return [

    'accepted' => 'Поле «:attribute» должно быть принято.',
    'accepted_if' => 'Поле «:attribute» должно быть принято, когда «:other» равно :value.',
    'active_url' => 'Поле «:attribute» должно содержать корректный URL.',
    'after' => 'Поле «:attribute» должно содержать дату позже :date.',
    'after_or_equal' => 'Поле «:attribute» должно содержать дату позже или равную :date.',
    'alpha' => 'Поле «:attribute» может содержать только буквы.',
    'alpha_dash' => 'Поле «:attribute» может содержать только буквы, цифры, дефисы и подчёркивания.',
    'alpha_num' => 'Поле «:attribute» может содержать только буквы и цифры.',
    'any_of' => 'Поле «:attribute» содержит недопустимое значение.',
    'array' => 'Поле «:attribute» должно быть массивом.',
    'array_keys' => 'Поле «:attribute» должно содержать только следующие ключи: :values.',
    'ascii' => 'Поле «:attribute» может содержать только однобайтовые буквенно-цифровые символы.',
    'base64' => 'Поле «:attribute» должно содержать корректную строку Base64.',
    'before' => 'Поле «:attribute» должно содержать дату раньше :date.',
    'before_or_equal' => 'Поле «:attribute» должно содержать дату раньше или равную :date.',
    'between' => [
        'array' => 'Поле «:attribute» должно содержать от :min до :max элементов.',
        'file' => 'Размер файла «:attribute» должен быть от :min до :max килобайт.',
        'numeric' => 'Значение «:attribute» должно быть от :min до :max.',
        'string' => 'Поле «:attribute» должно содержать от :min до :max символов.',
    ],
    'boolean' => 'Поле «:attribute» должно быть «да» или «нет».',
    'can' => 'Поле «:attribute» содержит недопустимое значение.',
    'confirmed' => 'Подтверждение поля «:attribute» не совпадает.',
    'contains' => 'В поле «:attribute» отсутствует обязательное значение.',
    'current_password' => 'Неверный пароль.',
    'date' => 'Поле «:attribute» должно содержать корректную дату.',
    'date_equals' => 'Поле «:attribute» должно содержать дату, равную :date.',
    'date_format' => 'Поле «:attribute» должно соответствовать формату :format.',
    'decimal' => 'Поле «:attribute» должно содержать :decimal знаков после запятой.',
    'declined' => 'Поле «:attribute» должно быть отклонено.',
    'declined_if' => 'Поле «:attribute» должно быть отклонено, когда «:other» равно :value.',
    'different' => 'Поля «:attribute» и «:other» должны различаться.',
    'digits' => 'Поле «:attribute» должно содержать :digits цифр.',
    'digits_between' => 'Поле «:attribute» должно содержать от :min до :max цифр.',
    'dimensions' => 'Изображение «:attribute» имеет недопустимые размеры.',
    'distinct' => 'Поле «:attribute» содержит повторяющееся значение.',
    'doesnt_contain' => 'Поле «:attribute» не должно содержать ни одного из значений: :values.',
    'doesnt_end_with' => 'Поле «:attribute» не должно заканчиваться ни одним из значений: :values.',
    'doesnt_start_with' => 'Поле «:attribute» не должно начинаться ни с одного из значений: :values.',
    'email' => 'Поле «:attribute» должно содержать корректный адрес электронной почты.',
    'encoding' => 'Поле «:attribute» должно быть в кодировке :encoding.',
    'ends_with' => 'Поле «:attribute» должно заканчиваться одним из значений: :values.',
    'enum' => 'Выбранное значение «:attribute» недопустимо.',
    'exists' => 'Выбранное значение «:attribute» недопустимо.',
    'extensions' => 'Поле «:attribute» должно иметь одно из расширений: :values.',
    'file' => 'Поле «:attribute» должно быть файлом.',
    'filled' => 'Поле «:attribute» должно иметь значение.',
    'gt' => [
        'array' => 'Поле «:attribute» должно содержать более :value элементов.',
        'file' => 'Размер файла «:attribute» должен быть больше :value килобайт.',
        'numeric' => 'Значение «:attribute» должно быть больше :value.',
        'string' => 'Поле «:attribute» должно содержать более :value символов.',
    ],
    'gte' => [
        'array' => 'Поле «:attribute» должно содержать :value элементов или более.',
        'file' => 'Размер файла «:attribute» должен быть не меньше :value килобайт.',
        'numeric' => 'Значение «:attribute» должно быть больше или равно :value.',
        'string' => 'Поле «:attribute» должно содержать :value символов или более.',
    ],
    'hex_color' => 'Поле «:attribute» должно содержать корректный шестнадцатеричный цвет.',
    'image' => 'Поле «:attribute» должно быть изображением.',
    'in' => 'Выбранное значение «:attribute» недопустимо.',
    'in_array' => 'Поле «:attribute» должно присутствовать в «:other».',
    'in_array_keys' => 'Поле «:attribute» должно содержать хотя бы один из ключей: :values.',
    'integer' => 'Поле «:attribute» должно быть целым числом.',
    'ip' => 'Поле «:attribute» должно содержать корректный IP-адрес.',
    'ipv4' => 'Поле «:attribute» должно содержать корректный IPv4-адрес.',
    'ipv6' => 'Поле «:attribute» должно содержать корректный IPv6-адрес.',
    'json' => 'Поле «:attribute» должно содержать корректную строку JSON.',
    'list' => 'Поле «:attribute» должно быть списком.',
    'lowercase' => 'Поле «:attribute» должно быть в нижнем регистре.',
    'lt' => [
        'array' => 'Поле «:attribute» должно содержать менее :value элементов.',
        'file' => 'Размер файла «:attribute» должен быть меньше :value килобайт.',
        'numeric' => 'Значение «:attribute» должно быть меньше :value.',
        'string' => 'Поле «:attribute» должно содержать менее :value символов.',
    ],
    'lte' => [
        'array' => 'Поле «:attribute» должно содержать не более :value элементов.',
        'file' => 'Размер файла «:attribute» должен быть не больше :value килобайт.',
        'numeric' => 'Значение «:attribute» должно быть меньше или равно :value.',
        'string' => 'Поле «:attribute» должно содержать не более :value символов.',
    ],
    'mac_address' => 'Поле «:attribute» должно содержать корректный MAC-адрес.',
    'max' => [
        'array' => 'Поле «:attribute» должно содержать не более :max элементов.',
        'file' => 'Размер файла «:attribute» должен быть не больше :max килобайт.',
        'numeric' => 'Значение «:attribute» должно быть не больше :max.',
        'string' => 'Поле «:attribute» должно содержать не более :max символов.',
    ],
    'max_digits' => 'Поле «:attribute» должно содержать не более :max цифр.',
    'mimes' => 'Файл «:attribute» должен быть одного из типов: :values.',
    'mimetypes' => 'Файл «:attribute» должен быть одного из типов: :values.',
    'min' => [
        'array' => 'Поле «:attribute» должно содержать не менее :min элементов.',
        'file' => 'Размер файла «:attribute» должен быть не менее :min килобайт.',
        'numeric' => 'Значение «:attribute» должно быть не менее :min.',
        'string' => 'Поле «:attribute» должно содержать не менее :min символов.',
    ],
    'min_digits' => 'Поле «:attribute» должно содержать не менее :min цифр.',
    'missing' => 'Поле «:attribute» должно отсутствовать.',
    'missing_if' => 'Поле «:attribute» должно отсутствовать, когда «:other» равно :value.',
    'missing_unless' => 'Поле «:attribute» должно отсутствовать, если «:other» не равно :value.',
    'missing_with' => 'Поле «:attribute» должно отсутствовать при наличии :values.',
    'missing_with_all' => 'Поле «:attribute» должно отсутствовать при наличии :values.',
    'multiple_of' => 'Значение «:attribute» должно быть кратно :value.',
    'not_in' => 'Выбранное значение «:attribute» недопустимо.',
    'not_regex' => 'Формат поля «:attribute» недопустим.',
    'numeric' => 'Поле «:attribute» должно быть числом.',
    'password' => [
        'letters' => 'Поле «:attribute» должно содержать хотя бы одну букву.',
        'mixed' => 'Поле «:attribute» должно содержать хотя бы одну заглавную и одну строчную букву.',
        'numbers' => 'Поле «:attribute» должно содержать хотя бы одну цифру.',
        'symbols' => 'Поле «:attribute» должно содержать хотя бы один специальный символ.',
        'uncompromised' => 'Указанное значение «:attribute» обнаружено в утечке данных. Выберите другое.',
    ],
    'present' => 'Поле «:attribute» должно присутствовать.',
    'present_if' => 'Поле «:attribute» должно присутствовать, когда «:other» равно :value.',
    'present_unless' => 'Поле «:attribute» должно присутствовать, если «:other» не равно :value.',
    'present_with' => 'Поле «:attribute» должно присутствовать при наличии :values.',
    'present_with_all' => 'Поле «:attribute» должно присутствовать при наличии :values.',
    'prohibited' => 'Поле «:attribute» запрещено.',
    'prohibited_if' => 'Поле «:attribute» запрещено, когда «:other» равно :value.',
    'prohibited_if_accepted' => 'Поле «:attribute» запрещено, когда «:other» принято.',
    'prohibited_if_declined' => 'Поле «:attribute» запрещено, когда «:other» отклонено.',
    'prohibited_unless' => 'Поле «:attribute» запрещено, если «:other» не входит в :values.',
    'prohibits' => 'Поле «:attribute» запрещает присутствие «:other».',
    'regex' => 'Формат поля «:attribute» недопустим.',
    'required' => 'Поле «:attribute» обязательно для заполнения.',
    'required_array_keys' => 'Поле «:attribute» должно содержать записи для: :values.',
    'required_if' => 'Поле «:attribute» обязательно, когда «:other» равно :value.',
    'required_if_accepted' => 'Поле «:attribute» обязательно, когда «:other» принято.',
    'required_if_declined' => 'Поле «:attribute» обязательно, когда «:other» отклонено.',
    'required_unless' => 'Поле «:attribute» обязательно, если «:other» не входит в :values.',
    'required_with' => 'Поле «:attribute» обязательно при наличии :values.',
    'required_with_all' => 'Поле «:attribute» обязательно при наличии :values.',
    'required_without' => 'Поле «:attribute» обязательно, когда :values отсутствует.',
    'required_without_all' => 'Поле «:attribute» обязательно, когда ни одно из :values не указано.',
    'same' => 'Поля «:attribute» и «:other» должны совпадать.',
    'size' => [
        'array' => 'Поле «:attribute» должно содержать :size элементов.',
        'file' => 'Размер файла «:attribute» должен быть :size килобайт.',
        'numeric' => 'Значение «:attribute» должно быть равно :size.',
        'string' => 'Поле «:attribute» должно содержать :size символов.',
    ],
    'starts_with' => 'Поле «:attribute» должно начинаться с одного из значений: :values.',
    'string' => 'Поле «:attribute» должно быть строкой.',
    'timezone' => 'Поле «:attribute» должно содержать корректный часовой пояс.',
    'unique' => 'Такое значение «:attribute» уже занято.',
    'uploaded' => 'Не удалось загрузить файл «:attribute».',
    'uppercase' => 'Поле «:attribute» должно быть в верхнем регистре.',
    'url' => 'Поле «:attribute» должно содержать корректный URL.',
    'ulid' => 'Поле «:attribute» должно содержать корректный ULID.',
    'uuid' => 'Поле «:attribute» должно содержать корректный UUID.',

    /*
    |--------------------------------------------------------------------------
    | Собственные сообщения для конкретных полей
    |--------------------------------------------------------------------------
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Подписи полей
    |--------------------------------------------------------------------------
    |
    | Ключи совпадают с именами полей API (`session_id`, `inventory_item_id`, …),
    | чтобы сообщение читалось как «Поле «Сессия» обязательно», а не
    | «session_id обязательно».
    |
    */

    'attributes' => [
        'email' => 'Электронная почта',
        'password' => 'Пароль',
        'password_confirmation' => 'Подтверждение пароля',
        'current_password' => 'Текущий пароль',
        'token' => 'Токен',
        'first_name' => 'Имя',
        'last_name' => 'Фамилия',
        'phone' => 'Телефон',
        'locale' => 'Язык',
        'session_id' => 'Сессия',
        'inventory_item_id' => 'Место',
        'quantity' => 'Количество',
        'device_id' => 'Устройство',
        'ticket_id' => 'Билет',
        'user_id' => 'Пользователь',
        'role' => 'Роль',
        'public_id' => 'Публичный идентификатор',
        'limit' => 'Лимит',
        'page' => 'Страница',
        'per_page' => 'Записей на странице',
        'event_id' => 'Мероприятие',
        'venue_id' => 'Площадка',
        'order_id' => 'Заказ',
        'payment_id' => 'Платёж',
        'promo_code' => 'Промокод',
        'provider' => 'Провайдер',
        'status' => 'Статус',
    ],

];
