<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cart model and CartService write currency + total_amount, but the original
 * carts table (2026_09_20_000400_004_sales) never created these columns —
 * it only defined id/public_id/user_id/session_id/status/expires_at. This
 * migration backfills them so cart persistence and checkout math work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->char('currency', 3)->default('RUB')->after('status');
            $table->bigInteger('total_amount')->default(0)->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->dropColumn(['currency', 'total_amount']);
        });
    }
};
