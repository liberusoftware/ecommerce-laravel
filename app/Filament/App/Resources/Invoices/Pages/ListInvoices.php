<?php

namespace App\Filament\App\Resources\Invoices\Pages;

use App\Filament\App\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;
}
