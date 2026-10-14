<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Nabilet\Modules\Loyalty\Models\Raffle;

/**
 * Страница результатов розыгрыша — публичное представление (фаза 5).
 *
 * МАСКИРОВАННЫЙ ПОБЕДИТЕЛЬ, А НЕ ОТКРЫТЫЙ
 *   Страница результатов открыта без авторизации: «смотрите, кто выиграл» —
 *   часть механики. Но полный адрес или полное имя победителя в открытом
 *   доступе — это утечка ПДн тем, кто пришёл посмотреть, поэтому наружу
 *   уходит только маска: имя — «Иван П.», адрес — «i***@mail.ru».
 *   Полные координаты победителя есть у организатора в админке.
 *
 * ПОЧЕМУ ЭТО РЕСУРС, А НЕ МАССИВ В КОНТРОЛЛЕРЕ
 *   Маскирование — правило представления, и оно должно быть проверяемым
 *   отдельно (vitest для витрины не видит PHP). Здесь оно живёт одним
 *   методом рядом с формой ответа, а не размазано по шаблонам.
 *
 * @mixin Raffle
 */
class RaffleResultsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $raffle = $this->resource;

        return [
            'public_id' => $raffle->public_id,
            'title' => $raffle->title,
            'description' => $raffle->description,
            'status' => $raffle->status,
            'starts_at' => $raffle->starts_at,
            'ends_at' => $raffle->ends_at,
            'drawn_at' => $raffle->drawn_at,
            // Число участников — публичное: для розыгрыша это не тайна, а
            // доказательство честности («было 412 билетов»).
            'entries_count' => (int) ($raffle->entries_count ?? 0),
            'prizes' => $raffle->orderedPrizes->map(function ($prize) use ($raffle): array {
                $winner = $raffle->winners->firstWhere('raffle_prize_id', $prize->getKey());

                return [
                    'place' => (int) $prize->place,
                    'title' => $prize->title,
                    'description' => $prize->description,
                    // До тиража победителя нет — поле остаётся null, а не
                    // исчезает: форма страницы не должна меняться от статуса.
                    'winner' => $winner === null ? null : [
                        'display' => self::mask($winner->user?->name, $winner->entry?->order?->customer_email),
                        'notified' => $winner->isNotified(),
                    ],
                ];
            })->values(),
        ];
    }

    /**
     * Маска победителя: имя, иначе адрес, иначе «участник».
     *
     * Имя сокращается до «Имя Ф.» — узнаваемо, но не полно. Адрес теряет
     * локальную часть до первой буквы: «i***@mail.ru». Оба варианта
     * намеренно неполные: цель — подтвердить выигрыш, а не предъявить
     * победителя.
     */
    public static function mask(?string $name, ?string $email): string
    {
        if (is_string($name) && trim($name) !== '') {
            $parts = preg_split('/\s+/u', trim($name)) ?: [];

            if ($parts === []) {
                return 'участник';
            }

            $first = array_shift($parts);

            if ($parts === []) {
                return $first;
            }

            $initial = mb_substr($parts[0], 0, 1);

            return $first . ' ' . $initial . '.';
        }

        if (is_string($email) && trim($email) !== '' && str_contains($email, '@')) {
            [$local, $domain] = explode('@', trim($email), 2);

            return mb_substr($local, 0, 1) . '***@' . $domain;
        }

        return 'участник';
    }
}
