<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Transaction;
use App\Services\Payment\PaymentServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = [10, 30, 60];

    /**
     * Create a new job instance.
     */
    public function __construct(
        private Order $order,
        private array $paymentDetails
    ) {}

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getPaymentDetails(): array
    {
        return $this->paymentDetails;
    }

    /**
     * Execute the job.
     */
    public function handle(PaymentServiceInterface $paymentService): void
    {
        try {
            DB::beginTransaction();

            // Create initial transaction record
            $transaction = Transaction::create([
                'order_id' => $this->order->id,
                'payment_provider' => config('payment.default'),
                'status' => Transaction::STATUS_PENDING,
                'response_data' => ['payment_method' => $this->paymentDetails['payment_method']]
            ]);

            // Process payment
            $paymentResponse = $paymentService->processPayment($this->order, $this->paymentDetails);

            // Update transaction with success response
            $transaction->update([
                'status' => Transaction::STATUS_SUCCESS,
                'response_data' => array_merge(
                    $transaction->response_data,
                    ['payment_response' => $paymentResponse]
                )
            ]);

            // Update order status
            $this->order->update(['status' => Order::STATUS_PAID]);

            DB::commit();

            // Log success
            Log::info('Payment processed successfully', [
                'order_id' => $this->order->id,
                'transaction_id' => $transaction->id
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            // Create failed transaction record
            Transaction::create([
                'order_id' => $this->order->id,
                'payment_provider' => config('payment.default'),
                'status' => Transaction::STATUS_FAILED,
                'response_data' => [
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'payment_method' => $this->paymentDetails['payment_method']
                ]
            ]);

            // Update order status
            $this->order->update(['status' => Order::STATUS_FAILED]);

            // Log error
            Log::error('Payment processing failed', [
                'order_id' => $this->order->id,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Payment job failed', [
            'order_id' => $this->order->id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);
    }
}
