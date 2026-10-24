<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Tests\TestCase;

/**
 * Афиша из `image_url` доходит до колонки `poster` на создании и на правке.
 *
 * ЗАЧЕМ ЭТОТ ТЕСТ. Жалоба «афиша не сохраняется» уже закрывалась один раз:
 * `UpdateEventRequest` не имел правил для `poster_file` и не сохранял файл.
 * Появился трейт `HandlesPosterUpload` с переносом `image_url` → `poster`, и
 * всё закрылось. Но перенос делает `normalizePosterMapping()`, а она
 * мутирует ЖИВОЙ источник входных данных — в то время как
 * `Validator::validated()` читает СНИМОК, снятый ДО `passedValidation()`
 * (`ValidatesWhenResolvedTrait::validateResolved()` создаёт валидатор
 * раньше, чем зовёт `passedValidation()`).
 *
 * То есть перенос виден в `$request->input('poster')`, но НЕ виден в
 * `$request->validated()` — а контроллер пишет именно `validated()`.
 * `image_url` при этом в `validated()` ЕСТЬ (правило для него есть), но в
 * `Event::$fillable` его нет, поэтому Eloquent его молча выбрасывает.
 * Итог: 200 OK, афиша не сохранена — ровно та жалоба, которую считали
 * закрытой.
 *
 * Тест идёт через HTTP и смотрит в БД, потому что предмет — именно порядок
 * «валидатор → passedValidation → контроллер», и вне HTTP он не виден.
 */
final class EventPosterMappingTest extends TestCase
{
    use RefreshDatabase;

    private const POSTER = 'https://cdn.nabilet.local/posters/koncert.jpg';

    public function test_creating_an_event_keeps_the_poster_from_image_url(): void
    {
        $organizationId = $this->seedOrganization();
        $this->actingAsAdmin($organizationId);

        $response = $this->postJson('/api/v1/events', [
            'organization_id' => $organizationId,
            'title' => 'Концерт «Секрет»',
            'slug' => 'koncert-sekret-' . Str::random(5),
            'image_url' => self::POSTER,
        ]);

        $response->assertStatus(201);

        $this->assertSame(
            self::POSTER,
            (string) DB::table('events')->where('id', $response->json('data.id'))->value('poster'),
            'image_url не доехал до колонки poster: перенос живёт в живом источнике, а контроллер пишет validated()'
        );
    }

    public function test_updating_an_event_keeps_the_poster_from_image_url(): void
    {
        $organizationId = $this->seedOrganization();
        $this->actingAsAdmin($organizationId);

        $eventId = $this->createEvent($organizationId);

        $response = $this->putJson("/api/v1/events/{$eventId}", [
            'title' => 'Концерт «Секрет»',
            'image_url' => self::POSTER,
        ]);

        $response->assertStatus(200);

        $this->assertSame(
            self::POSTER,
            (string) DB::table('events')->where('id', $eventId)->value('poster'),
            'правка потеряла афишу: image_url есть в validated(), но его нет в Event::$fillable, а poster в validated() не попадает'
        );
    }

    public function test_uploading_a_poster_file_reaches_the_column(): void
    {
        $organizationId = $this->seedOrganization();
        $this->actingAsAdmin($organizationId);

        // Настоящий 1×1 PNG: правила `image`/`mimes:` смотрят на мим-тип и
        // содержимое, а не на расширение.
        $path = tempnam(sys_get_temp_dir(), 'png') . '.png';
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='
        ));
        $file = new UploadedFile($path, 'poster.png', 'image/png', null, true);

        try {
            $response = $this->post('/api/v1/events', [
                'organization_id' => $organizationId,
                'title' => 'Концерт «Секрет»',
                'slug' => 'koncert-sekret-' . Str::random(5),
                'poster_file' => $file,
            ]);
        } finally {
            @unlink($path);
        }

        $response->assertStatus(201);

        $poster = (string) DB::table('events')->where('id', $response->json('data.id'))->value('poster');

        $this->assertNotSame('', $poster, 'загруженный файл афиши не доехал до колонки poster');
        $this->assertStringContainsString('/storage/posters/', $poster);
    }

    // ── фикстуры ────────────────────────────────────────────────────────────

    private function seedOrganization(): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Сургут-Концерт',
            'slug' => 'surgut-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function actingAsAdmin(int $organizationId): User
    {
        $now = now();

        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'status' => 'active',
            'locale' => 'ru',
            'timezone' => 'UTC',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $roleId = DB::table('roles')->where('slug', 'admin')->value('id');

        if ($roleId === null) {
            $roleId = (int) DB::table('roles')->insertGetId([
                'name' => 'Администратор',
                'slug' => 'admin',
            ]);
        }

        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => (int) $roleId,
            'created_at' => $now,
        ]);

        DB::table('user_organization')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => (int) $roleId,
            'created_at' => $now,
        ]);

        $user = User::query()->findOrFail($userId);
        $this->actingAs($user, 'api');

        return $user;
    }

    private function createEvent(int $organizationId): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('events')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'title' => 'Концерт «Секрет»',
            'slug' => 'koncert-sekret-' . Str::random(5),
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
