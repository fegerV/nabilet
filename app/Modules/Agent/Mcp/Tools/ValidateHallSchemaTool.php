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
#[Name('validate_hall_schema')]
#[Description('Проверить объект схемы зала теми же правилами, что редактор.')]
final class ValidateHallSchemaTool extends ContentTool
{
    protected function section(): string
    {
        return 'validateHallSchema';
    }
}