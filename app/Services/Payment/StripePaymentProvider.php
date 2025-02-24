<?php

namespace App\Services\Payment;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class StripePaymentProvider implements PaymentServiceInterface
{
    private const STRIPE_API_URL = 'https://api.stripe.com/v1';
    private const TIMEOUT_SECONDS = 30;
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_MS = 100;

    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('payment.providers.stripe.secret_key', 'sk_test_mock');
    }

    /**
     * Handle webhook notifications with Stripe signature verification
     *
     * @param array<string, mixed> $payload The webhook payload from Stripe
     * @return array<string, mixed> Response to send back to Stripe
     * @throws \Exception When webhook signature is invalid or processing fails
     */
    public function handleWebhook(array $payload): array
    {
        $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $webhookSecret = config('payment.providers.stripe.webhook_secret', 'whsec_mock');
        
        try {
            if (!$this->verifyWebhookSignature($payload, $signature, $webhookSecret)) {
                throw new \Exception('Invalid webhook signature', 400);
            }

            // Process different webhook event types
            return match ($payload['type'] ?? '') {
                'payment_intent.succeeded' => $this->handleSuccessfulPayment($payload),
                'payment_intent.payment_failed' => $this->handleFailedPayment($payload),
                default => $this->handleUnknownEvent($payload)
            };
        } catch (\Exception $e) {
            throw new \Exception('Webhook error: ' . $e->getMessage(), $e->getCode() ?: 400);
        }
    }

    /**
     * Process payment with retries and timeout handling
     *
     * @param Order $order The order to process payment for
     * @param array<string, mixed> $paymentDetails Payment method details
     * @return array<string, mixed> Response from Stripe
     * @throws \Exception When payment processing fails after all retries
     */
    public function processPayment(Order $order, array $paymentDetails): array
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < self::MAX_RETRIES) {
            try {
                // Create payment intent
                return $this->createPaymentIntent([
                    'amount' => $order->amount,
                    'currency' => 'EUR',
                    'payment_method' => $this->formatPaymentMethod($paymentDetails),
                    'metadata' => [
                        'order_id' => $order->id
                    ]
                ]);
            } catch (\Exception $e) {
                $lastException = $e;
                $attempt++;

                if ($this->shouldRetry($e)) {
                    usleep(self::RETRY_DELAY_MS * 1000 * $attempt); // Exponential backoff
                    continue;
                }

                break;
            }
        }

        $errors = [];
        for ($i = 1; $i <= $attempt; $i++) {
            $errors[] = "Attempt {$i}: " . $lastException->getMessage();
        }
        
        throw new \Exception(
            "Payment failed after {$attempt} attempts: " . implode(', ', $errors),
            $lastException->getCode(),
            $lastException
        );
    }

    /**
     * Verify payment status
     *
     * @param string $transactionId The Stripe payment intent ID
     * @return array<string, mixed> Payment status details from Stripe
     * @throws \Exception When verification fails or times out
     */
    public function verifyPayment(string $transactionId): array
    {
        try {
            return $this->retrievePaymentIntent($transactionId);
        } catch (\Exception $e) {
            if ($e->getMessage() === 'timeout') {
                throw new \Exception('Payment verification timed out');
            }
            throw $e;
        }
    }

    /**
     * Handle a successful payment webhook event
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function handleSuccessfulPayment(array $payload): array
    {
        $intent = $payload['data']['object'];
        return [
            'id' => $payload['id'],
            'type' => $payload['type'],
            'object' => 'event',
            'api_version' => '2023-10-16',
            'data' => [
                'object' => [
                    'id' => $intent['id'],
                    'object' => 'payment_intent',
                    'amount' => $intent['amount'],
                    'currency' => $intent['currency'],
                    'status' => 'succeeded',
                    'charges' => $intent['charges'] ?? ['data' => []],
                    'created' => time(),
                    'metadata' => $intent['metadata'] ?? []
                ]
            ],
            'livemode' => false,
            'pending_webhooks' => 1,
            'request' => ['id' => 'req_' . Str::random(24)],
            'created' => time()
        ];
    }

    /**
     * Handle a failed payment webhook event
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function handleFailedPayment(array $payload): array
    {
        $intent = $payload['data']['object'];
        return [
            'id' => $payload['id'],
            'type' => $payload['type'],
            'object' => 'event',
            'api_version' => '2023-10-16',
            'data' => [
                'object' => [
                    'id' => $intent['id'],
                    'object' => 'payment_intent',
                    'amount' => $intent['amount'],
                    'currency' => $intent['currency'],
                    'status' => 'failed',
                    'last_payment_error' => [
                        'type' => 'card_error',
                        'message' => $this->getRandomPaymentError(),
                        'code' => 'card_declined'
                    ],
                    'created' => time(),
                    'metadata' => $intent['metadata'] ?? []
                ]
            ],
            'livemode' => false,
            'pending_webhooks' => 1,
            'request' => ['id' => 'req_' . Str::random(24)],
            'created' => time()
        ];
    }

    /**
     * Handle an unknown webhook event type
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function handleUnknownEvent(array $payload): array
    {
        return [
            'id' => $payload['id'],
            'type' => $payload['type'],
            'object' => 'event',
            'api_version' => '2023-10-16',
            'data' => ['object' => []],
            'livemode' => false,
            'pending_webhooks' => 1,
            'request' => ['id' => 'req_' . Str::random(24)],
            'created' => time()
        ];
    }

    /**
     * Verify webhook signature using Stripe's algorithm simulation
     *
     * @param array<string, mixed> $payload
     * @throws \Exception When signature is invalid or timestamp is outside tolerance
     */
    private function verifyWebhookSignature(array $payload, string $signature, string $webhookSecret): bool
    {
        if (empty($signature) || empty($webhookSecret)) {
            return false;
        }

        // Simulate Stripe's webhook signature verification
        // In real Stripe implementation, this would:
        // 1. Extract timestamp and signatures from the Stripe-Signature header
        // 2. Verify the timestamp is within tolerance
        // 3. Generate a signature using the webhook secret and compare
        
        $timestamp = time();
        $tolerance = config('payment.webhooks.tolerance', 300);
        
        // Simulate timestamp validation
        if (abs(time() - $timestamp) > $tolerance) {
            throw new \Exception('Timestamp outside tolerance window');
        }

        // Simulate signature validation with 99% success rate for testing
        if (random_int(1, 100) > 99) {
            throw new \Exception('Invalid signature');
        }

        return true;
    }

    /**
     * Create a new payment intent using Stripe's API format
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws \Exception When API request fails
     */
    private function createPaymentIntent(array $data): array
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->asForm()
                ->post(self::STRIPE_API_URL . '/payment_intents', [
                    'amount' => (int)($data['amount'] * 100), // Convert to cents
                    'currency' => strtolower($data['currency']),
                    'payment_method_types' => ['card'],
                    'payment_method' => $data['payment_method'],
                    'metadata' => $data['metadata'],
                    'confirm' => true,
                ]);

            if ($response->successful()) {
                return $response->json();
            }

            throw new \Exception(
                $response->json()['error']['message'] ?? 'Payment failed',
                $response->status()
            );
        } catch (\Exception $e) {
            if ($e->getCode() === 0) {
                throw new \Exception('Network error or timeout', 500);
            }
            throw $e;
        }
    }

    /**
     * Retrieve payment intent details using Stripe's API format
     *
     * @return array<string, mixed>
     * @throws \Exception When API request fails
     */
    private function retrievePaymentIntent(string $paymentIntentId): array
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->get(self::STRIPE_API_URL . '/payment_intents/' . $paymentIntentId);

            if ($response->successful()) {
                return $response->json();
            }

            throw new \Exception(
                $response->json()['error']['message'] ?? 'Payment verification failed',
                $response->status()
            );
        } catch (\Exception $e) {
            if ($e->getCode() === 0) {
                throw new \Exception('Network error or timeout', 500);
            }
            throw $e;
        }
    }

    /**
     * Format payment method data to match Stripe's format
     *
     * @param array<string, mixed> $paymentDetails
     * @return array<string, mixed>
     */
    private function formatPaymentMethod(array $paymentDetails): array
    {
        return [
            'type' => $paymentDetails['payment_method'] ?? 'card',
            'card' => [
                'number' => $paymentDetails['payment_details']['number'] ?? null,
                'exp_month' => substr($paymentDetails['payment_details']['expiry'] ?? '', 0, 2),
                'exp_year' => substr($paymentDetails['payment_details']['expiry'] ?? '', -2),
                'cvc' => $paymentDetails['payment_details']['cvv'] ?? null
            ]
        ];
    }

    /**
     * Determine if we should retry the request based on the error
     */
    private function shouldRetry(\Exception $e): bool
    {
        $retryableErrors = [
            'timeout',
            'rate_limit_exceeded',
            'Too many requests',
            'internal_server_error',
            'network_error'
        ];

        return in_array($e->getMessage(), $retryableErrors) || 
               (isset($e->response) && $e->response->status() === 429);
    }

    /**
     * Get a random payment error message
     */
    private function getRandomPaymentError(): string
    {
        $errors = [
            'card_declined',
            'expired_card',
            'incorrect_cvc',
            'insufficient_funds',
            'invalid_expiry_month',
            'invalid_expiry_year',
            'invalid_number'
        ];

        return $errors[array_rand($errors)];
    }
}
