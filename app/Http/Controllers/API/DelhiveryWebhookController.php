<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDelhiveryWebhookJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DelhiveryWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('delhivery.webhook_secret');

        if ($secret && !hash_equals($secret, (string) $request->header('X-Delhivery-Webhook-Secret'))) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid webhook secret',
            ], 401);
        }

        ProcessDelhiveryWebhookJob::dispatch($request->all());

        return response()->json([
            'status' => true,
            'message' => 'Webhook accepted',
        ]);
    }
}
