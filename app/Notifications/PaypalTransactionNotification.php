<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaypalTransactionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $transactionDetails;

    public function __construct($transactionDetails)
    {
        $this->transactionDetails = $transactionDetails;
    }

    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        $mailMessage = (new MailMessage)
            ->subject('PayPal Transaction Notification')
            ->line('This is a notification regarding your recent PayPal transaction.')
            ->line('Transaction Type: '.$this->transactionDetails['type'])
            ->line('Amount: '.$this->transactionDetails['amount']);

        if ($this->transactionDetails['type'] === 'subscription_renewal') {
            $mailMessage->line('Your subscription has been successfully renewed.');
        } elseif ($this->transactionDetails['type'] === 'upcoming_charge') {
            $mailMessage->line('You have an upcoming charge for your subscription.');
        } elseif ($this->transactionDetails['type'] === 'subscription_cancellation') {
            $mailMessage->line('Your subscription has been cancelled.');
        } else {
            $mailMessage->line('Your payment was successful.');
        }

        return $mailMessage->action('View Details', url('/transactions'));
    }

    public function toArray($notifiable)
    {
        return [
            'transaction_type' => $this->transactionDetails['type'],
            'amount' => $this->transactionDetails['amount'],
            'message' => 'Your PayPal transaction was successful.',
        ];
    }
}
