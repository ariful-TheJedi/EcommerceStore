<?php

namespace App\Orders\Domain\Services;

use App\Orders\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OrderReportService
{
    /** @param array<string, mixed> $filters */
    public function query(array $filters): Builder
    {
        $query = Order::query();
        foreach (['customer_id', 'status', 'payment_status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(fn (Builder $query) => $query->where('order_number', 'like', $search)
                ->orWhereHas('items', fn (Builder $query) => $query->where('product_name', 'like', $search)->orWhere('sku', 'like', $search)));
        }
        if (isset($filters['phone'])) {
            $phone = '%'.$filters['phone'].'%';
            $query->where(fn (Builder $query) => $query->where('contact_snapshot->phone', 'like', $phone)
                ->orWhere('shipping_address->phone', 'like', $phone)->orWhere('guest_phone', 'like', $phone));
        }
        if (isset($filters['from'])) {
            $query->where('orders.created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (isset($filters['to'])) {
            $query->where('orders.created_at', '<', Carbon::parse($filters['to'])->addDay()->startOfDay());
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters): array
    {
        $query = $this->query($filters)->withTrashed();
        $counts = (clone $query)->select('status')->selectRaw('COUNT(*) AS count')->groupBy('status')->pluck('count', 'status')->map(fn ($count): int => (int) $count)->all();
        $units = DB::table('order_items')->select('order_id')->selectRaw('SUM(quantity) AS units')->groupBy('order_id');
        $rows = (clone $query)->leftJoinSub($units, 'item_totals', 'item_totals.order_id', '=', 'orders.id')
            ->select('currency')
            ->selectRaw("SUM(CASE WHEN status = 'delivered' THEN COALESCE(units, 0) ELSE 0 END) AS delivered_items")
            ->selectRaw("SUM(CASE WHEN status = 'returned' THEN COALESCE(units, 0) ELSE 0 END) AS returned_items")
            ->selectRaw("SUM(CASE WHEN status = 'delivered' THEN ROUND((total_amount - tax_amount) * 100) ELSE 0 END) AS income")
            ->selectRaw('SUM(ROUND(expense_amount * 100)) AS operating_expenses')
            ->selectRaw("SUM(CASE WHEN status = 'delivered' OR (status = 'returned' AND return_restocked = false) THEN ROUND(COALESCE(product_cost, 0) * 100) ELSE 0 END) AS product_expenses")
            ->selectRaw("SUM(CASE WHEN (status = 'delivered' OR (status = 'returned' AND return_restocked = false)) AND product_cost IS NULL THEN 1 ELSE 0 END) AS missing_cost_orders")
            ->groupBy('currency')->orderBy('currency')->get();

        return ['total_orders' => array_sum($counts), 'counts' => $counts, 'date_basis' => 'Order placed date (UTC)',
            'currencies' => $rows->map(function ($row): array {
                $expenses = (int) $row->operating_expenses + (int) $row->product_expenses;

                return ['currency' => $row->currency, 'delivered_items' => (int) $row->delivered_items,
                    'returned_items' => (int) $row->returned_items, 'income' => $this->decimal((int) $row->income),
                    'operating_expenses' => $this->decimal((int) $row->operating_expenses),
                    'product_expenses' => $this->decimal((int) $row->product_expenses), 'expenses' => $this->decimal($expenses),
                    'missing_cost_orders' => (int) $row->missing_cost_orders,
                    'profit' => $row->missing_cost_orders > 0 ? null : $this->decimal((int) $row->income - $expenses)];
            })->all()];
    }

    private function decimal(int $minor): string
    {
        return ($minor < 0 ? '-' : '').intdiv(abs($minor), 100).'.'.str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
    }
}
