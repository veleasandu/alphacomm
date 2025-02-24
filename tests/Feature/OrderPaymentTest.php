<?php

namespace Tests\Feature;

use App\Jobs\ProcessPayment;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payment\PaymentServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrderPaymentTest extends TestCase
{
    use RefreshDatabase, WithFaker;

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
    public function it_can_list_orders(): void
    {
        // Create orders with different statuses
        $pendingOrders = Order::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'status' => Order::STATUS_PENDING
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/orders');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'user_id',
                        'amount',
                        'status',
                        'created_at',
                        'updated_at'
                    ]
                ]
            ]);

        // Test status filter
        $paidOrder = Order::factory()->create([
            'user_id' => $this->user->id,
            'status' => Order::STATUS_PAID
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/orders?status=paid');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $paidOrder->id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_show_order_details(): void
    {
        $order = Order::factory()->create([
            'user_id' => $this->user->id
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/orders/{$order->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'user_id',
                    'amount',
                    'status',
                    'created_at',
                    'updated_at'
                ]
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_create_an_order()
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/orders', [
                'user_id' => $this->user->id,
                'amount' => 99.99
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'user_id',
                    'amount',
                    'status'
                ]
            ]);

        $this->assertDatabaseHas('orders', [
            'user_id' => $this->user->id,
            'amount' => 99.99,
            'status' => Order::STATUS_PENDING
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_queues_payment_processing_job()
    {
        Queue::fake();

        $order = Order::factory()->create([
            'user_id' => $this->user->id,
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

        $response = $this->actingAs($this->user, 'api')
            ->postJson("/api/orders/{$order->id}/pay", $paymentDetails);

        // Assert response indicates payment is being processed
        $response->assertStatus(202)
            ->assertJson([
                'message' => 'Payment processing initiated',
                'data' => [
                    'status' => 'processing'
                ]
            ]);

        // Assert order status remains pending
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => Order::STATUS_PENDING
        ]);

        // Assert payment job was queued
        Queue::assertPushed(ProcessPayment::class, function ($job) use ($order, $paymentDetails) {
            return $job->getOrder()->id === $order->id &&
                   $job->getPaymentDetails() === $paymentDetails;
        });
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_validates_payment_details_before_queueing()
    {
        Queue::fake();

        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'status' => Order::STATUS_PENDING
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson("/api/orders/{$order->id}/pay", [
                'payment_method' => 'card',
                'payment_details' => [
                    // Missing required fields
                ]
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'payment_details.number',
                'payment_details.expiry',
                'payment_details.cvv'
            ]);

        // Assert no job was queued
        Queue::assertNothingPushed();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_prevents_processing_already_paid_orders()
    {
        Queue::fake();

        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'amount' => 99.99,
            'status' => Order::STATUS_PAID
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson("/api/orders/{$order->id}/pay", [
                'payment_method' => 'card',
                'payment_details' => [
                    'number' => '4242424242424242',
                    'expiry' => '12/25',
                    'cvv' => '123'
                ]
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Order cannot be processed',
                'reason' => 'Current status: paid'
            ]);

        // Assert no job was queued
        Queue::assertNothingPushed();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_webhooks_correctly()
    {
        $order = Order::factory()->create([
            'status' => Order::STATUS_PENDING
        ]);

        $transaction = Transaction::factory()->create([
            'order_id' => $order->id,
            'status' => Transaction::STATUS_PENDING,
            'response_data' => ['transaction_id' => 'mock_tx_123']
        ]);

        // Mock payment service
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
    public function it_requires_authentication_for_protected_routes()
    {
        $response = $this->postJson('/api/orders', [
            'user_id' => $this->user->id,
            'amount' => 99.99
        ]);

        $response->assertStatus(401);
    }
}
