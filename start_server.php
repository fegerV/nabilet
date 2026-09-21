<?php
$server = new \Symfony\Component\Process\Process(
    ['php', 'artisan', 'serve', '--host=127.0.0.1', '--port=8000'],
    __DIR__,
    null,
    null,
    null
);
$server->run();