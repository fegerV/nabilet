<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Halls\Domain;

use stdClass;

/**
 * Приведение payload схемы зала к форме, описанной в ТЗ §54.
 *
 * Проблема, которую решает класс, — классическая ловушка PHP+JSON:
 * пустой ассоциативный массив `[]` кодируется как JSON-массив `[]`, а не как
 * объект `{}`. Редактор присылает `rowPrices: {}` (карта «номер ряда → цена»),
 * `json_decode(..., true)` превращает это в пустой PHP-массив, а `json_encode`
 * отдаёт обратно `[]` — то есть ОДНО И ТО ЖЕ поле в одном зале приходит
 * объектом, а в другом массивом, в зависимости от того, заданы ли цены по
 * рядам. Любой строгий потребитель (импортёр афиши, валидатор по JSON-схеме,
 * типизированный клиент) на этом ломается.
 *
 * Класс идемпотентен и не мутирует вход: возвращает новую структуру, где
 * перечисленные поля-карты всегда представлены объектом.
 */
final class SchemaPayloadNormalizer
{
    /**
     * Поля сектора, которые по контракту являются картами (JSON-объектами),
     * а не списками. Ключ — имя поля, значение — приводимый тип.
     */
    private const SECTOR_MAP_FIELDS = ['rowPrices'];

    /**
     * Нормализовать payload для записи в БД и для ответа API.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function normalize(array $payload): array
    {
        $sectors = $payload['sectors'] ?? null;
        if (!is_array($sectors)) {
            return $payload;
        }

        $normalized = [];
        foreach ($sectors as $key => $sector) {
            if (!is_array($sector)) {
                $normalized[$key] = $sector;
                continue;
            }

            foreach (self::SECTOR_MAP_FIELDS as $field) {
                if (!array_key_exists($field, $sector)) {
                    continue;
                }

                $value = $sector[$field];
                if ($value instanceof stdClass) {
                    continue;
                }

                // Пустая карта обязана стать объектом; непустая ассоциативная
                // карта кодируется объектом и так, но приводим единообразно,
                // чтобы форма не зависела от набора ключей.
                $sector[$field] = (object) (is_array($value) ? $value : []);
            }

            $normalized[$key] = $sector;
        }

        $payload['sectors'] = $normalized;

        return $payload;
    }
}
