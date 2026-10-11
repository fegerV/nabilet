<?php

declare(strict_types=1);

/**
 * Сканирует PHP-файлы на неявно nullable-параметры (`int $x = null`).
 * PHP 8.4: Deprecated. Выводит список файл:строка и итоговый счётчик.
 */

$root = $argv[1] ?? '.';
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$hits = [];
$files = 0;

foreach ($rii as $file) {
    if ($file->isDir()) continue;
    if ($file->getExtension() !== 'php') continue;
    $path = $file->getPathname();
    if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) continue;
    if (str_contains($path, DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR)) continue;

    $src = file_get_contents($path);
    if ($src === false) continue;
    $files++;

    try {
        $tokens = token_get_all($src);
    } catch (Throwable) {
        continue;
    }

    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_FUNCTION) continue;
        if (!isset($tokens[$i + 1]) || !is_array($tokens[$i + 1]) || $tokens[$i + 1][0] !== T_WHITESPACE) continue;

        // Ищем открывающую скобку параметров.
        $depth = 0;
        $parenOpen = null;
        for ($j = $i + 1; $j < $n; $j++) {
            $tj = $tokens[$j];
            if (is_array($tj) && in_array($tj[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
            if ($tj === '(') { $parenOpen = $j; break; }
            if (is_array($tj) && $tj[0] === T_STRING) continue; // имя функции
            break;
        }
        if ($parenOpen === null) continue;

        // Собираем параметры до парной закрывающей скобки.
        $depth = 0;
        $paramStart = $parenOpen + 1;
        $end = null;
        for ($j = $parenOpen; $j < $n; $j++) {
            $tj = $tokens[$j];
            if ($tj === '(') { $depth++; continue; }
            if ($tj === ')') {
                $depth--;
                if ($depth === 0) { $end = $j; break; }
            }
        }
        if ($end === null) continue;

        $params = [];
        $buf = [];
        $pdepth = 0;
        for ($j = $paramStart; $j < $end; $j++) {
            $tj = $tokens[$j];
            if ($tj === '(' || $tj === '[') $pdepth++;
            if ($tj === ')' || $tj === ']') $pdepth--;
            if ($tj === ',' && $pdepth === 0) {
                $params[] = $buf;
                $buf = [];
                continue;
            }
            $buf[] = $tj;
        }
        if ($buf) $params[] = $buf;

        foreach ($params as $ptokens) {
            $hasType = false;
            $nullable = false;
            $hasNullDefault = false;
            $varName = '';
            // Тип ищем ТОЛЬКО до '=': `null` справа — значение по умолчанию,
            // а не тип, иначе любой бестиповой параметр считается типизированным.
            $inDefault = false;
            foreach ($ptokens as $pt) {
                if ($pt === '=') { $inDefault = true; continue; }
                if ($inDefault) continue;
                if (is_array($pt)) {
                    if ($pt[0] === T_WHITESPACE || $pt[0] === T_COMMENT || $pt[0] === T_DOC_COMMENT) continue;
                    if (in_array($pt[0], [T_STRING, T_ARRAY, T_CALLABLE, T_STATIC, T_NS_SEPARATOR, T_NAME_QUALIFIED], true)) {
                        $hasType = true;
                        continue;
                    }
                    if ($pt[0] === T_VARIABLE) { $varName = $pt[1]; continue; }
                    continue;
                }
                if ($pt === '?') { $nullable = true; continue; }
            }
            // есть ли null после '=' (только в коде, комментарии не считаем)
            $text = '';
            foreach ($ptokens as $pt) {
                if (is_array($pt) && in_array($pt[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                if (is_array($pt)) $text .= $pt[1] . ' ';
                else $text .= $pt . ' ';
            }
            if (preg_match('/=\s*null\b/i', $text)) $hasNullDefault = true;

            if ($hasType && !$nullable && $hasNullDefault && $varName !== '') {
                // строка параметра
                $line = null;
                foreach ($ptokens as $pt) {
                    if (is_array($pt) && $pt[0] === T_VARIABLE) { $line = $pt[2]; break; }
                }
                $hits[] = sprintf('%s:%d: %s = null', $path, $line ?? 0, $varName);
            }
        }
    }
}

echo "Файлов проверено: {$files}\n";
echo 'Неявно nullable параметров: ' . count($hits) . "\n";
foreach ($hits as $h) echo $h . "\n";
exit(count($hits) > 0 ? 1 : 0);
