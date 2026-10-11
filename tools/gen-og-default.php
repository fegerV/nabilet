<?php

declare(strict_types=1);

/**
 * Генератор растровой заглушки для Open Graph.
 *
 * ЗАЧЕМ. `EventPageController` и `StructuredDataService` подставляют эту картинку,
 * когда у события нет постера. Раньше это был только SVG — а SVG не читают
 * парсеры VK и iMessage, то есть карточка ссылки у события без постера
 * раскрывалась БЕЗ картинки именно там, где афиши и распространяются.
 *
 * ПОЧЕМУ ГЕНЕРАТОР, А НЕ ГОТОВЫЙ ФАЙЛ В РЕПОЗИТОРИИ. Дизайн должен совпадать с
 * фирменными цветами витрины (`--brand-500` / `--accent-500` в
 * `resources/css/app.css`). Файл, отрисованный один раз вручную, при смене
 * палитры молча остаётся старым — а заметно это только в чужом мессенджере.
 * Скрипт делает пересборку одной командой и служит документацией того, из чего
 * картинка состоит.
 *
 * Запуск:  php tools/gen-og-default.php
 *
 * Требуется расширение GD с поддержкой TTF (`imagettftext`). Если его нет,
 * скрипт падает с понятным сообщением, а не пишет пустой файл: пустой `og:image`
 * хуже отсутствующего — соцсеть закэширует битую картинку на сутки.
 */
$root = dirname(__DIR__);
$target = $root . '/public/images/og-default.png';
$fontRegular = 'C:/Windows/Fonts/segoeui.ttf';
$fontBold = 'C:/Windows/Fonts/segoeuib.ttf';

if (! function_exists('imagettftext') || ! function_exists('imagepng')) {
    fwrite(STDERR, "GD с поддержкой TTF/PNG не установлен — генерировать нечем.\n");
    exit(1);
}

foreach ([$fontRegular, $fontBold] as $font) {
    if (! is_file($font)) {
        fwrite(STDERR, "Не найден шрифт {$font}. Укажите путь к TTF с кириллицей.\n");
        exit(1);
    }
}

/** @return int идентификатор цвета в палитре изображения */
$rgb = static function (\GdImage $image, string $hex): int {
    $hex = ltrim($hex, '#');

    return (int) imagecolorallocate(
        $image,
        (int) hexdec(substr($hex, 0, 2)),
        (int) hexdec(substr($hex, 2, 2)),
        (int) hexdec(substr($hex, 4, 2)),
    );
};

/** Линейная интерполяция двух цветов: $t = 0 → $from, $t = 1 → $to. */
$mix = static function (array $from, array $to, float $t): array {
    return [
        (int) round($from[0] + ($to[0] - $from[0]) * $t),
        (int) round($from[1] + ($to[1] - $from[1]) * $t),
        (int) round($from[2] + ($to[2] - $from[2]) * $t),
    ];
};

$width = 1200;
$height = 630;

$image = imagecreatetruecolor($width, $height);
imagealphablending($image, true);

// ── Фон ─────────────────────────────────────────────────────────────────────
$background = [0x0B, 0x09, 0x18];
imagefilledrectangle($image, 0, 0, $width, $height, $rgb($image, '#0B0918'));

// ── Свечение сверху ─────────────────────────────────────────────────────────
// У GD нет радиального градиента, поэтому он рисуется концентрическими
// эллипсами от большого к малому: цвет плавно уходит от фона к `--brand-500`, а
// прозрачность растёт. Так получается тот же эффект, что у `<radialGradient>` в
// исходном SVG.
$brand = [0x6D, 0x4A, 0xFF];
$steps = 60;

for ($i = $steps; $i >= 1; $i--) {
    $t = $i / $steps;                       // 1 → внешний край, 0 → центр
    $radius = (int) round(60 + 700 * $t);
    $color = $mix($brand, $background, $t);
    $alpha = (int) round(127 * (1 - (1 - $t) ** 2.2)); // у края прозрачно

    $allocated = imagecolorallocatealpha($image, $color[0], $color[1], $color[2], $alpha);
    imagefilledellipse($image, (int) ($width / 2), 0, $radius * 2, $radius * 2, $allocated);
}

// ── Декоративное «поле мест» ────────────────────────────────────────────────
$dot = imagecolorallocatealpha($image, 255, 255, 255, 110);

foreach ([150, 210, 270] as $x) {
    foreach ([150, 210] as $y) {
        imagefilledellipse($image, $x, $y, 14, 14, $dot);
    }
}

foreach ([930, 990, 1050] as $x) {
    foreach ([420, 480] as $y) {
        imagefilledellipse($image, $x, $y, 14, 14, $dot);
    }
}

// ── Текст ───────────────────────────────────────────────────────────────────
$white = $rgb($image, '#FFFFFF');
$accentText = $rgb($image, '#B0A0FF');
$mutedText = $rgb($image, '#7A729F');

imagettftext($image, 104, 0, 96, 260, $white, $fontBold, 'NABILET');

// ── Фирменная полоса под названием (градиент brand → accent) ────────────────
$barTop = 302;
$barHeight = 8;
$barWidth = 132;
$accent = [0xFF, 0x5C, 0x22];

for ($x = 0; $x < $barWidth; $x++) {
    $color = $mix($brand, $accent, $x / ($barWidth - 1));
    imagefilledrectangle(
        $image,
        100 + $x,
        $barTop,
        100 + $x,
        $barTop + $barHeight,
        imagecolorallocate($image, $color[0], $color[1], $color[2]),
    );
}

imagettftext($image, 40, 0, 96, 400, $accentText, $fontRegular, 'Билеты на события');
imagettftext($image, 28, 0, 96, 460, $mutedText, $fontRegular, 'Выбор мест на схеме зала · Оплата онлайн · QR-билет');

// ── Запись ──────────────────────────────────────────────────────────────────
$directory = dirname($target);

if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
    fwrite(STDERR, "Не удалось создать каталог {$directory}.\n");
    exit(1);
}

if (! imagepng($image, $target, 9)) {
    fwrite(STDERR, "Не удалось записать {$target}.\n");
    exit(1);
}

imagedestroy($image);

printf(
    "Записано %s (%d×%d, %d байт).\n",
    $target,
    $width,
    $height,
    (int) filesize($target),
);
