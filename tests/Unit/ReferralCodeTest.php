<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Modules\Loyalty\Domain\ReferralCode;
use Nabilet\Modules\Loyalty\Domain\ReferralPolicy;
use Nabilet\Modules\Loyalty\Domain\ReferralReward;
use Nabilet\Tests\Support\TestCase;

/**
 * Реферальная программа (фаза 4): чистые правила кода и награды.
 *
 * БЕЗ БАЗЫ И БЕЗ ФРЕЙМВОРКА — тот же путь, что у раннего доступа
 * (`SessionEarlyAccessTest`): `tests/run.php` не грузит Eloquent, поэтому здесь
 * проверяются ровно те два класса, которые обязаны работать везде — генерация
 * ссылки (`ReferralCode`) и решение о награде (`ReferralPolicy`).
 *
 * ЗДЕСЬ ПРОВЕРЯЮТСЯ ПРАВИЛА, А НЕ ПРИМЕНЕНИЕ
 *   «Кому, когда и сколько начислить» решают `ReferralService` и обсервер — их
 *   проверяет feature-тест на живой базе. Здесь — геометрия кода и границы
 *   награды: то, что нельзя сломать случайной правкой констант.
 */
final class ReferralCodeTest extends TestCase
{
    // ── форма кода ──────────────────────────────────────────────────────────

    public function test_generated_code_matches_the_alphabet_and_length(): void
    {
        for ($i = 0; $i < 50; $i += 1) {
            $code = ReferralCode::generate();

            $this->assertSame(ReferralCode::LENGTH, strlen($code));
            $this->assertTrue(ReferralCode::isWellFormed($code), sprintf('"%s" must be well-formed', $code));
        }
    }

    public function test_alphabet_excludes_visually_similar_pairs(): void
    {
        // `0`/`O` и `1`/`I` отличаются только шрифтом: код с ними теряется при
        // пересылке в мессенджер, а «не пришёл бонус» — это обращение в поддержку.
        foreach (['0', '1', 'O', 'I'] as $glyph) {
            $this->assertFalse(
                str_contains(ReferralCode::ALPHABET, $glyph),
                sprintf('"%s" must not be in the alphabet', $glyph),
            );
        }
        $this->assertSame(32, strlen(ReferralCode::ALPHABET));
    }

    public function test_codes_are_not_repeated_on_generate(): void
    {
        // Алфавит 32, длина 16 → 80 бит энтропии. Совпадение здесь означало бы
        // сломанный random_int, а не невезение, — поэтому сравниваем множество.
        $codes = [];

        for ($i = 0; $i < 200; $i += 1) {
            $codes[ReferralCode::generate()] = true;
        }

        $this->assertCount(200, $codes);
    }

    public function test_normalize_trims_and_uppercases(): void
    {
        // Ссылку копируют из письма и мессенджеров: ` abc123 ` — это `ABC123`,
        // а не другой код. Используем символы алфавита, а не произвольные.
        $this->assertSame('ABCD23456789ABCD', ReferralCode::normalize("  abcd23456789abcd \n"));
        $this->assertSame('ABCD23456789ABCD', ReferralCode::normalize('ABCD23456789ABCD'));
    }

    public function test_well_formed_accepts_only_the_alphabet(): void
    {
        $this->assertTrue(ReferralCode::isWellFormed('ABCDEFGHJKLMNPQR'));

        // Длина: короче и длиннее — не код.
        $this->assertFalse(ReferralCode::isWellFormed('ABCDEFGHJKLMNPQ'));
        $this->assertFalse(ReferralCode::isWellFormed('ABCDEFGHJKLMNPQRS'));

        // Регистр: ссылка хранит верхний, в нижнем кода не существует.
        $this->assertFalse(ReferralCode::isWellFormed('abcdefghjklmnpqr'));

        // Вне алфавита: `0` (как `O`), `1` (как `I`) и кириллица при ручном вводе.
        $this->assertFalse(ReferralCode::isWellFormed('0BCDEFGHJKLMNPQR'));
        $this->assertFalse(ReferralCode::isWellFormed('1BCDEFGHJKLMNPQR'));
        $this->assertFalse(ReferralCode::isWellFormed('АBCDEFGHJKLMNPQR'));
    }

    // ── награда ──────────────────────────────────────────────────────────────

    public function test_reward_clamps_both_amounts_into_range(): void
    {
        // Опечатка «+30000» в админке — это ₽300 000 за приглашение; потолок
        // своё отработает, даже если валидацию обойдут (сид, команда, миграция).
        $reward = ReferralPolicy::reward(true, -500, 999_999_999);

        $this->assertSame(0, $reward->inviterAmount);
        $this->assertSame(ReferralPolicy::MAX_BONUS, $reward->invitedAmount);
    }

    /**
     * `normalize()` — единственный владелец диапазона [0, MAX_BONUS].
     *
     * ЗАЧЕМ ОТДЕЛЬНО. Раньше `max(0, …)` был скопирован в `LoyaltySettingsService`
     * четырежды и БЕЗ потолка — обрезанная копия правила, которое уже жило здесь.
     * Теперь и `reward()`, и сервис настроек зовут этот метод, поэтому тест держит
     * оба конца диапазона и «внутри ничего не меняется» (иначе кламп схлопывал бы
     * нормальные суммы).
     */
    public function test_normalize_owns_the_bonus_range(): void
    {
        // Пол: отрицательная награда — «списать бонусы за друга», такой операции нет.
        $this->assertSame(0, ReferralPolicy::normalize(-1));
        $this->assertSame(0, ReferralPolicy::normalize(-999_999));

        // Потолок: ₽10 000 — предел ручной настройки; выше — опечатка.
        $this->assertSame(ReferralPolicy::MAX_BONUS, ReferralPolicy::normalize(ReferralPolicy::MAX_BONUS + 1));
        $this->assertSame(ReferralPolicy::MAX_BONUS, ReferralPolicy::normalize(999_999_999));

        // Внутри диапазона и на границах — без изменений.
        $this->assertSame(0, ReferralPolicy::normalize(0));
        $this->assertSame(30_000, ReferralPolicy::normalize(30_000));
        $this->assertSame(ReferralPolicy::MAX_BONUS, ReferralPolicy::normalize(ReferralPolicy::MAX_BONUS));
    }

    public function test_reward_keeps_enabled_flag_even_with_zero_amounts(): void
    {
        // «Ноль другу» — это половина выключенной награды, а не выключенная
        // программа: пригласивший всё равно получает свою. Поэтому `enabled`
        // живёт отдельно от сумм.
        $reward = ReferralPolicy::reward(true, 30000, 0);

        $this->assertTrue($reward->paysInviter());
        $this->assertFalse($reward->paysInvited());
        $this->assertFalse($reward->isZero());
    }

    public function test_disabled_program_pays_nobody_even_with_amounts(): void
    {
        $reward = ReferralPolicy::reward(false, 30000, 30000);

        $this->assertFalse($reward->paysInviter());
        $this->assertFalse($reward->paysInvited());
        $this->assertTrue($reward->isZero());
    }

    public function test_zero_amount_reward_means_nothing_to_write(): void
    {
        // Обе суммы по нулю: писать строки журнала не нужно вовсе.
        $reward = new ReferralReward(true, 0, 0);

        $this->assertTrue($reward->isZero());
    }
}
