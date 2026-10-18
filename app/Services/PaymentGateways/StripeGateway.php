<?php

namespace App\Services\PaymentGateways;

use App\Interfaces\PaymentGatewayInterface;
use Exception;
use Illuminate\Support\Facades\Config;
use Stripe\StripeClient;

class StripeGateway implements PaymentGatewayInterface
{
    private $stripeClient;

    public function __construct()
    {
        $this->stripeClient = new StripeClient(Config::get('services.stripe.secret'));
    }

    public function processPayment(float $amount, array $paymentDetails): array
    {
        try {
            $charge = $this->stripeClient->charges->create([
                'amount' => $amount * 100, // Stripe expects amount in cents
                'currency' => 'usd',
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
                'amount' => $amount * 100, // Stripe expects amount in cents
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
