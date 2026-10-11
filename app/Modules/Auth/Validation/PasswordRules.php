<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Validation;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Nabilet\Modules\Auth\Validation\Rules\NotPersonalData;

/**
 * Единая политика паролей проекта.
 *
 * ПОЧЕМУ ЭТО ОТДЕЛЬНЫЙ КЛАСС. До него правила были разными в трёх местах и ни в
 * одном не проверяли сложность:
 *
 *   - `RegisterRequest`            — `min:12` + `confirmed`;
 *   - `UserController::store()`    — `min:8`;
 *   - `UserController::update()`   — `min:8`.
 *
 * То есть покупатель регистрировался с длинным паролем, а учётную запись
 * администратора — самую ценную в системе — можно было создать с `qwertyui1`.
 * Три копии одного правила расходятся именно так: каждую правят отдельно, и
 * «неудобную» (админскую) ослабляют первой.
 *
 * ПОЧЕМУ `min(12)`. Восемь символов — это верхняя граница для офлайн-перебора на
 * бытовой видеокарте; двенадцать уже делают его невыгодным. Требования к составу
 * (`mixedCase`, `numbers`, `symbols`) добавлены не ради энтропии сами по себе, а
 * чтобы отсечь словарные пароли и `12345678901`.
 *
 * ПРОВЕРКА УТЕЧЕК ПО УМОЛЧАНИЮ ВЫКЛЮЧЕНА — и это осознанное решение, а не
 * недоделка. `uncompromised()` ходит в API HaveIBeenPwned на КАЖДУЮ валидацию
 * пароля: это внешний сетевой вызов внутри обработки запроса. На включённом по
 * умолчанию он означал бы, что регистрация зависит от доступности чужого сервиса,
 * а тесты — от наличия интернета. Laravel при ошибке сети считает пароль
 * «чистым» (fail-open), поэтому включение — вопрос деплоя, а не кода:
 * `NABILET_PASSWORD_BREACH_CHECK=true` в `.env`.
 */
final class PasswordRules
{
    /**
     * Минимальная длина. Одна константа на весь проект: если политику поднимут,
     * это должно быть одно изменение, а не поиск `min:8` по коду.
     */
    public const MIN_LENGTH = 12;

    /**
     * Правила для обязательного поля пароля (регистрация, создание пользователя).
     *
     * @return array<int, mixed>
     */
    public static function required(): array
    {
        return ['required', 'string', self::policy(), new NotPersonalData()];
    }

    /**
     * Правила для необязательного поля пароля (обновление пользователя).
     *
     * «НЕ МЕНЯТЬ» — это ТРИ разных случая, и все три обязаны работать одинаково:
     * поле отсутствует, поле `null`, поле пустая строка. Форма админки всегда
     * отправляет поле и при нетронутом input шлёт `''`, поэтому считать пустую
     * строку ошибкой нельзя — администратор получал бы 422 на каждой правке
     * любого другого поля.
     *
     * ПОЧЕМУ `excludeIf`, А НЕ `nullable`. `nullable` пропускает пустое значение
     * через остальные правила, но оставляет КЛЮЧ в `validated()` — и `null`
     * доходил до `$user->update()`. На NOT NULL колонке `users.password` это
     * записывало пустую строку: администратор, отправивший форму без нового
     * пароля, СТИРАЛ пароль пользователя. `excludeIf` убирает ключ из данных
     * целиком (`Validator::removeAttribute()` забывает атрибут и в `data`, и в
     * `rules`), поэтому дальше он не проходит ни одного слоя — ни записи, ни
     * расчёта «надо ли отзывать сессии». Проверено тестом
     * `PasswordPolicyTest::test_an_empty_password_on_update_does_not_change_the_password`.
     *
     * @return array<int, mixed>
     */
    public static function optional(): array
    {
        return [
            'sometimes',
            // `ExcludeIf` вызывает условие БЕЗ аргументов — значение приходит не
            // параметром, а из запроса (`ExcludeIf::__toString()` вычисляется при
            // разборе правил, внутри валидатора, где запрос уже есть).
            Rule::excludeIf(static fn (): bool => self::isBlankPassword()),
            'nullable',
            'string',
            self::policy(),
            new NotPersonalData(),
        ];
    }

    /**
     * Поле пароля пустое или отсутствует — то есть «пароль не меняется».
     *
     * Проверка идёт по сырому вводу запроса, а не по `validated()`: смысл в том,
     * чтобы решить это ДО остальных правил и убрать ключ из данных целиком.
     * Массив в поле пароля — не «пусто», поэтому он доходит до политики и
     * получает штатный 422.
     */
    private static function isBlankPassword(): bool
    {
        if (! function_exists('request')) {
            return false;
        }

        $value = request()->input('password');

        return $value === null || $value === '';
    }

    /**
     * Собственно политика сложности — одна на все случаи.
     *
     * Возвращается объектом `Password`, а не строкой `min:12|...`: объект
     * неизменяемый и собирается по шагам, поэтому невозможно случайно собрать
     * «двенадцать символов, но без проверки состава», как это получается со
     * строкой, где один `|` легко потерять при правке.
     */
    private static function policy(): Password
    {
        $policy = Password::min(self::MIN_LENGTH)
            ->mixedCase()
            ->numbers()
            ->symbols();

        if ((bool) config('nabilet.auth.password.check_breaches', false)) {
            $policy = $policy->uncompromised();
        }

        return $policy;
    }

    private function __construct()
    {
    }
}
