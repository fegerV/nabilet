<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Notifications\Http\Controllers\MailSettingsController;
use Nabilet\Modules\Notifications\Http\Controllers\NewsletterController;
use Nabilet\Modules\Notifications\Http\Controllers\NotificationTemplateController;

// Mounted at /api/v1 by bootstrap/app.php вЂ” do not repeat the version segment.
//
// Р РµРґР°РєС‚РёСЂРѕРІР°РЅРёРµ С€Р°Р±Р»РѕРЅРѕРІ РїРёСЃРµРј вЂ” Р°РґРјРёРЅРёСЃС‚СЂР°С‚РѕСЂСЃРєРёР№ СЂР°Р·РґРµР». `admin` РїРѕРІРµСЂС…
// `auth:api`: С€Р°Р±Р»РѕРЅ РѕРїСЂРµРґРµР»СЏРµС‚ РїРёСЃСЊРјРѕ, РєРѕС‚РѕСЂРѕРµ РїРѕР»СѓС‡РёС‚ РєР°Р¶РґС‹Р№ РїРѕРєСѓРїР°С‚РµР»СЊ.
Route::prefix('notification-templates')->middleware(['auth:api', 'admin'])->group(function () {
    Route::get('/', [NotificationTemplateController::class, 'index']);
    Route::get('/{template}', [NotificationTemplateController::class, 'show'])->whereNumber('template');
    Route::put('/{template}', [NotificationTemplateController::class, 'update'])->whereNumber('template');
    Route::post('/{template}/preview', [NotificationTemplateController::class, 'preview'])->whereNumber('template');
});

// SMTP-РЅР°СЃС‚СЂРѕР№РєРё РІ Р°РґРјРёРЅРєРµ: Р±РµР· РЅРёС… РїРѕС‡С‚Сѓ РЅРµР»СЊР·СЏ РІРєР»СЋС‡РёС‚СЊ Р±РµР· РїСЂР°РІРєРё .env Рё
// РїРµСЂРµР·Р°РїСѓСЃРєР°, С‡С‚Рѕ РЅР° С€Р°СЂРµРґ-С…РѕСЃС‚РёРЅРіРµ РЅРµРґРѕСЃС‚СѓРїРЅРѕ РІР»Р°РґРµР»СЊС†Сѓ РјР°РіР°Р·РёРЅР°.
Route::prefix('admin/mail')->middleware(['auth:api', 'admin'])->group(function () {
    Route::get('/settings', [MailSettingsController::class, 'show']);
    Route::put('/settings', [MailSettingsController::class, 'update']);
    Route::post('/test', [MailSettingsController::class, 'sendTest']);
});

// ── Подписка на рассылку афиши ─────────────────────────────────────────────
//
// Публичные (витрина): подписаться может гость до всякого аккаунта, отписка
// идёт по токену из письма — владельцу адреса не нужен пароль (152-ФЗ §9).
// `throttle:6,1` держит автозабивку мусорных адресов; XOR-ответы подписки
// не вскрывают существование чужих адресов.
Route::post('subscribe', [NewsletterController::class, 'store'])->middleware('throttle:6,1');
Route::get('unsubscribe/{token}', [NewsletterController::class, 'unsubscribe']);
Route::post('unsubscribe/{token}', [NewsletterController::class, 'unsubscribe']);