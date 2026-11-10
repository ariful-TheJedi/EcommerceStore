<?php

namespace App\Orders\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = ($request->route()->defaults['order_audience'] ?? null) === 'staff';
        $summary = [
            'id' => $this->id, 'order_number' => $this->order_number, 'status' => $this->status,
            'payment_status' => $this->payment_status, 'payment_method' => $this->payment_method,
            'currency' => $this->currency, 'subtotal' => $this->subtotal, 'discount_amount' => $this->discount_amount,
            'shipping_fee' => $this->shipping_fee, 'tax_amount' => $this->tax_amount, 'total_amount' => $this->total_amount,
            'delivery_status' => $this->delivery_status, 'created_at' => $this->created_at,
        ];
        if ($request->route()->getActionMethod() === 'index') {
            return $summary + ($staff ? ['customer_id' => $this->customer_id, 'contact' => $this->contact_snapshot, 'item_count' => (int) $this->items_sum_quantity] : [])
                + ($staff && $request->boolean('deletion_list') ? [
                    'can_process' => $request->user()->can('orders.process'),
                    'delivery' => json_decode($this->shipping_address, true),
                    'items' => $this->items->map(fn ($item): array => $item->only(['product_name', 'sku', 'quantity', 'unit_price', 'line_total']))->all(),
                ] : []);
        }
        $history = DB::table('order_history')->where('order_id', $this->id)->orderBy('id');
        if (! $staff) {
            $history->whereIn('action', ['placed', 'status', 'delivery']);
        }

        return $summary + ($staff ? ['product_cost' => $this->product_cost, 'expense_amount' => $this->expense_amount, 'return_restocked' => (bool) $this->return_restocked,
            'can_process' => $request->user()->can('orders.process'),
            'allowed_transitions' => config('orders.transitions.'.$this->status, [])] : []) + [
                'contact' => $this->contact_snapshot,
                'delivery' => json_decode($this->shipping_address, true), 'customer_note' => $this->customer_note,
                'delivery_method' => $this->delivery_method, 'courier_name' => $this->courier_name,
                'tracking_reference' => $this->tracking_reference, 'shipped_at' => $this->shipped_at,
                'delivered_at' => $this->delivered_at, 'cancelled_at' => $this->cancelled_at,
                'returned_at' => $this->returned_at,
                'items' => $this->items->map(fn ($item): array => $item->only([
                    'product_id', 'variant_id', 'product_name', 'sku', 'variant_attributes', 'unit_price',
                    'quantity', 'discount_amount', 'line_total', 'currency',
                ]))->all(),
                'history' => $history->get($staff ? ['id', 'action', 'previous_status', 'new_status', 'actor_id', 'note', 'before', 'after', 'created_at'] : ['action', 'previous_status', 'new_status', 'created_at'])->all(),
            ];
    }
}
