<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Http\Controllers;

use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\Venues\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class VenueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['organization_id', 'city']);
        $perPage = (int) $request->get('per_page', 20);
        
        $query = Venue::query()->with(['halls', 'organization']);
        
        if (isset($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }
        
        if (isset($filters['city'])) {
            $query->where('city', 'like', '%' . $filters['city'] . '%');
        }
        
        $venues = $query->paginate($perPage);
        
        return response()->json([
            'data' => $venues,
            'meta' => [
                'current_page' => $venues->currentPage(),
                'per_page' => $venues->perPage(),
                'total' => $venues->total(),
                'last_page' => $venues->lastPage(),
            ],
        ]);
    }

    public function show(Venue $venue): JsonResponse
    {
        // Only relations that exist on the model. `translations` and `hallSchemas`
        // were loaded here and neither is defined on `Venue`, so every call threw
        // RelationNotFoundException → 500. Translations live on
        // `venueTranslations`; hall schemas hang off `halls`, not off the venue.
        $venue->load(['halls', 'organization', 'venueTranslations']);

        return response()->json(['data' => $venue]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            // `bail` перед `exists`: колонка BIGINT, а MySQL не отвергает
            // сравнение со строкой, а приводит её (`id = '1abc'` совпадает с 1).
            // Без `bail` правило `exists` успевает отработать и пропускает мусор.
            'organization_id' => ['bail', 'nullable', 'integer', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:venues,slug'],
            'description' => ['nullable', 'string'],
            // Max lengths follow the columns, not a round number: country is
            // VARCHAR(100), region/city VARCHAR(150), address VARCHAR(500). A
            // blanket `max:255` let a 200-char country through validation and then
            // failed in MySQL strict mode as a 500 instead of a 422.
            'country' => ['nullable', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:150'],
            'city' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);

        $data['status'] ??= 'active';

        // Fail closed when there is no organization to attach the venue to. The
        // previous fallback was `Organization::query()->value('id')` — an
        // arbitrary tenant, which would silently file one organization's venue
        // under another's. `organization_id` is NOT NULL, so the alternative to
        // guessing is refusing.
        if (empty($data['organization_id'])) {
            $data['organization_id'] = $request->user()?->organizations()->value('organizations.id');
        }

        if (empty($data['organization_id'])) {
            throw new ValidationError([
                'organization_id' => ['No organization to attach this venue to.'],
            ]);
        }

                // БД требует slug NOT NULL — генерим из name, если не передан.
        if (empty($data['slug'])) {
            $base = \Illuminate\Support\Str::slug($data['name']);
            $data['slug'] = $base;
            $i = 2;
            while (Venue::where('slug', $data['slug'])->exists()) {
                $data['slug'] = $base . '-' . $i++;
            }
        }

        $venue = Venue::create($data);
        $venue->load('organization');

        return response()->json(['success' => true, 'data' => $venue], 201);
    }

    public function update(Request $request, Venue $venue): JsonResponse
    {
        $data = $request->validate([
            // `bail` перед `exists`: колонка BIGINT, а MySQL не отвергает
            // сравнение со строкой, а приводит её (`id = '1abc'` совпадает с 1).
            // Без `bail` правило `exists` успевает отработать и пропускает мусор.
            'organization_id' => ['bail', 'nullable', 'integer', 'exists:organizations,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', 'unique:venues,slug,' . $venue->id],
            'description' => ['nullable', 'string'],
            'country' => ['nullable', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:150'],
            'city' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
        ]);

        $venue->update(array_filter($data, fn ($v) => $v !== null));
        $venue->load('organization');

        return response()->json(['success' => true, 'data' => $venue]);
    }

    public function destroy(Venue $venue): JsonResponse
    {
        $venue->delete();

        return response()->json(['success' => true, 'message' => 'Venue deleted successfully']);
    }
}
