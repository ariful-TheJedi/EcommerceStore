<?php

namespace App\Payments\Domain\Services;

use App\Orders\Models\Order;
use Illuminate\Support\Facades\DB;

class CodOrderPayments
{
    public function initialize(Order $order): void
    {
        DB::table('payments')->insert([
            'order_id' => $order->id, 'provider' => 'cod', 'amount' => $order->total_amount,
            'currency' => $order->currency, 'status' => 'pending', 'obligation_status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function cancelled(Order $order): void
    {
        DB::table('payments')->where('order_id', $order->id)->where('provider', 'cod')
            ->where('status', 'pending')->update(['obligation_status' => 'closed', 'updated_at' => now()]);
        DB::table('payments')->where('order_id', $order->id)->where('status', 'successful')
            ->update(['obligation_status' => 'refund_required', 'updated_at' => now()]);
    }
}
