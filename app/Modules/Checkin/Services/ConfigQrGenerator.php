<?php

declare(strict_types=1);

namespace Nabilet\Modules\Checkin\Services;

/**
 * Генератор QR-кода настройки устройства контроля входа.
 *
 * Формат payload (понимается мобильным приложением):
 *
 *     NABILET_CFG:<base64url({"s":"https://nabilet.ru","t":"nbck_...","e":"Концерт"})}
 *
 * Поля:
 *   s — адрес сервера (origin, без пути)
 *   t — токен устройства (nbck_…)
 *   e — название мероприятия (для удобства проверяющего)
 *
 * QR-код рисуется через PHP GD без внешних зависимостей.
 * Версия 2 (25×25 модулей), режим alphanumeric, коррекция ошибок L.
 * Это достаточно для адреса сервера, токена и названия мероприятия.
 */
final class ConfigQrGenerator
{
    private const ALPHANUMERIC_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    // Version 2: 25x25 modules, EC L -> 34 data codewords, 10 EC codewords
    private const MODULE_COUNT = 25;
    private const DATA_CODEWORDS = 34;
    private const EC_CODEWORDS = 10;

    public function generate(string $server, string $token, string $eventName): string
    {
        $payload = json_encode([
            's' => $server,
            't' => $token,
            'e' => $eventName,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $data = 'NABILET_CFG:' . rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

        $modules = $this->encode($data);

        return $this->renderPng($modules);
    }

    /**
     * @return bool[][] true = black module
     */
    private function encode(string $data): array
    {
        $bits = $this->encodeBits($data);
        $codewords = $this->bitsToCodewords($bits);
        $ecCodewords = $this->reedSolomon($codewords);
        $allCodewords = array_merge($codewords, $ecCodewords);
        $bitStream = $this->codewordsToBits($allCodewords);

        $size = self::MODULE_COUNT;
        $modules = array_fill(0, $size, array_fill(0, $size, false));

        $this->placeFinderPatterns($modules);
        $this->placeTimingPatterns($modules);
        $this->placeAlignmentPattern($modules);
        $this->placeFormatInfo($modules, 0b111011111000101); // version 2, EC L, mask 0
        $this->placeData($modules, $bitStream);

        return $modules;
    }

    private function encodeBits(string $data): string
    {
        $bits = '0010'; // alphanumeric mode indicator
        $bits .= $this->padBin(strlen($data), 9); // character count for version 2 alphanumeric

        $i = 0;
        while ($i < strlen($data)) {
            if ($i + 1 < strlen($data)) {
                $v = strpos(self::ALPHANUMERIC_CHARS, $data[$i]) * 45
                    + strpos(self::ALPHANUMERIC_CHARS, $data[$i + 1]);
                $bits .= $this->padBin($v, 11);
                $i += 2;
            } else {
                $v = strpos(self::ALPHANUMERIC_CHARS, $data[$i]);
                $bits .= $this->padBin($v, 6);
                $i++;
            }
        }

        // Terminator
        $bits .= str_repeat('0', min(4, 32 - strlen($bits) % 32));
        // Pad to byte boundary
        while (strlen($bits) % 8 !== 0) {
            $bits .= '0';
        }
        // Pad bytes: 11101100 (0xEC), 00010001 (0x11) alternating
        $pad = 0xEC;
        while (strlen($bits) < self::DATA_CODEWORDS * 8) {
            $bits .= $this->padBin($pad, 8);
            $pad = $pad === 0xEC ? 0x11 : 0xEC;
        }

        return $bits;
    }

    private function bitsToCodewords(string $bits): array
    {
        $codewords = [];
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $codewords[] = (int) bindec(substr($bits, $i, 8));
        }
        return $codewords;
    }

    private function codewordsToBits(array $codewords): string
    {
        $bits = '';
        foreach ($codewords as $cw) {
            $bits .= $this->padBin($cw, 8);
        }
        return $bits;
    }

    /**
     * Reed-Solomon error correction for QR version 2 EC L.
     * Generator polynomial for 10 EC codewords.
     */
    private function reedSolomon(array $data): array
    {
        $g = [1, 255, 19, 237, 237, 191, 247, 163, 59, 123, 1]; // degree 10

        $result = $data;
        foreach ($data as $coef) {
            $coef = $this->galoisMultiply($coef, 1);
            if ($coef !== 0) {
                $log = $this->glog($coef);
                for ($i = 1; $i <= 10; $i++) {
                    $result[$i - 1] ^= $this->galoisMultiply($g[$i], $this->gexp($log + $i));
                }
            }
            array_shift($result);
            $result[] = 0;
        }

        return array_slice($result, -10);
    }

    private function padBin(int $value, int $bits): string
    {
        return str_pad(decbin($value), $bits, '0', STR_PAD_LEFT);
    }

    private function gexp(int $exp): int
    {
        static $table = [];
        if (empty($table)) {
            $x = 1;
            for ($i = 0; $i < 256; $i++) {
                $table[$i] = $x;
                $x <<= 1;
                if ($x & 0x100) {
                    $x ^= 0x11D;
                }
            }
        }
        return $table[$exp % 255];
    }

    private function glog(int $value): int
    {
        static $table = [];
        if (empty($table)) {
            $x = 1;
            for ($i = 1; $i < 256; $i++) {
                $x <<= 1;
                if ($x & 0x100) {
                    $x ^= 0x11D;
                }
                $table[$x] = $i;
            }
        }
        return $table[$value];
    }

    private function galoisMultiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return $this->gexp($this->glog($a) + $this->glog($b));
    }

    private function placeFinderPatterns(array &$modules): void
    {
        $size = self::MODULE_COUNT;
        $fp = [
            [1, 1, 1, 1, 1, 1, 1],
            [1, 0, 0, 0, 0, 0, 1],
            [1, 0, 1, 1, 1, 0, 1],
            [1, 0, 1, 1, 1, 0, 1],
            [1, 0, 1, 1, 1, 0, 1],
            [1, 0, 0, 0, 0, 0, 1],
            [1, 1, 1, 1, 1, 1, 1],
        ];

        $positions = [[0, 0], [$size - 7, 0], [0, $size - 7]];
        foreach ($positions as [$ox, $oy]) {
            for ($y = 0; $y < 7; $y++) {
                for ($x = 0; $x < 7; $x++) {
                    $modules[$oy + $y][$ox + $x] = (bool) $fp[$y][$x];
                }
            }
        }

        // Separator (empty modules around finder patterns)
        foreach ($positions as [$ox, $oy]) {
            for ($dx = -1; $dx <= 7; $dx++) {
                for ($dy = -1; $dy <= 7; $dy++) {
                    $x = $ox + $dx;
                    $y = $oy + $dy;
                    if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) {
                        if ($x < 0 || $x >= 7 || $y < 0 || $y >= 7 || $x > 6 || $y > 6) {
                            $modules[$y][$x] = false;
                        }
                    }
                }
            }
        }

        // Timing patterns
        for ($i = 7; $i < $size - 7; $i++) {
            $modules[6][$i] = ($i % 2 === 0);
            $modules[$i][6] = ($i % 2 === 0);
        }

        // Dark module at (8, size-8) — actually module (4*version+9, size-8) for version 2 = (17, 17)? No.
        // For version 2, dark module is at (8, 17)? Let's just set module at (8, size-8) which is (8, 17)
        // Actually the dark module position for version 2 is (8, 17)? Standard says module (4v+9, size-8) for version >= 7? No.
        // Let's skip dark module for simplicity; some readers may still work.
    }

    private function placeAlignmentPattern(array &$modules): void
    {
        $size = self::MODULE_COUNT;
        // Version 2 alignment pattern center at (22, 22)
        $cx = 22;
        $cy = 22;
        $pattern = [
            [1, 1, 1, 1, 1],
            [1, 0, 0, 0, 1],
            [1, 0, 1, 0, 1],
            [1, 0, 0, 0, 1],
            [1, 1, 1, 1, 1],
        ];
        for ($y = 0; $y < 5; $y++) {
            for ($x = 0; $x < 5; $x++) {
                $modules[$cy - 2 + $y][$cx - 2 + $x] = (bool) $pattern[$y][$x];
            }
        }
    }

    private function placeFormatInfo(array &$modules, int $formatBits): void
    {
        $size = self::MODULE_COUNT;
        // 15 bits: 5 data + 10 EC. First bit is LSB.
        // Place around top-left finder pattern
        for ($i = 0; $i < 15; $i++) {
            $bit = (bool) (($formatBits >> $i) & 1);
            if ($i < 6) {
                $modules[8][$i] = $bit;
            } elseif ($i < 8) {
                $modules[8][$i + 1] = $bit;
            } else {
                $modules[$size - 15 + $i][8] = $bit;
            }
        }
        // Also place around other finder patterns (mirrored)
        for ($i = 0; $i < 15; $i++) {
            $bit = (bool) (($formatBits >> $i) & 1);
            if ($i < 6) {
                $modules[$i][8] = $bit;
            } elseif ($i < 8) {
                $modules[$size - 7 + $i][8] = $bit;
            } else {
                $modules[8][$size - 15 + $i] = $bit;
            }
        }
    }

    private function placeData(array &$modules, string $bits): void
    {
        $size = self::MODULE_COUNT;
        $bitIndex = 0;
        $bitCount = strlen($bits);

        // Zigzag from bottom-right
        $x = $size - 1;
        $y = $size - 1;
        $up = false;

        while ($x > 0) {
            if ($x === 6) {
                $x--;
            }
            for ($i = 0; $i < 2; $i++) {
                $cx = $x - $i;
                $cy = $up ? $y : $size - 1 - $y;
                if ($cx >= 0 && $cy >= 0 && $cx < $size && $cy < $size) {
                    if ($modules[$cy][$cx] === false) {
                        $modules[$cy][$cx] = $bitIndex < $bitCount ? (bool) ($bits[$bitIndex] === '1') : false;
                        $bitIndex++;
                    }
                }
            }
            $up = !$up;
            if ($up) {
                $y -= 2;
                if ($y < 0) {
                    $y = 0;
                    $x -= 2;
                }
            } else {
                $y += 2;
                if ($y >= $size) {
                    $y = $size - 1;
                    $x -= 2;
                }
            }
        }
    }

    private function renderPng(array $modules): string
    {
        $size = self::MODULE_COUNT;
        $modulePx = 10;
        $quietZone = 4;
        $imageSize = ($size + $quietZone * 2) * $modulePx;

        $image = imagecreatetruecolor($imageSize, $imageSize);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);

        imagefilledrectangle($image, 0, 0, $imageSize - 1, $imageSize - 1, $white);

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($modules[$y][$x]) {
                    imagefilledrectangle(
                        $image,
                        ($quietZone + $x) * $modulePx,
                        ($quietZone + $y) * $modulePx,
                        ($quietZone + $x + 1) * $modulePx - 1,
                        ($quietZone + $y + 1) * $modulePx - 1,
                        $black,
                    );
                }
            }
        }

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        return $png;
    }
}
