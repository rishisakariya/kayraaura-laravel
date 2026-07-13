<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\RazorpayPaymentVerifyRequest;
use App\Http\Resources\OrderResource;
use App\Jobs\CreateDelhiveryShipmentJob;
use App\Models\Order;
use App\Models\RazorpayPaymentLog;
use App\Services\CheckoutService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RazorpayController extends Controller
{
    public function __construct(private readonly CheckoutService $checkoutService)
    {
    }

    public function verifyPayment(RazorpayPaymentVerifyRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $order = Order::where('user_id', Auth::id())->findOrFail($payload['order_id']);

        Log::channel('thirdparty')->info('Payment flow: verify payment requested', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'razorpay_order_id' => $payload['razorpay_order_id'],
            'razorpay_payment_id' => $payload['razorpay_payment_id'],
        ]);

        if ($order->razorpay_order_id !== $payload['razorpay_order_id']) {
            return $this->paymentError($order, $payload, 'Razorpay order id does not match local order');
        }

        $signatureVerified = $this->checkoutService->verifyPaymentSignature(
            $payload['razorpay_order_id'],
            $payload['razorpay_payment_id'],
            $payload['razorpay_signature']
        );

        if (!$signatureVerified) {
            return $this->paymentError($order, $payload, 'Invalid Razorpay payment signature');
        }

        try {
            $verifiedOrder = DB::transaction(function () use ($order, $payload) {
                $paymentPayload = $this->checkoutService->fetchRazorpayPayment($payload['razorpay_payment_id']);
                $this->checkoutService->assertPaymentAmountMatches($order, $paymentPayload);

                RazorpayPaymentLog::create([
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'razorpay_order_id' => $payload['razorpay_order_id'],
                    'razorpay_payment_id' => $payload['razorpay_payment_id'],
                    'event_type' => 'payment.verify',
                    'status' => $paymentPayload['status'] ?? 'verified',
                    'response_payload' => [
                        'callback' => $payload,
                        'payment' => $paymentPayload,
                    ],
                    'signature_verified' => true,
                ]);

                return $this->checkoutService->markOrderPaid($order, $payload);
            });

            $statusCode = $verifiedOrder->payment_status === 'paid_stock_failed' ? 409 : 200;

            if ($verifiedOrder->payment_status === 'paid') {
                CreateDelhiveryShipmentJob::dispatch($verifiedOrder->id);

                Log::channel('thirdparty')->info('Payment flow: verify payment succeeded, shipment job dispatched', [
                    'order_id' => $verifiedOrder->id,
                    'order_number' => $verifiedOrder->order_number,
                ]);
            }

            return response()->json([
                'status' => $verifiedOrder->payment_status === 'paid',
                'data' => new OrderResource($verifiedOrder->load(['orderItems.product', 'orderItems.productSize', 'shipment'])),
                'message' => $verifiedOrder->payment_status === 'paid'
                    ? 'Payment verified successfully'
                    : 'Payment captured but stock is no longer available',
            ], $statusCode);
        } catch (DomainException $e) {
            return $this->paymentError($order, $payload, $e->getMessage());
        }
    }

    public function webhook(Request $request): JsonResponse
    {
        $rawPayload = $request->getContent();
        $webhookPayload = $request->all();
        $signature = $request->header('X-Razorpay-Signature');
        $signatureVerified = $this->checkoutService->verifyWebhookSignature($rawPayload, $signature);

        Log::channel('thirdparty')->info('Payment flow: Razorpay webhook received', [
            'event' => $webhookPayload['event'] ?? null,
            'signature_verified' => $signatureVerified,
        ]);

        $order = $this->findOrderFromWebhookPayload($webhookPayload);

        RazorpayPaymentLog::create([
            'order_id' => $order?->id,
            'user_id' => $order?->user_id,
            'razorpay_order_id' => $this->razorpayOrderIdFromWebhookPayload($webhookPayload),
            'razorpay_payment_id' => $this->razorpayPaymentIdFromWebhookPayload($webhookPayload),
            'event_type' => $webhookPayload['event'] ?? null,
            'status' => $signatureVerified ? 'received' : 'invalid_signature',
            'webhook_payload' => $webhookPayload,
            'webhook_signature' => $signature,
            'signature_verified' => $signatureVerified,
        ]);

        if (!$signatureVerified) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid webhook signature',
            ], 400);
        }

        if (!$order) {
            return response()->json([
                'status' => true,
                'message' => 'Webhook received but no matching order was found',
            ]);
        }

        try {
            $processedOrder = DB::transaction(function () use ($order, $webhookPayload) {
                $event = $webhookPayload['event'] ?? null;
                $updatedOrder = null;

                if (in_array($event, ['payment.captured', 'order.paid'], true)) {
                    $payment = $webhookPayload['payload']['payment']['entity'] ?? [];

                    if (!empty($payment)) {
                        $this->checkoutService->assertPaymentAmountMatches($order, $payment);
                    }

                    $updatedOrder = $this->checkoutService->markOrderPaid($order, [
                        'razorpay_order_id' => $this->razorpayOrderIdFromWebhookPayload($webhookPayload),
                        'razorpay_payment_id' => $this->razorpayPaymentIdFromWebhookPayload($webhookPayload),
                        'razorpay_signature' => null,
                    ]);
                }

                if ($event === 'payment.failed') {
                    $this->checkoutService->markOrderPaymentFailed(
                        $order,
                        $this->razorpayPaymentIdFromWebhookPayload($webhookPayload)
                    );
                }

                if ($event === 'refund.processed') {
                    $updatedOrder = $this->checkoutService->settleReturnRefundFromWebhook($order);
                }

                if ($event === 'refund.failed') {
                    $updatedOrder = $this->checkoutService->failReturnRefundFromWebhook($order);
                }

                return $updatedOrder;
            });

            if ($processedOrder?->payment_status === 'paid') {
                CreateDelhiveryShipmentJob::dispatch($processedOrder->id);

                Log::channel('thirdparty')->info('Payment flow: Razorpay webhook processed, shipment job dispatched', [
                    'order_id' => $processedOrder->id,
                    'event' => $webhookPayload['event'] ?? null,
                ]);
            }
        } catch (DomainException $e) {
            Log::channel('thirdparty')->warning('Payment flow: Razorpay webhook processing failed', [
                'order_id' => $order?->id,
                'event' => $webhookPayload['event'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 409);
        }

        return response()->json([
            'status' => true,
            'message' => 'Webhook processed successfully',
        ]);
    }

    private function paymentError(Order $order, array $payload, string $message): JsonResponse
    {
        Log::channel('thirdparty')->warning('Payment flow: verify payment failed', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'razorpay_order_id' => $payload['razorpay_order_id'] ?? null,
            'razorpay_payment_id' => $payload['razorpay_payment_id'] ?? null,
            'error' => $message,
        ]);

        RazorpayPaymentLog::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'razorpay_order_id' => $payload['razorpay_order_id'] ?? null,
            'razorpay_payment_id' => $payload['razorpay_payment_id'] ?? null,
            'event_type' => 'payment.verify',
            'status' => 'failed',
            'response_payload' => $payload,
            'signature_verified' => false,
            'error_description' => $message,
        ]);

        return response()->json([
            'status' => false,
            'message' => $message,
        ], 400);
    }

    private function findOrderFromWebhookPayload(array $payload): ?Order
    {
        $event = $payload['event'] ?? '';

        if (str_starts_with($event, 'refund.')) {
            $paymentId = $payload['payload']['refund']['entity']['payment_id']
                ?? $payload['payload']['payment']['entity']['id']
                ?? null;

            if (!$paymentId) {
                return null;
            }

            return Order::where('razorpay_payment_id', $paymentId)->first();
        }

        $razorpayOrderId = $this->razorpayOrderIdFromWebhookPayload($payload);

        if (!$razorpayOrderId) {
            return null;
        }

        return Order::where('razorpay_order_id', $razorpayOrderId)->first();
    }

    private function razorpayOrderIdFromWebhookPayload(array $payload): ?string
    {
        return $payload['payload']['payment']['entity']['order_id']
            ?? $payload['payload']['order']['entity']['id']
            ?? null;
    }

    private function razorpayPaymentIdFromWebhookPayload(array $payload): ?string
    {
        return $payload['payload']['payment']['entity']['id']
            ?? null;
    }
}
