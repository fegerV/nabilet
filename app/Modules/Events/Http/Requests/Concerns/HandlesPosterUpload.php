<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Http\Requests\Concerns;

use Illuminate\Support\Str;
use Nabilet\Core\Errors\ValidationError;

/**
 * Общая обработка афиши мероприятия для Store/UpdateEventRequest.
 *
 * Логика была продублирована, и в `UpdateEventRequest` её просто НЕ БЫЛО:
 * при редактировании выбранный файл афиши молча игнорировался (в правилах
 * отсутствовало `poster_file`, и никто не сохранял файл на диск). Отсюда жалоба
 * «афиша не сохраняется». Теперь один источник на оба запроса:
 *
 *  - `storeUploadedPoster()` сохраняет `poster_file` на public-диск и подставляет
 *    абсолютный URL в колонку `poster`;
 *  - `normalizePosterMapping()` переносит legacy-поле `image_url` в `poster`.
 *
 * Потребитель обязан вызвать оба метода и в `passedValidation()`, и в
 * `validated()`: `Validator::validated()` перечитывает живой источник данных,
 * поэтому мутация входа до его вызова — единственное, что реально меняет payload.
 */
trait HandlesPosterUpload
{
    /**
     * Сохранить загруженный файл афиши на public-диск и подставить URL в `poster`.
     *
     * Файл имеет приоритет над текстовыми полями: UI шлёт FormData, где
     * `poster_file` и (иногда) пустой `image_url` сосуществуют. Ошибка записи на
     * диск превращается в 422 (ValidationError), а не в 500 — это ожидаемый
     * инфраструктурный отказ (права на storage, не смонтирован volume).
     */
    protected function storeUploadedPoster(): void
    {
        if (! $this->hasFile('poster_file')) {
            return;
        }

        // Идемпотентность: validated() может вызываться повторно.
        if ($this->input('__poster_stored') === true) {
            return;
        }

        $file = $this->file('poster_file');

        $this->request->remove('image_url');
        $this->getInputSource()->remove('image_url');

        try {
            // hashName() даёт имя с расширением, выведенным из реального мим-типа
            // (проверен валидатором `image`/`mimes`) — исходное расширение файла
            // клиенту не верим. storeAs — детерминированный путь posters/<ULID>.<ext>.
            $ext = pathinfo((string) $file->hashName(), PATHINFO_EXTENSION) ?: 'jpg';
            $stored = $file->storeAs('posters', Str::ulid()->toBase32() . '.' . $ext, [
                'disk' => 'public',
                'visibility' => 'public',
            ]);
        } catch (\Throwable $e) {
            throw new ValidationError(
                ['poster_file' => ['Не удалось сохранить файл афиши: ' . $e->getMessage()]],
                'Ошибка загрузки афиши.'
            );
        }

        $url = rtrim((string) config('filesystems.disks.public.url'), '/') . '/' . ltrim((string) $stored, '/');

        $this->merge(['poster' => $url, '__poster_stored' => true]);
        // Из входных данных файл убираем — в Event::create/update попадает только `poster`.
        $this->request->remove('poster_file');
        $this->getInputSource()->remove('poster_file');
    }

    /**
     * Перенести legacy-поле `image_url` в колонку `poster`.
     *
     * Форма исторически слала `image_url`, а колонка называется `poster`; без
     * этого переноса афиша молча терялась. Файл (`storeUploadedPoster`) имеет
     * приоритет: если он уже подставил `poster`, URL не перетирается.
     */
    protected function normalizePosterMapping(): void
    {
        $url = $this->input('image_url', '__missing__');

        if ($url === '__missing__') {
            return;
        }

        $this->request->remove('image_url');
        $this->getInputSource()->remove('image_url');

        if ($url !== null && $this->input('poster') === null) {
            $this->merge(['poster' => $url]);
        }
    }
}
