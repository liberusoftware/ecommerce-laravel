<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Notifications\TransactionSuccessNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The payment receipt states the amount in the money it was taken in, not dollars by default. */
class TransactionSuccessNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_amount_is_in_the_stores_currency(): void
    {
        Store::query()->update(['currency_code' => 'GBP']);

        $mail = (new TransactionSuccessNotification(['transaction_id' => 'ch_1', 'amount' => 12.5, 'currency' => 'GBP']))->toMail(null);

        $this->assertContains('Amount: £12.50', $mail->introLines);
    }
}
