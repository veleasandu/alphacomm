<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Order::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'amount' => $this->faker->randomFloat(2, 10, 1000),
            'status' => $this->faker->randomElement([
                Order::STATUS_PENDING,
                Order::STATUS_PAID,
                Order::STATUS_FAILED
            ])
        ];
    }

    /**
     * Indicate that the order is pending.
     */
    public function pending(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => Order::STATUS_PENDING
            ];
        });
    }

    /**
     * Indicate that the order is paid.
     */
    public function paid(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => Order::STATUS_PAID
            ];
        });
    }

    /**
     * Indicate that the order has failed.
     */
    public function failed(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => Order::STATUS_FAILED
            ];
        });
    }
}
