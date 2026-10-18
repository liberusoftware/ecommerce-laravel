<?php

namespace App\Filament\App\Resources\Coupons\Pages;

use App\Filament\App\Resources\Coupons\CouponResource;
use App\Filament\Schemas\StoreField;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCoupon extends CreateRecord
{
    protected static string $resource = CouponResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return StoreField::createIn($data, fn (array $data) => parent::handleRecordCreation($data));
    }
}
