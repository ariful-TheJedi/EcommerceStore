<?php

namespace App\Orders\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class OrderChanged implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly int $orderId, public readonly string $status) {}
}
