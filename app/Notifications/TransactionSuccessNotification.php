<?php

namespace App\Notifications;

use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TransactionSuccessNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $transactionDetails;

    public function __construct(array $transactionDetails)
    {
        $this->transactionDetails = $transactionDetails;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Transaction Successful')
            ->greeting('Hello!')
            ->line('Your transaction has been successfully processed.')
            ->line('Transaction ID: '.$this->transactionDetails['transaction_id'])
            ->line('Amount: '.Money::format($this->transactionDetails['amount'], $this->transactionDetails['currency'] ?? null))
            ->action('View Transaction', url('/transactions/'.$this->transactionDetails['transaction_id']))
            ->line('Thank you for using our application!');
    }

    public function toArray($notifiable)
    {
        return [
            'transaction_id' => $this->transactionDetails['transaction_id'],
            'amount' => $this->transactionDetails['amount'],
        ];
    }
}
