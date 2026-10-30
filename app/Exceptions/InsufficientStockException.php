<?php

namespace App\Exceptions;

class InsufficientStockException extends CheckoutException
{
    public static function make(): self
    {
        return new self('Requested quantity exceeds available stock.');
    }
}
