<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Payment Provider
    |--------------------------------------------------------------------------
    |
    | The default payment provider that should be used to process payments.
    |
    */
    'default' => env('PAYMENT_PROVIDER', 'mock'),

    /*
    |--------------------------------------------------------------------------
    | Payment Processing Settings
    |--------------------------------------------------------------------------
    |
    | Configure various aspects of payment processing like timeouts and retries.
    |
    */
    'processing' => [
        'timeout' => env('PAYMENT_TIMEOUT', 30), // seconds
        'retry' => [
            'attempts' => env('PAYMENT_RETRY_ATTEMPTS', 3),
            'delay' => env('PAYMENT_RETRY_DELAY', 5), // seconds
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for handling payment provider webhooks.
    |
    */
    'webhooks' => [
        'secret' => env('PAYMENT_WEBHOOK_SECRET'),
        'tolerance' => env('PAYMENT_WEBHOOK_TOLERANCE', 300), // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Providers Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for different payment providers.
    |
    */
    'providers' => [
        'mock' => [
            'class' => App\Services\Payment\MockPaymentProvider::class,
            'success_rate' => env('MOCK_PAYMENT_SUCCESS_RATE', 80),
        ],
        // Add other providers here as needed
        'stripe' => [
            'key' => env('STRIPE_KEY'),
            'secret' => env('STRIPE_SECRET'),
            'secret_key' => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'success_rate' => env('STRIPE_SUCCESS_RATE', 80),
        ],
    ],
];
