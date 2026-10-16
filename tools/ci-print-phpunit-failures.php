<?php

/**
 * Печатает упавшие тесты из JUnit-лога phpunit в виде GitHub-аннотаций
 * (`::error title=...::...`) — лог джобы закрыт правами, а аннотации читаются
 * по API без авторизации. Вызывается из CI только при падении свиты.
 */

$file = $argv[1] ?? 'phpunit-junit.xml';

if (! is_file($file)) {
    fwrite(STDERR, "no junit log at {$file}\n");

    exit(0);
}

$xml = @simplexml_load_file($file);

if ($xml === false) {
    fwrite(STDERR, "cannot parse {$file}\n");

    exit(0);
}

$failures = [];

foreach ($xml->xpath('//testcase') as $case) {
    $failure = $case->failure ?? $case->error ?? null;

    if ($failure === null) {
        continue;
    }

    $failures[] = [
        'name' => (string) $case['name'],
        'class' => (string) $case['classname'],
        'message' => trim((string) ($failure['message'] ?? '')),
        'text' => trim((string) $failure),
    ];
}

if ($failures === []) {
    fwrite(STDERR, "junit parsed: no failures recorded (suite may have errored before any test ran).\n");

    exit(0);
}

foreach ($failures as $failure) {
    $short = basename(str_replace('\\', '/', $failure['class']));

    // GitHub прячет длинные аннотации за сворачиванием; первые строки
    // сообщения достаточны, чтобы опознать причину.
    $message = mb_substr($failure['message'] !== '' ? $failure['message'] : $failure['text'], 0, 600);

    $message = str_replace(["\r", "\n"], ' | ', $message);

    fwrite(STDOUT, sprintf(
        "::error title=%s::%s — %s\n",
        escapeshellarg($short . '::' . $failure['name']),
        $short,
        $message
    ));
}
