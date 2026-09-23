<?php

declare(strict_types=1);

namespace Nabilet\Modules\Webhooks\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class WebhookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => null]);
    }

    public function handle(string $provider, Request $request): JsonResponse
    {
        return response()->json(['received' => true]);
    }
}