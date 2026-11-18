<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Заказ = билет (этап 3, подготовка).
 *
 * Переносим состояние билета (QR, погашение, возврат) прямо в позицию заказа.
 * Одна строка `order_items` = один проданный билет = один QR. Таблица tickets
 * остаётся на этом этапе как legacy-зеркало для чек-ина и уведомлений; после
 * перевода читателей она будет удалена.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('order_items', 'event_id')) {
                $table->unsignedBigInteger('event_id')->nullable()->after('inventory_item_id');
            }
            if (! Schema::hasColumn('order_items', 'session_id')) {
                $table->unsignedBigInteger('session_id')->nullable()->after('event_id');
            }
            if (! Schema::hasColumn('order_items', 'ticket_number')) {
                $table->string('ticket_number', 100)->nullable()->after('total_amount');
                $table->unsignedInteger('ticket_index')->nullable()->after('ticket_number');
                $table->unsignedSmallInteger('qr_version')->nullable()->after('ticket_index');
                $table->char('qr_token_hash', 64)->nullable()->after('qr_version');
                $table->string('qr_payload', 512)->nullable()->after('qr_token_hash');
                $table->string('holder_name', 255)->nullable()->after('qr_payload');
                $table->string('ticket_status', 32)->nullable()->after('holder_name');
                $table->dateTime('issued_at', 6)->nullable()->after('ticket_status');
                $table->dateTime('used_at', 6)->nullable()->after('issued_at');
                $table->dateTime('refunded_at', 6)->nullable()->after('used_at');
                $table->dateTime('revoked_at', 6)->nullable()->after('refunded_at');
                $table->string('revoked_reason', 100)->nullable()->after('revoked_at');
                $table->index(['event_id', 'ticket_status'], 'idx_order_items_event_ticket_status');
                $table->unique(['ticket_number'], 'uq_order_items_ticket_number');
                $table->unique(['qr_token_hash'], 'uq_order_items_qr_hash');
            }
        });

        $tickets = DB::table('tickets')->orderBy('id')->get();
        foreach ($tickets as $ticket) {
            $item = DB::table('order_items')->where('id', $ticket->order_item_id)->first();
            if ($item === null) {
                continue;
            }

            $targetId = $item->id;
            if ((int) $item->quantity > 1) {
                // Первый билет остаётся на исходной строке; остальные получают
                // отдельные копии позиции с quantity = 1.
                $copy = (array) $item;
                unset($copy['id']);
                $targetId = DB::table('order_items')->insertGetId($copy);
                $single = (int) round((int) $item->total_amount / (int) $item->quantity);
                DB::table('order_items')->where('id', $targetId)->update([
                    'quantity' => 1,
                    'unit_price' => $single,
                    'total_amount' => $single,
                ]);
            }

            DB::table('order_items')->where('id', $targetId)->update([
                'event_id' => $ticket->event_id,
                'session_id' => $ticket->session_id,
                'ticket_number' => $ticket->ticket_number,
                'ticket_index' => $ticket->ticket_index,
                'qr_version' => $ticket->qr_version,
                'qr_token_hash' => $ticket->qr_token_hash,
                'qr_payload' => $ticket->qr_payload ?? null,
                'holder_name' => $ticket->holder_name,
                'ticket_status' => $ticket->status,
                'issued_at' => $ticket->issued_at,
                'used_at' => $ticket->used_at,
                'refunded_at' => $ticket->refunded_at,
                'revoked_at' => $ticket->revoked_at,
                'revoked_reason' => $ticket->revoked_reason,
                'quantity' => 1,
            ]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('order_item_is_ticket is not reversible; restore from a backup.');
    }
};
