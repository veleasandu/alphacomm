<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TransactionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Transaction::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'payment_provider' => $this->faker->randomElement(['mock', 'stripe', 'paypal']),
            'status' => $this->faker->randomElement([
                Transaction::STATUS_PENDING,
                Transaction::STATUS_SUCCESS,
                Transaction::STATUS_FAILED
            ]),
            'response_data' => $this->generateResponseData()
        ];
    }

    /**
     * Generate mock response data based on transaction status.
     */
    protected function generateResponseData(): array
    {
        $transactionId = 'tx_' . Str::random(16);
        
        return [
            'transaction_id' => $transactionId,
            'timestamp' => now()->toIso8601String(),
            'payment_method' => $this->faker->randomElement(['card', 'bank_transfer']),
            'card_type' => $this->faker->creditCardType,
            'last4' => $this->faker->numerify('####'),
        ];
    }

    /**
     * Indicate that the transaction is pending.
     */
    public function pending(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => Transaction::STATUS_PENDING,
                'response_data' => array_merge(
                    $this->generateResponseData(),
                    ['status' => 'processing']
                )
            ];
        });
    }

    /**
     * Indicate that the transaction was successful.
     */
    public function successful(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => Transaction::STATUS_SUCCESS,
                'response_data' => array_merge(
                    $this->generateResponseData(),
                    [
                        'status' => 'success',
                        'authorization_code' => Str::random(6),
                        'risk_score' => $this->faker->numberBetween(0, 100)
                    ]
                )
            ];
        });
    }

    /**
     * Indicate that the transaction has failed.
     */
    public function failed(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => Transaction::STATUS_FAILED,
                'response_data' => array_merge(
                    $this->generateResponseData(),
                    [
                        'status' => 'failed',
                        'error_code' => $this->faker->randomElement(['insufficient_funds', 'card_declined', 'expired_card']),
                        'error_message' => $this->faker->sentence()
                    ]
                )
            ];
        });
    }
}
