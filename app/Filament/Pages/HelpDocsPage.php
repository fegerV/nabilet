<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * Страница документации и справки для администратора.
 * Открывается из навигации: «Справка» (heroicon-o-book-open).
 */
class HelpDocsPage extends Page
{
    protected static string $routePath = '/help-docs';

    protected static ?string $slug = 'help-docs';

    /**
     * @var view-string
     */
    protected static string $view = 'filament.pages.help-docs';

    protected static ?string $title = 'Справка администратора';

    protected static ?string $navigationLabel = 'Справка';

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 100;

    protected ?string $heading = 'Справка администратора';

    protected ?string $subheading = 'Руководство по всем разделам панели';
}