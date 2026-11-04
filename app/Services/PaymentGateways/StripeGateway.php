<?php

namespace App\Services\PaymentGateways;

use App\Interfaces\PaymentGatewayInterface;
use App\Support\Money;
use Exception;
use Stripe\StripeClient;

class StripeGateway implements PaymentGatewayInterface
{
    public function __construct(private readonly StripeClient $stripeClient) {}

    public function processPayment(float $amount, array $paymentDetails): array
    {
        try {
            $charge = $this->stripeClient->charges->create([
                'amount' => (int) round($amount * 100),
                'currency' => strtolower(Money::currency()),
                'source' => $paymentDetails['token'],
                'description' => 'Payment transaction',
            ]);

            if ($charge->status === 'succeeded') {
                return ['success' => true, 'transaction_id' => $charge->id];
            }

            return ['success' => false, 'error' => 'Payment failed'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function processSubscription(string $planId, array $subscriptionDetails): array
    {
        // Not wired to Stripe. Fail rather than report a subscription that was never created.
        return ['success' => false, 'error' => 'Stripe subscriptions are not implemented through this gateway.'];
    }

    public function refundPayment(string $transactionId, float $amount): array
    {
        try {
            $refund = $this->stripeClient->refunds->create([
                'charge' => $transactionId,
                'amount' => (int) round($amount * 100),
            ]);

            if ($refund->status === 'succeeded') {
                return ['success' => true, 'refund_id' => $refund->id];
            }

            return ['success' => false, 'error' => 'Refund failed'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
