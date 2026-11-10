<?php

namespace App\Orders\Domain\Services;

use App\Users\Models\User;
use Illuminate\Support\Facades\DB;

class DeliveryChargeService
{
    /** @return array{inside_dhaka: string, outside_dhaka: string, currency: string, version: int} */
    public function rates(bool $lock = false): array
    {
        $query = DB::table('delivery_charge_settings')->where('id', 1);
        if ($lock) {
            $query->lockForUpdate();
        }
        $settings = $query->firstOrFail();

        return ['inside_dhaka' => Money::decimal(Money::minor((string) $settings->inside_dhaka)),
            'outside_dhaka' => Money::decimal(Money::minor((string) $settings->outside_dhaka)),
            'currency' => config('orders.currency'), 'version' => (int) $settings->version];
    }

    /** @param array{inside_dhaka: string, outside_dhaka: string, version: int} $data
     * @return array<string, mixed>
     */
    public function update(User $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data): array {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->is_active && $actor->role === 'admin', 403);
            $before = $this->rates(true);
            abort_unless($before['version'] === (int) $data['version'], 409);
            DB::table('delivery_charge_settings')->where('id', 1)->update([
                'inside_dhaka' => Money::decimal(Money::minor((string) $data['inside_dhaka'])),
                'outside_dhaka' => Money::decimal(Money::minor((string) $data['outside_dhaka'])),
                'version' => $before['version'] + 1, 'updated_by' => $actor->id, 'updated_at' => now(),
            ]);
            $after = $this->rates();
            DB::table('staff_activity')->insert(['actor_id' => $actor->id, 'staff_id' => $actor->id, 'action' => 'delivery_charges_updated',
                'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $after;
        }, 3);
    }
}
