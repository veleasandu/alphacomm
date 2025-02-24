<?php

namespace App\Services\Payment;

use App\Models\Order;

interface PaymentServiceInterface
{
    /**
     * Process a payment for an order
     *
     * @throws \Exception If payment processing fails
     */
    public function processPayment(Order $order, array $paymentDetails): array;

    /**
     * Verify a payment's status
     */
    public function verifyPayment(string $transactionId): array;

    /**
     * Handle webhook notifications from payment provider
     */
    public function handleWebhook(array $payload): array;
}
