<?php

declare(strict_types=1);

namespace Nabilet\Modules\Checkin\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * Генератор QR-кода настройки устройства контроля входа.
 *
 * Формат payload (понимается мобильным приложением):
 *
 *     NABILET_CFG:<base64url({"server":"https://nabilet.ru","token":"nbck_...","event":"Концерт"})}
 *
 * Поля:
 *   server — адрес сервера (origin, без пути)
 *   token  — токен устройства (nbck_…)
 *   event  — название мероприятия (для удобства проверяющего; парсером
 *            игнорируется)
 *
 * Ключи обязаны совпадать с ConfigQrParser.kt (android-чекер): он читает
 * `server` и `token`. Ранее здесь выпускались сокращённые `s`/`t` —
 * сконфигурированный так чекер не смог бы пройти настройку одним сканом.
 *
 * QR-код рисуется через PHP GD без внешних бэкендов рендера. Матрица QR
 * строится библиотекой bacon/bacon-qr-code: раньше здесь был собственный
 * энкодер в alphanumeric-режиме, а base64url содержит строчные буквы, которых
 * в alphanumeric-алфавите нет — позиция символа через strpos() превращалась
 * в 0 и payload кодировался мусором. Bacon строит корректный QR в byte mode
 * с автоматическим подбором версии, EC-кода и маски.
 */
final class ConfigQrGenerator
{
    /** Модуль QR-кода в пикселях (10 px на модуль — удобно сканеру с экрана и печати). */
    private const MODULE_PX = 10;

    /** Тихая зона вокруг QR-кода в модулях (стандарт — 4). */
    private const QUIET_ZONE = 4;

    public function generate(string $server, string $token, string $eventName): string
    {
        $payload = json_encode([
            'server' => $server,
            'token' => $token,
            'event' => $eventName,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $data = 'NABILET_CFG:' . rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

        return $this->renderPng($this->encodeMatrix($data));
    }

    /**
     * Матрица QR-кода: $matrix[$y][$x] === true — чёрный модуль.
     *
     * Bacon (Encoder::encode) сам выбирает режим (byte), версию и маску и
     * строит матрицу целиком — вместе с quiet-функциями, EC-кодированием
     * Рида-Соломона и размещением данных. EC L как в прежнем энкодере.
     *
     * @return bool[][]
     */
    private function encodeMatrix(string $data): array
    {
        $qrCode = Encoder::encode($data, ErrorCorrectionLevel::L(), Encoder::DEFAULT_BYTE_MODE_ENCODING);

        $matrix = $qrCode->getMatrix();
        $size = $matrix->getWidth();

        $modules = [];
        for ($y = 0; $y < $size; $y++) {
            $row = [];
            for ($x = 0; $x < $size; $x++) {
                $row[] = $matrix->get($x, $y) === 1;
            }
            $modules[] = $row;
        }

        return $modules;
    }

    /**
     * @param bool[][] $modules true = чёрный модуль
     */
    private function renderPng(array $modules): string
    {
        $size = count($modules);
        $imageSize = ($size + self::QUIET_ZONE * 2) * self::MODULE_PX;

        $image = imagecreatetruecolor($imageSize, $imageSize);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);

        imagefilledrectangle($image, 0, 0, $imageSize - 1, $imageSize - 1, $white);

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($modules[$y][$x]) {
                    imagefilledrectangle(
                        $image,
                        (self::QUIET_ZONE + $x) * self::MODULE_PX,
                        (self::QUIET_ZONE + $y) * self::MODULE_PX,
                        (self::QUIET_ZONE + $x + 1) * self::MODULE_PX - 1,
                        (self::QUIET_ZONE + $y + 1) * self::MODULE_PX - 1,
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
