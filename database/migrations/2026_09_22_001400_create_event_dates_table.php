<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for event_dates table - stores specific dates/times when events occur.
 * An event can have multiple dates (e.g., multi-day festival, recurring shows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_dates', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('event_id');
            $table->dateTime('start_at', 6);
            $table->dateTime('end_at', 6)->nullable();
            $table->string('status', 32)->default('scheduled');
            $table->string('name', 255)->nullable(); // Optional name for this date (e.g., "Day 1", "Evening Show")
            $table->unsignedInteger('capacity')->nullable(); // Optional capacity override for this date
            $table->boolean('is_sold_out')->default(false);
            $table->dateTime('sales_start_at', 6)->nullable(); // When ticket sales begin for this date
            $table->dateTime('sales_end_at', 6)->nullable(); // When ticket sales end for this date
            $table->text('notes')->nullable(); // Internal notes about this date
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->dateTime('deleted_at', 6)->nullable();
            
            $table->index(['event_id'], 'idx_event_dates_event');
            $table->index(['start_at'], 'idx_event_dates_start');
            $table->index(['status'], 'idx_event_dates_status');
            $table->unique(['public_id'], 'uq_event_dates_public_id');
            
            $table->foreign('event_id')
                ->references('id')
                ->on('events')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_dates');
    }
};
