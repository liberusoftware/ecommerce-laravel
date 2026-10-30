<?php

namespace App\Filament\App\Resources\Orders\Pages;

use App\Filament\App\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Services\CheckoutService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->transitionAction('markPaid', 'Mark paid', Order::STATUS_PAID),
            $this->transitionAction('markFailed', 'Mark failed', Order::STATUS_FAILED),
            $this->transitionAction('cancel', 'Cancel', Order::STATUS_CANCELLED, onlyFromPending: true),
            $this->transitionAction('startProcessing', 'Start processing', Order::STATUS_PROCESSING),
            $this->transitionAction('complete', 'Complete', Order::STATUS_COMPLETED),
            DeleteAction::make(),
        ];
    }

    /** Status, payment status and total are changed only by the actions above. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return Arr::only($data, ['shipping_status']);
    }

    /**
     * Refunds and supplier states are not offered here: they move money or
     * stock through their own workflows. Cancelling a paid order needs a refund.
     */
    private function transitionAction(string $name, string $label, string $to, bool $onlyFromPending = false): Action
    {
        return Action::make($name)
            ->label($label)
            ->requiresConfirmation()
            ->visible(function () use ($to, $onlyFromPending): bool {
                $status = $this->getRecord()->status;

                return in_array($to, Order::TRANSITIONS[$status] ?? [], true)
                    && (! $onlyFromPending || $status === Order::STATUS_PENDING);
            })
            ->action(function () use ($to): void {
                /** @var Order $order */
                $order = $this->getRecord();

                DB::transaction(function () use ($order, $to): void {
                    // A pending order holds reserved stock; leaving it without
                    // payment returns the stock, as a failed checkout does.
                    if ($order->status === Order::STATUS_PENDING && in_array($to, [Order::STATUS_FAILED, Order::STATUS_CANCELLED], true)) {
                        $lines = $order->items->map(fn ($item) => ['product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id, 'quantity' => $item->quantity])->all();
                        app(CheckoutService::class)->releaseStock($order, $lines);
                    }

                    $order->transitionTo($to, auth()->id(), 'Changed in the back office');
                });

                $this->refreshFormData(['status', 'payment_status']);
            });
    }
}
