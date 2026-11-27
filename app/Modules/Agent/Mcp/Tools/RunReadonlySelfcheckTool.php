<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Mcp\Tools;

use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[Name('run_readonly_selfcheck')]
#[Description('Только чтение: проверки состояния без отправки алертов и записи в БД.')]
final class RunReadonlySelfcheckTool extends DiagnosticsTool
{
    protected function section(): string
    {
        return 'readonlySelfcheck';
    }
}