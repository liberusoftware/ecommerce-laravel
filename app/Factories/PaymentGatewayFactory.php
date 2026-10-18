<?php

namespace App\Factories;

use App\Interfaces\PaymentGatewayInterface;
use App\Services\PaymentGateways\PayPalGateway;
use App\Services\PaymentGateways\StripeGateway;
use InvalidArgumentException;

class PaymentGatewayFactory
{
    /** Gateway key => what a shopper sees. */
    public const GATEWAYS = [
        'stripe' => 'Card (Stripe)',
        'paypal' => 'PayPal',
    ];

    /** Whether the deployment holds credentials for a gateway. Credentials are env, not merchant settings. */
    public static function isConfigured(string $gateway): bool
    {
        return match ($gateway) {
            'stripe' => filled(config('services.stripe.key')) && filled(config('services.stripe.secret')),
            'paypal' => filled(config('services.paypal.client_id')) && filled(config('services.paypal.secret')),
            default => false,
        };
    }

    public static function create(string $gateway): PaymentGatewayInterface
    {
        // Resolve through the container so gateways are injectable (tests can bind
        // a fake) and their own dependencies are wired.
        return match ($gateway) {
            'stripe' => app(StripeGateway::class),
            'paypal' => app(PayPalGateway::class),
            default => throw new InvalidArgumentException("Unsupported payment gateway: {$gateway}"),
        };
    }
}
