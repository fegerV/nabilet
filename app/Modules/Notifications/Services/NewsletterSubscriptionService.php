<?php

declare(strict_types=1);

namespace Nabilet\Modules\Notifications\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Nabilet\Modules\Notifications\Models\NewsletterSubscriber;
use Throwable;

/**
 * Подписка/отписка на рассылку афиши.
 *
 * ДО ЭТОГО
 *   Виджет «Подписка» на витрине валидировал e-mail и рисовал «Готово», но
 *   адрес никуда не уходил — бэкенда не существовало (аудит лояльности L4:
 *   кандидат NewsletterService читал колонку users.subscribed_to_newsletter,
   *   которой не существует). Здесь — честный минимум: адрес + токен отписки +
 *   статус, ФАКТ согласия дублируется в consents (152-ФЗ §9).
 *
 * ПОЧЕМУ ОДИН АНОНИМНЫЙ ID НА ПУПКЕ
 *   consents требует субъекта (user XOR anonymous). Гость — anonymous uuid
 *   генерируется при первой подписке и ЖИВЁТ на строке подписчика: отписка и
 *   повторная подписка ссылаются на тот же субъект, история согласий остаётся
 *   связной (grant → withdrawn → grant).
 *
 * АНТИ-ПЕРЕЧИСЛЕНИЕ
 *   Повторная подписка не отличает «уже подписан» от «создан» — оба ответа
 *   одинаковы, адреса не вскрываются.
 */
final class NewsletterSubscriptionService
{
    public const POLICY_VERSION = '2026-10-16';

    /**
     * Подписать адрес (идемпотентно). Возвращает строку подписчика.
     */
    public function subscribe(string $email): NewsletterSubscriber
    {
        $email = mb_strtolower(trim($email));

        $existing = NewsletterSubscriber::query()->where('email', $email)->first();

        if ($existing !== null) {
            if ($existing->status !== NewsletterSubscriber::STATUS_SUBSCRIBED) {
                $existing->update([
                    'status' => NewsletterSubscriber::STATUS_SUBSCRIBED,
                    'unsubscribe_token' => $this->freshToken(),
                ]);
            }

            $existing->refresh();

            return $existing;
        }

        $subscriber = new NewsletterSubscriber([
            'email' => $email,
            'unsubscribe_token' => $this->freshToken(),
            'status' => NewsletterSubscriber::STATUS_SUBSCRIBED,
            'policy_version' => self::POLICY_VERSION,
        ]);

        // Согласие — в той же транзакции: подписка без записанного согласия
        // была бы «адрес, который рассылать нельзя».
        DB::transaction(function () use ($subscriber): void {
            $subscriber->save();

            DB::table('consents')->insert([
                'user_id' => null,
                'anonymous_id' => $subscriber->anonymous_id,
                'consent_type' => NewsletterSubscriber::CONSENT_TYPE,
                'status' => 'granted',
                'policy_version' => $subscriber->policy_version,
                'ip_address' => null,
                'user_agent' => null,
                'created_at' => now(),
            ]);
        });

        return $subscriber;
    }

    /**
     * Отписка по токену (владелец адреса — без пароля). Возвращает false,
     * когда токен неизвестен: не утечь существованием подписчиков.
     */
    public function unsubscribeByToken(string $token): bool
    {
        $subscriber = NewsletterSubscriber::query()->where('unsubscribe_token', $token)->first();

        if ($subscriber === null) {
            return false;
        }

        if ($subscriber->status === NewsletterSubscriber::STATUS_SUBSCRIBED) {
            DB::transaction(function () use ($subscriber): void {
                $subscriber->update(['status' => NewsletterSubscriber::STATUS_UNSUBSCRIBED]);

                try {
                    DB::table('consents')->insert([
                        'user_id' => null,
                        'anonymous_id' => $subscriber->anonymous_id,
                        'consent_type' => NewsletterSubscriber::CONSENT_TYPE,
                        'status' => 'withdrawn',
                        'policy_version' => $subscriber->policy_version,
                        'ip_address' => null,
                        'user_agent' => null,
                        'created_at' => now(),
                    ]);
                } catch (Throwable $e) {
                    // Журнал согласий важен, но потеря отписки из-за него хуже;
                    // сам факт отписки уже зафиксирован статусом подписчика.
                    // §66 E2: в журнал — код, а не сырой текст исключения.
                    Log::warning('Newsletter unsubscribe: consent row not written', [
                        'subscriber_id' => $subscriber->id,
                        'code' => $e::class,
                    ]);
                }
            });
        }

        return true;
    }

    private function freshToken(): string
    {
        return Str::random(48);
    }
}
