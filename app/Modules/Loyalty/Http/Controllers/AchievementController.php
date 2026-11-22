<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nabilet\Modules\Loyalty\Services\AchievementService;

final class AchievementController extends Controller
{
    public function show(Request $request, AchievementService $service): JsonResponse
    {
        return response()->json(['data' => $service->forUser($request->user())]);
    }
}
