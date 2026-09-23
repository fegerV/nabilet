<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration creates 5 tables for expanded event content:
 * - event_speakers: speakers at events
 * - event_sponsors: event sponsors with tiers
 * - event_faqs: frequently asked questions
 * - event_artists: performing artists
 * - event_schedule_items: schedule/timetable items
 */
return new class extends Migration
{
    public function up(): void
    {
        // Event Speakers Table
        Schema::create('event_speakers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('name');
            $table->string('position')->nullable();
            $table->text('bio')->nullable();
            $table->string('avatar')->nullable();
            $table->json('social_links')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->timestamps();
            
            $table->index(['event_id', 'sort_order']);
            $table->index('is_featured');
        });

        // Event Sponsors Table
        Schema::create('event_sponsors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('name');
            $table->string('logo')->nullable();
            $table->string('tier')->default('standard'); // bronze, silver, gold, platinum, standard
            $table->string('website')->nullable();
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index(['event_id', 'tier', 'sort_order']);
            $table->index('is_active');
        });

        // Event FAQs Table
        Schema::create('event_faqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('question');
            $table->text('answer');
            $table->string('category')->nullable(); // ticket, venue, payment, general
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index(['event_id', 'category', 'sort_order']);
            $table->index('is_active');
        });

        // Event Artists Table
        Schema::create('event_artists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('name');
            $table->string('genre')->nullable();
            $table->text('bio')->nullable();
            $table->string('photo')->nullable();
            $table->json('social_links')->nullable();
            $table->string('stage')->nullable(); // for multi-stage events
            $table->dateTime('performance_at')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_headliner')->default(false);
            $table->timestamps();
            
            $table->index(['event_id', 'sort_order']);
            $table->index('is_headliner');
        });

        // Event Schedule Items Table
        Schema::create('event_schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('event_date_id')->nullable()->constrained('event_dates')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->time('start_time');
            $table->integer('duration_minutes')->default(60);
            $table->string('location')->nullable(); // hall, room, stage
            $table->string('type')->default('session'); // session, break, registration, keynote, workshop
            $table->json('speaker_ids')->nullable(); // references to event_speakers IDs
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index(['event_id', 'event_date_id', 'start_time']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_schedule_items');
        Schema::dropIfExists('event_artists');
        Schema::dropIfExists('event_faqs');
        Schema::dropIfExists('event_sponsors');
        Schema::dropIfExists('event_speakers');
    }
};
