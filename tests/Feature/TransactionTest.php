<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payment\PaymentServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        
        // Set up test environment
        config([
            'payment.providers.stripe.secret_key' => 'sk_test_mock',
            'payment.providers.stripe.webhook_secret' => 'whsec_mock',
            'payment.providers.stripe.success_rate' => 80,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_show_transaction_details(): void
    {
        $order = Order::factory()->create([
            'user_id' => $this->user->id
        ]);

        $transaction = Transaction::factory()->create([
            'order_id' => $order->id,
            'status' => Transaction::STATUS_PENDING,
            'response_data' => ['transaction_id' => 'mock_tx_123']
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/transactions/{$transaction->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'id',
                'order_id',
                'status',
                'response_data',
                'created_at',
                'updated_at',
                'order' => [
                    'id',
                    'user_id',
                    'amount',
                    'status'
                ]
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_successful_payment_webhook(): void
    {
        $order = Order::factory()->create([
            'status' => Order::STATUS_PENDING
        ]);

        $transaction = Transaction::factory()->create([
            'order_id' => $order->id,
            'status' => Transaction::STATUS_PENDING,
            'response_data' => ['transaction_id' => 'mock_tx_123']
        ]);

        $this->mock(PaymentServiceInterface::class, function ($mock) {
            $mock->shouldReceive('handleWebhook')
                ->once()
                ->andReturn([
                    'received' => true,
                    'processed' => true,
                    'type' => 'payment.succeeded'
                ]);
        });

        $response = $this->postJson('/api/webhooks/payment', [
            'event' => 'payment.succeeded',
            'transaction_id' => 'mock_tx_123',
            'type' => 'payment_intent.succeeded'
        ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'Webhook processed successfully'
            ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => Order::STATUS_PAID
        ]);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'status' => Transaction::STATUS_SUCCESS
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_failed_payment_webhook(): void
    {
        $order = Order::factory()->create([
            'status' => Order::STATUS_PENDING
        ]);

        $transaction = Transaction::factory()->create([
            'order_id' => $order->id,
            'status' => Transaction::STATUS_PENDING,
            'response_data' => ['transaction_id' => 'mock_tx_123']
        ]);

        $this->mock(PaymentServiceInterface::class, function ($mock) {
            $mock->shouldReceive('handleWebhook')
                ->once()
                ->andReturn([
                    'received' => true,
                    'processed' => true,
                    'type' => 'payment.failed'
                ]);
        });

        $response = $this->postJson('/api/webhooks/payment', [
            'event' => 'payment.failed',
            'transaction_id' => 'mock_tx_123',
            'type' => 'payment.failed',
            'reason' => 'Card declined'
        ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'Webhook processed successfully'
            ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => Order::STATUS_FAILED
        ]);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'status' => Transaction::STATUS_FAILED
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_missing_transaction_id_in_webhook(): void
    {
        $this->mock(PaymentServiceInterface::class, function ($mock) {
            $mock->shouldReceive('handleWebhook')
                ->once()
                ->andReturn([
                    'data' => [
                        'object' => [
                            'id' => 'pi_test',
                            'status' => 'succeeded'
                        ]
                    ]
                ]);
        });

        $response = $this->postJson('/api/webhooks/payment', [
            'event' => 'payment.succeeded',
            'type' => 'payment_intent.succeeded'
        ]);

        $response->assertStatus(500)
            ->assertJson([
                'message' => 'Webhook processing failed',
                'error' => 'Transaction ID not found in webhook payload'
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_invalid_transaction_id_in_webhook(): void
    {
        $this->mock(PaymentServiceInterface::class, function ($mock) {
            $mock->shouldReceive('handleWebhook')
                ->once()
                ->andReturn([
                    'received' => true,
                    'processed' => true
                ]);
        });

        $response = $this->postJson('/api/webhooks/payment', [
            'event' => 'payment.succeeded',
            'transaction_id' => 'invalid_tx_id',
            'type' => 'payment_intent.succeeded'
        ]);

        $response->assertStatus(500)
            ->assertJsonStructure([
                'message',
                'error'
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_requires_authentication_for_transaction_details(): void
    {
        $transaction = Transaction::factory()->create();

        $response = $this->getJson("/api/transactions/{$transaction->id}");

        $response->assertStatus(401);
    }
}
