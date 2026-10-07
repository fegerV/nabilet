<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('halls', function (Blueprint $table): void {
            $table->string('city', 150)->nullable()->after('description');
            $table->string('address', 500)->nullable()->after('city');
            $table->string('exterior_photo_url', 2048)->nullable()->after('address');
            $table->string('interior_photo_url', 2048)->nullable()->after('exterior_photo_url');
        });

        Schema::table('seats', function (Blueprint $table): void {
            $table->bigInteger('price_amount')->nullable()->after('number');
        });
    }

    public function down(): void
    {
        Schema::table('seats', function (Blueprint $table): void {
            $table->dropColumn('price_amount');
        });

        Schema::table('halls', function (Blueprint $table): void {
            $table->dropColumn(['city', 'address', 'exterior_photo_url', 'interior_photo_url']);
        });
    }
};
