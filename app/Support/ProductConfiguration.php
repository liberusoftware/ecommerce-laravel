<?php

namespace App\Support;

/** A validated set of choices for one product, priced from the catalogue. */
final class ProductConfiguration
{
    /**
     * @param  list<array{choice_id: int, group: string, name: string, price_adjustment: float}>  $options
     */
    public function __construct(
        public readonly array $options,
        public readonly float $unitPrice,
        public readonly string $key,
    ) {}
}
