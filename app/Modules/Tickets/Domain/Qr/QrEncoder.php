<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Domain\Qr;

/**
 * QR-кодировщик на чистом PHP: байтовый режим, уровень коррекции M.
 *
 * ЗАЧЕМ СВОЙ, А НЕ ПАКЕТ
 *
 * `qr_payload` билета — это `NB1.<publicId>.<token>.<signature>`, то есть
 * подписанный пропуск на вход. Отдавать его стороннему сервису генерации
 * картинок нельзя: это ровно та утечка, из-за которой из проекта убрали
 * `api.qrserver.com` (см. `resources/js/lib/qr.ts`). Значит код обязан
 * рисоваться внутри процесса. В `vendor/` нет ни одной библиотеки QR, а
 * Composer в этой среде без сети, поэтому кодировщик живёт здесь.
 *
 * ПОЧЕМУ ЭТО ВООБЩЕ ПРОВЕРЯЕМО
 *
 * Написать QR «на глаз» нельзя: любая ошибка в таблицах Reed-Solomon, в
 * раскладке модулей или в масках даёт картинку, которая ВЫГЛЯДИТ как QR, но не
 * читается сканером. Ошибка тихая — письмо уходит, картинка на месте, а
 * покупатель узнаёт о проблеме на входе.
 *
 * Поэтому кодировщик сверяется с независимой реализацией: npm-пакет `qrcode`
 * (та же библиотека, что рисует QR в браузере) отдаёт свою матрицу модулей, и
 * тест `QrEncoderGoldenMasterTest` требует ПОБИТОВОГО совпадения. Это
 * превращает «мне кажется, я правильно помню таблицы» в проверяемый факт.
 *
 * ГРАНИЦЫ ПОДДЕРЖКИ (осознанные, а не «пока не доделано»)
 *
 *  - только байтовый режим: payload — ASCII, экономить на цифровом/буквенном
 *    режимах нечего;
 *  - только уровень M (~15 % коррекции): компромисс между плотностью кода и
 *    устойчивостью к блику и трещине на экране;
 *  - версии 1..10. `NB1.` + ULID + подпись укладываются в версию 4-6, то есть
 *    запас более чем двукратный. Если payload всё же не влез, бросается
 *    `QrCapacityException` — вызывающий код обязан откатиться на текстовый
 *    payload, а не отправить письмо без билета.
 */
final class QrEncoder
{
    /** Уровень коррекции M: 2-битный индикатор `00`. */
    private const EC_LEVEL_INDICATOR = 0b00;

    /** Байтовый режим: 4-битный индикатор `0100`. */
    private const MODE_BYTE = 0b0100;

    /** Старшая поддерживаемая версия. */
    public const MAX_VERSION = 10;

    /**
     * Структура блоков Reed-Solomon для уровня M.
     *
     * `версия => [ecCodewordsPerBlock, [блоков, dataCodewords], ...]`
     *
     * Вторая и третья группы существуют не для красоты: у версий 8+ данные
     * делятся на блоки РАЗНОГО размера, и разница в один кодоворд — не
     * опечатка. Общее число кодовордов версии = сумма (блоков × dataCodewords)
     * + (блоков × ecCodewords).
     */
    private const RS_BLOCKS_M = [
        1 => [10, [1, 16]],
        2 => [16, [1, 28]],
        3 => [26, [1, 44]],
        4 => [18, [2, 32]],
        5 => [24, [2, 43]],
        6 => [16, [4, 27]],
        7 => [18, [4, 31]],
        8 => [22, [2, 38], [2, 39]],
        9 => [22, [3, 36], [2, 37]],
        10 => [26, [4, 43], [1, 44]],
    ];

    /** Центры alignment-паттернов; 6 не входит — он часть тайминга. */
    private const ALIGNMENT_CENTERS = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /** Полином GF(256), по которому строится арифметика Reed-Solomon. */
    private const GF_PRIMITIVE = 0x11D;

    /** @var list<int>|null экспоненциальная таблица GF(256) */
    private static ?array $exp = null;

    /** @var list<int>|null таблица логарифмов GF(256) */
    private static ?array $log = null;

    private function __construct() {}

    /**
     * Матрица модулей для payload.
     *
     * @return array{version:int, size:int, rows:list<list<bool>>}
     *
     * @throws QrCapacityException если payload не влезает в версию MAX_VERSION
     */
    public static function encode(string $payload): array
    {
        $version = self::smallestVersion(strlen($payload));

        if ($version === null) {
            throw new QrCapacityException(sprintf(
                'Payload из %d байт не влезает в QR версии %d (уровень M, максимум %d байт).',
                strlen($payload),
                self::MAX_VERSION,
                self::dataCapacityBytes(self::MAX_VERSION) - self::headerBytes(self::MAX_VERSION),
            ));
        }

        $size = 17 + 4 * $version;
        $codewords = self::interleave(self::dataCodewords($payload, $version), $version);

        [$matrix, $reserved] = self::functionPatterns($version, $size);

        self::placeData($matrix, $reserved, $codewords, $size);
        self::applyBestMask($matrix, $reserved, $size, $version);

        return ['version' => $version, 'size' => $size, 'rows' => $matrix];
    }

    // ── Версия и ёмкость ────────────────────────────────────────────────────

    /** Меньшая версия, в которую влезает payload, либо `null`. */
    private static function smallestVersion(int $byteLength): ?int
    {
        $neededBits = 4 + self::countBits(1) + 8 * $byteLength;

        for ($version = 1; $version <= self::MAX_VERSION; $version++) {
            // Размер индикатора длины зависит от версии, поэтому пересчитываем.
            $neededBits = 4 + self::countBits($version) + 8 * $byteLength;

            if ($neededBits <= self::dataCapacityBytes($version) * 8) {
                return $version;
            }
        }

        return null;
    }

    /** Биты в индикаторе длины для байтового режима. */
    private static function countBits(int $version): int
    {
        return $version <= 9 ? 8 : 16;
    }

    /** Кодовордов, доступных под данные (без коррекции). */
    private static function dataCapacityBytes(int $version): int
    {
        $blocks = self::RS_BLOCKS_M[$version];
        array_shift($blocks);

        $total = 0;

        foreach ($blocks as [$count, $dataCodewords]) {
            $total += $count * $dataCodewords;
        }

        return $total;
    }

    /** Байт, которые съедает заголовок (режим + индикатор длины) в худшем случае. */
    private static function headerBytes(int $version): int
    {
        return (int) ceil((4 + self::countBits($version)) / 8);
    }

    // ── Кодовые слова ───────────────────────────────────────────────────────

    /**
     * Данные + выравнивающие байты, до длины ёмкости.
     *
     * @return list<int>
     */
    private static function dataCodewords(string $payload, int $version): array
    {
        $capacity = self::dataCapacityBytes($version);
        $bits = [];

        $append = static function (int $value, int $width) use (&$bits): void {
            for ($i = $width - 1; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        };

        $append(self::MODE_BYTE, 4);
        $append(strlen($payload), self::countBits($version));

        for ($i = 0, $len = strlen($payload); $i < $len; $i++) {
            $append(ord($payload[$i]), 8);
        }

        // Терминатор: до четырёх нулей, но не длиннее остатка ёмкости.
        $capacityBits = $capacity * 8;
        $terminator = min(4, $capacityBits - count($bits));

        for ($i = 0; $i < $terminator; $i++) {
            $bits[] = 0;
        }

        // До границы байта.
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $codewords = [];

        for ($i = 0, $count = count($bits); $i < $count; $i += 8) {
            $byte = 0;

            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | $bits[$i + $j];
            }

            $codewords[] = $byte;
        }

        // Заполнители строго чередуются, начиная с 0xEC.
        $pads = [0xEC, 0x11];
        $padIndex = 0;

        while (count($codewords) < $capacity) {
            $codewords[] = $pads[$padIndex % 2];
            $padIndex++;
        }

        return $codewords;
    }

    /**
     * Блоки данных с коррекцией, перемешанные в порядке записи.
     *
     * Перемешивание обязательно: сканер читает кодоворды в порядке чередования
     * блоков, поэтому «просто дописать коррекцию в конец» даёт код, который
     * декодируется только при отсутствии ошибок — то есть ровно тогда, когда
     * коррекция не нужна.
     *
     * @param  list<int>  $dataCodewords
     * @return list<int>
     */
    private static function interleave(array $dataCodewords, int $version): array
    {
        $spec = self::RS_BLOCKS_M[$version];
        $ecPerBlock = $spec[0];
        $groups = array_slice($spec, 1);

        $dataBlocks = [];
        $ecBlocks = [];
        $offset = 0;

        foreach ($groups as [$blockCount, $dataCount]) {
            for ($b = 0; $b < $blockCount; $b++) {
                $block = array_slice($dataCodewords, $offset, $dataCount);
                $offset += $dataCount;

                $dataBlocks[] = $block;
                $ecBlocks[] = self::reedSolomon($block, $ecPerBlock);
            }
        }

        $result = [];
        $maxData = max(array_map('count', $dataBlocks));

        // Сначала по одному кодоворду данных из каждого блока, по кругу…
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        // …затем так же коррекция.
        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $block) {
                $result[] = $block[$i];
            }
        }

        return $result;
    }

    /**
     * Кодоворды коррекции для блока (полиномиальное деление в GF(256)).
     *
     * @param  list<int>  $data
     * @return list<int>
     */
    private static function reedSolomon(array $data, int $ecCount): array
    {
        self::initGaloisField();

        $generator = self::generatorPolynomial($ecCount);
        $remainder = array_merge($data, array_fill(0, $ecCount, 0));
        $dataCount = count($data);

        for ($i = 0; $i < $dataCount; $i++) {
            $factor = $remainder[$i];

            if ($factor === 0) {
                continue;
            }

            // Вычитание в GF(2^n) — это XOR, знак не важен.
            for ($j = 0, $gCount = count($generator); $j < $gCount; $j++) {
                $remainder[$i + $j] ^= self::gfMultiply($generator[$j], $factor);
            }
        }

        return array_slice($remainder, $dataCount);
    }

    /**
     * Порождающий полином степени `$degree`.
     *
     * @return list<int>
     */
    private static function generatorPolynomial(int $degree): array
    {
        $generator = [1];

        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($generator) + 1, 0);

            foreach ($generator as $j => $coefficient) {
                // Умножение на x сдвигает полином на разряд…
                $next[$j] ^= $coefficient;
                // …а на α^i — добавляет произведение в следующий разряд.
                $next[$j + 1] ^= self::gfMultiply($coefficient, self::$exp[$i]);
            }

            $generator = $next;
        }

        return $generator;
    }

    private static function initGaloisField(): void
    {
        if (self::$exp !== null) {
            return;
        }

        self::$exp = array_fill(0, 512, 0);
        self::$log = array_fill(0, 256, 0);

        $x = 1;

        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;

            $x <<= 1;

            if (($x & 0x100) !== 0) {
                $x ^= self::GF_PRIMITIVE;
            }
        }

        // Удвоенная таблица: произведение степеней может превысить 254.
        for ($i = 255; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
    }

    private static function gfMultiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return self::$exp[self::$log[$a] + self::$log[$b]];
    }

    // ── Раскладка модулей ───────────────────────────────────────────────────

    /**
     * Функциональные узоры: они не маскируются и не несут данных.
     *
     * @return array{0: list<list<bool>>, 1: list<list<bool>>} матрица и карта занятости
     */
    private static function functionPatterns(int $version, int $size): array
    {
        $matrix = array_fill(0, $size, array_fill(0, $size, false));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        // Три угловых «глаза» с разделителями.
        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$row, $col]) {
            for ($r = -1; $r <= 7; $r++) {
                for ($c = -1; $c <= 7; $c++) {
                    $y = $row + $r;
                    $x = $col + $c;

                    if ($y < 0 || $y >= $size || $x < 0 || $x >= $size) {
                        continue;
                    }

                    $isEye = $r >= 0 && $r <= 6 && $c >= 0 && $c <= 6
                        && ($r === 0 || $r === 6 || $c === 0 || $c === 6
                            || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4));

                    $matrix[$y][$x] = $isEye;
                    $reserved[$y][$x] = true;
                }
            }
        }

        // Тайминг: строка и столбец 6, тёмный модуль на чётных позициях.
        for ($i = 0; $i < $size; $i++) {
            if (! $reserved[6][$i]) {
                $matrix[6][$i] = $i % 2 === 0;
                $reserved[6][$i] = true;
            }

            if (! $reserved[$i][6]) {
                $matrix[$i][6] = $i % 2 === 0;
                $reserved[$i][6] = true;
            }
        }

        // Выравнивающие квадраты 5×5 — на всех пересечениях, кроме угловых
        // (там уже «глаза», и паттерн их перекрыл бы).
        $centers = self::ALIGNMENT_CENTERS[$version];

        foreach ($centers as $row) {
            foreach ($centers as $col) {
                $overlapsEye = ($row <= 8 && $col <= 8)
                    || ($row <= 8 && $col >= $size - 9)
                    || ($row >= $size - 9 && $col <= 8);

                if ($overlapsEye) {
                    continue;
                }

                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $isEdge = $r === -2 || $r === 2 || $c === -2 || $c === 2;
                        $isCore = $r === 0 && $c === 0;

                        $matrix[$row + $r][$col + $c] = $isEdge || $isCore;
                        $reserved[$row + $r][$col + $c] = true;
                    }
                }
            }
        }

        // Тёмный модуль — единственный всегда-тёмный вне узоров.
        $matrix[$size - 8][8] = true;
        $reserved[$size - 8][8] = true;

        self::reserveFormatAreas($matrix, $reserved, $size);

        if ($version >= 7) {
            self::writeVersionInfo($matrix, $reserved, $version, $size);
        }

        return [$matrix, $reserved];
    }

    /** Места под формат: их нельзя занять данными до выбора маски. */
    private static function reserveFormatAreas(array &$matrix, array &$reserved, int $size): void
    {
        for ($i = 0; $i <= 8; $i++) {
            foreach ([[8, $i], [$i, 8]] as [$y, $x]) {
                if (! $reserved[$y][$x]) {
                    $reserved[$y][$x] = true;
                }
            }
        }

        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$size - 1 - $i] = true;
            $reserved[$size - 1 - $i][8] = true;
        }
    }

    private static function writeVersionInfo(array &$matrix, array &$reserved, int $version, int $size): void
    {
        $info = $version << 12;
        $generator = 0x1F25;

        for ($i = 17; $i >= 12; $i--) {
            if ((($info >> $i) & 1) === 1) {
                $info ^= $generator << ($i - 12);
            }
        }

        $info |= $version << 12;

        for ($i = 0; $i < 18; $i++) {
            $bit = (($info >> $i) & 1) === 1;

            $matrix[intdiv($i, 3)][$size - 11 + ($i % 3)] = $bit;
            $reserved[intdiv($i, 3)][$size - 11 + ($i % 3)] = true;

            $matrix[$size - 11 + ($i % 3)][intdiv($i, 3)] = $bit;
            $reserved[$size - 11 + ($i % 3)][intdiv($i, 3)] = true;
        }
    }

    /**
     * Зигзаг справа налево: по два модуля в столбце, снизу вверх, затем сверху
     * вниз. Столбец 6 пропускается — там тайминг.
     *
     * @param  list<list<bool>>  $matrix
     * @param  list<list<bool>>  $reserved
     * @param  list<int>         $codewords
     */
    private static function placeData(array &$matrix, array $reserved, array $codewords, int $size): void
    {
        $bitIndex = 0;
        $totalBits = count($codewords) * 8;
        $upward = true;
        $col = $size - 1;

        while ($col >= 1) {
            if ($col === 6) {
                $col = 5;
            }

            for ($i = 0; $i < $size; $i++) {
                $row = $upward ? $size - 1 - $i : $i;

                for ($k = 0; $k < 2; $k++) {
                    $x = $col - $k;

                    if ($reserved[$row][$x]) {
                        continue;
                    }

                    // Остаток добивается нулями: ёмкость всегда кратна восьми
                    // битам, но последние модули могут остаться незанятыми.
                    $bit = $bitIndex < $totalBits
                        ? ($codewords[$bitIndex >> 3] >> (7 - ($bitIndex & 7))) & 1
                        : 0;

                    $matrix[$row][$x] = $bit === 1;
                    $bitIndex++;
                }
            }

            $upward = ! $upward;
            $col -= 2;
        }
    }

    /**
     * Перебрать все восемь масок и оставить ту, что даёт меньший штраф.
     *
     * Маска — не косметика: без неё длинные серии одинаковых модулей сбивают
     * сканер. Выбор по штрафу предписан спецификацией, поэтому он и здесь.
     *
     * @param  list<list<bool>>  $matrix
     * @param  list<list<bool>>  $reserved
     */
    private static function applyBestMask(array &$matrix, array $reserved, int $size, int $version): void
    {
        $bestMask = 0;
        $bestPenalty = PHP_INT_MAX;
        $bestMatrix = null;

        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = self::masked($matrix, $reserved, $size, $mask);
            self::writeFormatInfo($candidate, $mask, $size);

            $penalty = self::penalty($candidate, $size);

            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestMask = $mask;
                $bestMatrix = $candidate;
            }
        }

        $matrix = $bestMatrix ?? $matrix;

        // Формат переписывается на выигравшей матрице: предыдущие проходы
        // оставили там биты чужих масок.
        self::writeFormatInfo($matrix, $bestMask, $size);
    }

    /**
     * @param  list<list<bool>>  $matrix
     * @param  list<list<bool>>  $reserved
     * @return list<list<bool>>
     */
    private static function masked(array $matrix, array $reserved, int $size, int $mask): array
    {
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($reserved[$y][$x]) {
                    continue;
                }

                if (self::maskCondition($mask, $y, $x)) {
                    $matrix[$y][$x] = ! $matrix[$y][$x];
                }
            }
        }

        return $matrix;
    }

    private static function maskCondition(int $mask, int $y, int $x): bool
    {
        return match ($mask) {
            0 => ($y + $x) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($y + $x) % 3 === 0,
            4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
            5 => (($y * $x) % 2) + (($y * $x) % 3) === 0,
            6 => ((($y * $x) % 2) + (($y * $x) % 3)) % 2 === 0,
            7 => ((($y + $x) % 2) + (($y * $x) % 3)) % 2 === 0,
            default => false,
        };
    }

    /**
     * Формат: 2 бита уровня + 3 бита маски, BCH(15,5), затем XOR с 0x5412.
     *
     * @param  list<list<bool>>  $matrix
     */
    private static function writeFormatInfo(array &$matrix, int $mask, int $size): void
    {
        $data = (self::EC_LEVEL_INDICATOR << 3) | $mask;
        $format = $data << 10;
        $generator = 0x537;

        for ($i = 14; $i >= 10; $i--) {
            if ((($format >> $i) & 1) === 1) {
                $format ^= $generator << ($i - 10);
            }
        }

        $format = (($data << 10) | $format) ^ 0x5412;

        $bit = static fn (int $i): bool => (($format >> $i) & 1) === 1;

        // ПОРЯДОК БИТОВ — НЕ КОСМЕТИКА, и здесь его легко перепутать.
        //
        // В (8,0) лежит СТАРШИЙ бит (14), в (0,8) — младший (0). Если положить
        // наоборот, код остаётся правдоподобным: размер верный, «глаза»,
        // тайминг и данные на месте, PNG валиден. Но сканер читает
        // инвертированный формат и не может определить маску — билет не
        // сканируется. Хуже того, штраф маски считается по матрице с уже
        // записанным форматом, поэтому неверный порядок иногда меняет и выбор
        // маски, то есть расходится вся картинка. Проверяется эталонной
        // матрицей; глазами — нет.
        //
        // Первая копия — вокруг левого верхнего «глаза».
        for ($i = 0; $i <= 5; $i++) {
            $matrix[8][$i] = $bit(14 - $i);   // (8,0)..(8,5) — биты 14..9
            $matrix[$i][8] = $bit($i);        // (0,8)..(5,8) — биты 0..5
        }

        $matrix[8][7] = $bit(8);
        $matrix[8][8] = $bit(7);
        $matrix[7][8] = $bit(6);

        // Вторая копия разорвана: 7 бит под левым нижним «глазом» (сверху вниз,
        // от старшего), 8 бит справа от правого верхнего (слева вправо, до
        // младшего).
        for ($k = 0; $k <= 6; $k++) {
            $matrix[$size - 1 - $k][8] = $bit(14 - $k);   // биты 14..8
        }

        for ($k = 0; $k < 8; $k++) {
            $matrix[8][$size - 8 + $k] = $bit(7 - $k);    // биты 7..0
        }
    }

    /**
     * Четыре штрафа из спецификации; меньше — лучше.
     *
     * @param  list<list<bool>>  $matrix
     */
    private static function penalty(array $matrix, int $size): int
    {
        $penalty = 0;

        // 1. Серии одинаковых модулей длиной 5+.
        for ($i = 0; $i < $size; $i++) {
            $penalty += self::runPenalty(self::line($matrix, $size, $i, true));
            $penalty += self::runPenalty(self::line($matrix, $size, $i, false));
        }

        // 2. Квадраты 2×2 одного цвета.
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $value = $matrix[$y][$x];

                if ($matrix[$y][$x + 1] === $value
                    && $matrix[$y + 1][$x] === $value
                    && $matrix[$y + 1][$x + 1] === $value) {
                    $penalty += 3;
                }
            }
        }

        // 3. «Настоящий» узор тайминга в любом месте поля.
        $needles = [
            [true, false, true, true, true, false, true, false, false, false, false],
            [false, false, false, false, true, false, true, true, true, false, true],
        ];

        for ($i = 0; $i < $size; $i++) {
            foreach ([true, false] as $horizontal) {
                $line = self::line($matrix, $size, $i, $horizontal);

                for ($start = 0; $start + 11 <= $size; $start++) {
                    foreach ($needles as $needle) {
                        if (array_slice($line, $start, 11) === $needle) {
                            $penalty += 40;
                        }
                    }
                }
            }
        }

        // 4. Отклонение доли тёмных модулей от 50 %.
        $dark = 0;

        foreach ($matrix as $row) {
            foreach ($row as $value) {
                if ($value) {
                    $dark++;
                }
            }
        }

        // Формула повторяет эталонную реализацию буквально:
        //   k = |ceil(доля% / 5) − 10|
        //
        // «Ступени по 5 %» из спецификации допускают и трактовку
        // floor(|доля% − 50| / 5), и она расходится с эталоном на 51-54 %
        // (53 % даёт 0 против 1). Выбор маски на читаемость не влияет: любая из
        // восьми масок даёт корректный код. Но расхождение здесь меняет
        // выбранную маску, а значит и всю картинку, — и побитовое сравнение с
        // эталоном перестаёт что-либо проверять. Поэтому формула взята у
        // эталона, а не «по мотивам» спецификации.
        $k = (int) abs((int) ceil(($dark * 100 / ($size * $size)) / 5) - 10);

        return $penalty + $k * 10;
    }

    /**
     * @param  list<list<bool>>  $matrix
     * @return list<bool>
     */
    private static function line(array $matrix, int $size, int $index, bool $horizontal): array
    {
        $line = [];

        for ($i = 0; $i < $size; $i++) {
            $line[] = $horizontal ? $matrix[$index][$i] : $matrix[$i][$index];
        }

        return $line;
    }

    /**
     * @param  list<bool>  $line
     */
    private static function runPenalty(array $line): int
    {
        $penalty = 0;
        $run = 1;

        for ($i = 1, $count = count($line); $i < $count; $i++) {
            if ($line[$i] === $line[$i - 1]) {
                $run++;

                continue;
            }

            if ($run >= 5) {
                $penalty += 3 + ($run - 5);
            }

            $run = 1;
        }

        if ($run >= 5) {
            $penalty += 3 + ($run - 5);
        }

        return $penalty;
    }
}
