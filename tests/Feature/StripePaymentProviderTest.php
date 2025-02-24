<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\Payment\PaymentServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class StripePaymentProviderTest extends TestCase
{
    use RefreshDatabase;

    private PaymentServiceInterface $paymentService;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Set up test environment variables
        config([
            'payment.providers.stripe.secret_key' => 'sk_test_mock',
            'payment.providers.stripe.success_rate' => 80,
            'payment.providers.stripe.webhook_secret' => 'whsec_mock'
        ]);
        
        $this->paymentService = app(PaymentServiceInterface::class);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_process_a_successful_payment()
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'amount' => 99.99,
            'status' => Order::STATUS_PENDING
        ]);

        $paymentDetails = [
            'payment_method' => 'card',
            'payment_details' => [
                'number' => '4242424242424242',
                'expiry' => '12/25',
                'cvv' => '123'
            ]
        ];

        $paymentIntentId = 'pi_' . Str::random(24);
        $chargeId = 'ch_' . Str::random(24);

        Http::fake([
            'api.stripe.com/v1/payment_intents' => Http::response([
                'id' => $paymentIntentId,
                'object' => 'payment_intent',
                'amount' => 9999, // 99.99 in cents
                'currency' => 'eur',
                'status' => 'succeeded',
                'client_secret' => 'pi_secret_' . Str::random(24),
                'charges' => [
                    'data' => [
                        [
                            'id' => $chargeId,
                            'amount' => 9999,
                            'status' => 'succeeded'
                        ]
                    ]
                ]
            ], 200)
        ]);

        $response = $this->paymentService->processPayment($order, $paymentDetails);
        
        $this->assertEquals('succeeded', $response['status']);
        $this->assertStringStartsWith('pi_', $response['id']);
        $this->assertEquals(9999, $response['amount']); // Amount in cents
        $this->assertEquals('eur', strtolower($response['currency']));
        $this->assertArrayHasKey('charges', $response);
        $this->assertArrayHasKey('data', $response['charges']);
        $this->assertNotEmpty($response['charges']['data']);
        $this->assertStringStartsWith('ch_', $response['charges']['data'][0]['id']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_retries_on_timeout_until_max_attempts()
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'amount' => 99.99,
            'status' => Order::STATUS_PENDING
        ]);

        $paymentDetails = [
            'payment_method' => 'card',
            'payment_details' => [
                'number' => '4242424242424242',
                'expiry' => '12/25',
                'cvv' => '123'
            ]
        ];

        Http::fake([
            'api.stripe.com/v1/payment_intents' => Http::response([
                'error' => [
                    'type' => 'api_error',
                    'message' => 'timeout',
                    'code' => 'timeout'
                ]
            ], 500)
        ]);

        try {
            $this->paymentService->processPayment($order, $paymentDetails);
            $this->fail('Expected timeout exception was not thrown');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Payment failed after 3 attempts', $e->getMessage());
            $this->assertEquals(3, substr_count(strtolower($e->getMessage()), 'timeout'));
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_rate_limiting_with_retries()
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'amount' => 99.99,
            'status' => Order::STATUS_PENDING
        ]);

        $paymentDetails = [
            'payment_method' => 'card',
            'payment_details' => [
                'number' => '4242424242424242',
                'expiry' => '12/25',
                'cvv' => '123'
            ]
        ];

        Http::fake([
            'api.stripe.com/v1/payment_intents' => Http::response([
                'error' => [
                    'type' => 'rate_limit_error',
                    'message' => 'Too many requests',
                    'code' => 'rate_limit_exceeded'
                ]
            ], 429)
        ]);

        try {
            $this->paymentService->processPayment($order, $paymentDetails);
            $this->fail('Expected rate limit exception was not thrown');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Payment failed after 3 attempts', $e->getMessage());
            $this->assertEquals(429, $e->getCode());
            $this->assertStringContainsString('Too many requests', $e->getMessage());
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_different_webhook_events()
    {
        $_SERVER['HTTP_STRIPE_SIGNATURE'] = 'test_signature';
        $eventId = 'evt_' . Str::random(24);
        $paymentIntentId = 'pi_' . Str::random(24);

        // Test successful payment webhook
        $successPayload = [
            'id' => $eventId,
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => $paymentIntentId,
                    'object' => 'payment_intent',
                    'amount' => 9999,
                    'currency' => 'eur',
                    'status' => 'succeeded'
                ]
            ]
        ];

        $response = $this->paymentService->handleWebhook($successPayload);
        $this->assertEquals($eventId, $response['id']);
        $this->assertEquals('payment_intent.succeeded', $response['type']);
        $this->assertEquals('event', $response['object']);
        $this->assertEquals($paymentIntentId, $response['data']['object']['id']);
        $this->assertEquals('succeeded', $response['data']['object']['status']);

        // Test failed payment webhook
        $failurePayload = [
            'id' => $eventId,
            'type' => 'payment_intent.payment_failed',
            'data' => [
                'object' => [
                    'id' => $paymentIntentId,
                    'object' => 'payment_intent',
                    'amount' => 9999,
                    'currency' => 'eur',
                    'status' => 'failed'
                ]
            ]
        ];

        $response = $this->paymentService->handleWebhook($failurePayload);
        $this->assertEquals($eventId, $response['id']);
        $this->assertEquals('payment_intent.payment_failed', $response['type']);
        $this->assertEquals('failed', $response['data']['object']['status']);
        $this->assertArrayHasKey('last_payment_error', $response['data']['object']);
    }
}
