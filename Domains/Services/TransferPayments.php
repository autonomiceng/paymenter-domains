<?php
// Adapted from Enom 444f31c TransferOrders::recoverPaidAt; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
final class TransferPayments
{
    /** Current full funding is checked separately; never recover an edited transaction's first-success time. */
    public static function recover(Operation $op, array $l, \DateTimeImmutable $now): ?string
    {
        $valid = static function ($at) use ($op, $now): ?string {
            try { $d = $at instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($at)
                : new \DateTimeImmutable((string) $at, new \DateTimeZone(date_default_timezone_get())); }
            catch (\Throwable) { return null; }
            return $at && $d <= $now && $d >= $op->created_at ? $d->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM) : null;
        };
        foreach (DB::table('audits')->where('auditable_type', Invoice::class)->where('auditable_id', $op->invoice_id)->orderBy('id')->get() as $audit) {
            $old = json_decode($audit->old_values ?? '{}', true); $new = json_decode($audit->new_values ?? '{}', true);
            if (($new['status'] ?? null) === 'paid' && ($old['status'] ?? null) !== 'paid') { return $valid($audit->created_at); }
        }
        $net = 0; $total = 0;
        foreach ($l['items']->where('invoice_id', $op->invoice_id) as $item) { $total += PricingRule::minor($item->price) * (int) $item->quantity; }
        $rows = $l['transactions']->where('invoice_id', $op->invoice_id)->sortBy(static fn ($t) => $t->created_at?->format('Y-m-d H:i:s.u').sprintf('%020d', $t->id));
        foreach ($rows as $t) {
            if ($t->status !== InvoiceTransactionStatus::Succeeded) { continue; }
            if (!$t->created_at || !$t->updated_at || !$t->created_at->equalTo($t->updated_at)) { return null; }
            $net += PricingRule::minor($t->amount);
            if ($net >= $total) { return $valid($t->created_at); }
        }
        return null;
    }
}
