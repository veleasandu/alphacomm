<?php

namespace App\Providers;

use App\Services\Payment\StripePaymentProvider;
use App\Services\Payment\PaymentServiceInterface;
use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentServiceInterface::class, function ($app) {
            return new StripePaymentProvider();
        });

        // Register config
        $this->mergeConfigFrom(
            __DIR__.'/../../config/payment.php', 'payment'
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__.'/../../config/payment.php' => config_path('payment.php'),
        ], 'payment-config');
    }
}
