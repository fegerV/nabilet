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
#[Name('validate_ticket_template')]
#[Description('Проверить шаблон билета доменными правилами.')]
final class ValidateTicketTemplateTool extends ContentTool
{
    protected function section(): string
    {
        return 'validateTicketTemplate';
    }
}