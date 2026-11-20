<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Папки медиатеки.
 *
 * Файлы хранятся в одном каталоге диска — папка здесь это ВИРТУАЛЬНАЯ
 * группировка в `media_folders`, а не каталог на диске. Поэтому перемещение
 * файла не трогает путь и не ломает уже выданные URL афиш и галерей.
 *
 * `parent_id = 0` означает корень: в MySQL UNIQUE-индекс с NULL разрешает
 * дубли, а 0 даёт честную проверку «в этой папке нет двух одинаковых имён».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media_folders')) {
            Schema::create('media_folders', function (Blueprint $table): void {
                $table->id();
                $table->char('public_id', 26);
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('parent_id')->default(0);
                $table->string('name', 255);
                $table->dateTime('created_at', 6);
                $table->dateTime('updated_at', 6);

                $table->unique(['public_id'], 'uq_media_folders_public_id');
                $table->unique(['organization_id', 'parent_id', 'name'], 'uq_media_folders_path');
                $table->index(['organization_id', 'parent_id'], 'idx_media_folders_parent');
            });
        }

        if (Schema::hasTable('media_assets') && ! Schema::hasColumn('media_assets', 'folder_id')) {
            Schema::table('media_assets', function (Blueprint $table): void {
                $table->unsignedBigInteger('folder_id')->nullable()->after('organization_id');
                $table->index(['organization_id', 'folder_id'], 'idx_media_assets_folder');
            });
        }
    }

    public function down(): void
    {
        // История группировок намеренно сохраняется.
    }
};
