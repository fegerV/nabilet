<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A6: билет должен нести подписанный QR-пэйлоад (QrSigner «NB1.<id>.<token>.<sig>»),
 * а не только хэш токена. Колонка qr_payload — то, что кодируется в QR-картинку;
 * qr_token_hash остаётся уникальным якорем для проверки на чек-ине.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->string('qr_payload', 255)->nullable()->after('qr_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropColumn('qr_payload');
        });
    }
};
