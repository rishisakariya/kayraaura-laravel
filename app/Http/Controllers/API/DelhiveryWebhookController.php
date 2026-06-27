<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDelhiveryWebhookJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DelhiveryWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('delhivery.webhook_secret');

        if ($secret && !hash_equals($secret, (string) $request->header('X-Delhivery-Webhook-Secret'))) {
            Log::warning('Delhivery flow: webhook rejected due to invalid secret');

            return response()->json([
                'status' => false,
                'message' => 'Invalid webhook secret',
            ], 401);
        }

        ProcessDelhiveryWebhookJob::dispatch($request->all());

        Log::info('Delhivery flow: webhook accepted and queued', [
            'waybill' => $request->input('waybill') ?? $request->input('awb'),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Webhook accepted',
        ]);
    }
}
