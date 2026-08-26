<?php

namespace Tests\Fixtures\Surfaces;

use Laravel\Ai\Contracts\Surface;

class ReceiptCard implements Surface
{
    public function __construct(public readonly string $total) {}

    public static function name(): string
    {
        return 'receipt-card';
    }

    public static function actions(): array
    {
        return [];
    }

    public function toArray(): array
    {
        return ['total' => $this->total];
    }
}
