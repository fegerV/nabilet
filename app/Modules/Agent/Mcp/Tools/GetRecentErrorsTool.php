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
#[Name('get_recent_errors')]
#[Description('Последние обезличенные ERROR/CRITICAL записи журнала. Стектейсы и контекст исключены.')]
final class GetRecentErrorsTool extends DiagnosticsTool
{
    protected function section(): string
    {
        return 'recentErrors';
    }
}