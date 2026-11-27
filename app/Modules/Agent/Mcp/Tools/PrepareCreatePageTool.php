<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Mcp\Tools;

use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsIdempotent]
#[IsOpenWorld(false)]
#[Name('prepare_create_page')]
#[Description('Подготовить черновик страницы. Требует одобрения человека.')]
final class PrepareCreatePageTool extends PrepareContentTool
{
    protected function section(): string
    {
        return 'createPage';
    }
}