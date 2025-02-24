<?php

namespace Tests\Feature;

use App\Jobs\ProcessPayment;
use App\Models\Order;
use App\Models\User;
use App\Services\Payment\PaymentServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProcessPaymentJobTest extends TestCase
{
    use RefreshDatabase;

    private PaymentServiceInterface $paymentService;

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
    public function it_processes_payment_through_queue()
    {
        Queue::fake();

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

        // Dispatch the job
        ProcessPayment::dispatch($order, $paymentDetails);

        // Assert job was pushed to the queue
        Queue::assertPushed(ProcessPayment::class, function ($job) use ($order, $paymentDetails) {
            return $job->getOrder()->id === $order->id &&
                   $job->getPaymentDetails() === $paymentDetails;
        });
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_updates_order_and_creates_transaction_on_success()
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

        // Mock payment service
        $this->mock(PaymentServiceInterface::class, function ($mock) {
            $mock->shouldReceive('processPayment')
                ->once()
                ->andReturn([
                    'id' => 'pi_' . \Illuminate\Support\Str::random(24),
                    'status' => 'succeeded',
                    'amount' => 9999,
                    'currency' => 'eur'
                ]);
        });

        // Process the job
        $job = new ProcessPayment($order, $paymentDetails);
        $job->handle(app(PaymentServiceInterface::class));

        // Refresh order from database
        $order->refresh();

        // Assert order was updated
        $this->assertEquals(Order::STATUS_PAID, $order->status);

        // Assert transaction was created
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'status' => 'success'
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_payment_failure()
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

        // Mock payment service
        $this->mock(PaymentServiceInterface::class, function ($mock) {
            $mock->shouldReceive('processPayment')
                ->once()
                ->andThrow(new \Exception('Payment failed', 402));
        });

        try {
            $job = new ProcessPayment($order, $paymentDetails);
            $job->handle(app(PaymentServiceInterface::class));
        } catch (\Exception $e) {
            // Exception is expected
        }

        // Refresh order from database
        $order->refresh();

        // Assert order was marked as failed
        $this->assertEquals(Order::STATUS_FAILED, $order->status);

        // Assert failed transaction was created
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'status' => 'failed'
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_retries_on_transient_failures()
    {
        Queue::fake();

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

        // Create and dispatch job
        $job = new ProcessPayment($order, $paymentDetails);

        // Assert job has correct retry configuration
        $this->assertEquals(3, $job->tries);
        $this->assertEquals([10, 30, 60], $job->backoff);

        // Dispatch the job
        ProcessPayment::dispatch($order, $paymentDetails);

        // Assert job was pushed to the queue
        Queue::assertPushed(ProcessPayment::class, function ($job) use ($order, $paymentDetails) {
            return $job->getOrder()->id === $order->id &&
                   $job->getPaymentDetails() === $paymentDetails &&
                   $job->tries === 3 &&
                   $job->backoff === [10, 30, 60];
        });
    }
}
