<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Пароль не должен быть производным от личных данных пользователя.
 *
 * ЗАЧЕМ. `Password::min(12)->mixedCase()->numbers()->symbols()` отлично отсекает
 * `qwerty` и `12345678`, но пропускает `Ivanov-2026!` и `ivan.petrov@corp.ru` —
 * а именно такие пароли люди ставят, когда политика требует «сложности». Для
 * атакующего это лучший случай: цель известна заранее (e-mail виден в форме
 * входа), и словарь строится из имени, фамилии и домена компании, а не из
 * топ-10k утечек. Проверка на утечки здесь не помогает — такой пароль в базах
 * ещё не встречается, потому что придуман только что.
 *
 * ЧТО СРАВНИВАЕТСЯ. Локальная часть e-mail (`ivan.petrov` из
 * `ivan.petrov@corp.ru`), сам e-mail целиком, имя и фамилия — если они есть в
 * том же запросе. На регистрации они есть всегда; на смене пароля администратором
 * (`UserController::update`) — только если администратор передал их вместе с
 * паролем, иначе проверка честно ограничивается e-mail.
 *
 * ПОРОГ В 3 СИМВОЛА. Короткие имена («Ян», «Ли») дают ложные срабатывания:
 * пароль `K7#mYan2!q` содержит «yan» случайно. Порог в 3 символа отсекает
 * совпадения по одной-двум буквам, оставляя осмысленные.
 *
 * РЕГИСТР НЕ УЧИТЫВАЕТСЯ: пароль `IVANOV-2026!` не сложнее `ivanov-2026!`, и
 * требовать от пользователя регистр в этом сравнении значило бы просто
 * подсказать обходной путь.
 */
class NotPersonalData implements ValidationRule
{
    /**
     * Поля запроса, которые считаются «личными данными» для этой проверки.
     *
     * @var list<string>
     */
    private const PERSONAL_FIELDS = ['email', 'first_name', 'last_name', 'name'];

    /**
     * Минимальная длина совпадения. Короче — почти всегда ложное срабатывание.
     */
    private const MIN_NEEDLE_LENGTH = 3;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            // Пустое значение — забота правил `required`/`nullable`, а не этой
            // проверки. Молча выходим, чтобы не выдавать два сообщения на одно поле.
            return;
        }

        $haystack = mb_strtolower($value);

        foreach ($this->personalNeedles() as $needle) {
            if (mb_strlen($needle) < self::MIN_NEEDLE_LENGTH) {
                continue;
            }

            if (str_contains($haystack, $needle)) {
                $fail('Пароль не должен содержать e-mail или имя пользователя.');

                return;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function personalNeedles(): array
    {
        $needles = [];

        foreach (self::PERSONAL_FIELDS as $field) {
            $raw = $this->input($field);

            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }

            $needles[] = mb_strtolower(trim($raw));

            if ($field === 'email' && str_contains($raw, '@')) {
                // Локальная часть — самое частое производное («ivan.petrov»).
                $localPart = strstr($raw, '@', true);

                if (is_string($localPart) && trim($localPart) !== '') {
                    $needles[] = mb_strtolower(trim($localPart));
                }
            }
        }

        return $needles;
    }

    /**
     * Значение поля из текущего запроса.
     *
     * Через `request()`, а не через инъекцию данных в конструктор: правило
     * применяется и в `FormRequest` (`RegisterRequest`), и в inline-валидации
     * контроллера (`UserController::store/update`) — во втором случае передать
     * данные в конструктор неоткуда, а дублировать правило ради этого не стоит.
     * `function_exists` — потому что правило может быть инстанцировано в
     * окружении без загруженного фреймворка (например, при разборе правил в
     * тестах), и падать на этом оно не должно.
     */
    private function input(string $field): ?string
    {
        if (! function_exists('request')) {
            return null;
        }

        $value = request()->input($field);

        return is_string($value) ? $value : null;
    }
}
