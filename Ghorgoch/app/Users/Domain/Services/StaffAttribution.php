<?php

namespace App\Users\Domain\Services;

use App\Orders\Models\Order;
use App\Payments\Models\Payment;
use App\Users\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class StaffAttribution
{
    public function savingOrder(Order $order): void
    {
        $actor = $this->actor();
        if (! $actor) {
            abort_if(Auth::check() && $order->isDirty('processed_by'), 403);

            return;
        }

        Gate::forUser($actor)->authorize('orders.process');
        if (! $order->exists || $order->isDirty('status')) {
            $order->processed_by = $actor->id;
        } else {
            $order->processed_by = $order->getOriginal('processed_by');
        }
    }

    public function savingPayment(Payment $payment): void
    {
        $actor = $this->actor();
        if (! $actor) {
            abort_if(Auth::check() && $payment->isDirty(['recorded_by', 'reconciled_by']), 403);

            return;
        }

        Gate::forUser($actor)->authorize($payment->exists ? 'payments.reconcile' : 'payments.record');
        if (! $payment->exists) {
            $payment->recorded_by = $actor->id;
            $payment->reconciled_by = null;
        } else {
            $payment->recorded_by = $payment->getOriginal('recorded_by');
            $payment->reconciled_by = $actor->id;
        }
    }

    private function actor(): ?User
    {
        $user = Auth::user();

        return $user instanceof User && in_array($user->role, ['admin', 'staff'], true) ? $user : null;
    }
}
