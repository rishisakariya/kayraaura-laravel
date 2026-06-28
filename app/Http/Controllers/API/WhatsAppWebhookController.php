<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    /**
     * Meta's verification handshake (GET). Called once when you click
     * "Verify and save" in the WhatsApp webhook setup. We echo back the
     * hub.challenge value only when the verify token matches our secret.
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $expected = config('services.sms.whatsapp.webhook_verify_token');

        if ($mode === 'subscribe' && $expected && hash_equals((string) $expected, (string) $token)) {
            return response((string) $challenge, 200)
                ->header('Content-Type', 'text/plain');
        }

        Log::channel('thirdparty')->warning('WhatsApp flow: webhook verification rejected', [
            'mode' => $mode,
            'token_matched' => false,
        ]);

        return response('Forbidden', 403);
    }

    /**
     * Incoming events (POST): messages, delivery/read status updates, etc.
     * We acknowledge with 200 immediately so Meta does not retry, and log the
     * payload. There is no inbound message processing required right now.
     */
    public function handle(Request $request): JsonResponse
    {
        Log::channel('thirdparty')->info('WhatsApp flow: webhook event received', [
            'payload' => $request->all(),
        ]);

        return response()->json(['status' => true]);
    }
}
