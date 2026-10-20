<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\{Adoption, Domain, HistoryLink};
/** Separate retained associations. Never financial authority, settlement or reconciliation. */
final class AdoptionHistory
{
    public static function link(HoldingIdentityLock $lock, Domain $d, Adoption $a, \DateTimeImmutable $now): void
    {
        $n = $lock->nativeRows((int) $a->user_id);
        if ($a->state !== 'committed' || (int) $a->domain_id !== (int) $d->id || $d->adoption_id !== (int) $a->id) { throw new \DomainException('Adoption history requires its committed holding.'); }
        $links = HistoryLink::where('domain_id', $d->id)->orderBy('id')->lockForUpdate()->get();
        foreach ($a->evidence['history_link_ids'] as $ids) {
            $invoice = $n['invoices']->firstWhere('id', $ids['invoice_id']);
            $item = $n['items']->firstWhere('id', $ids['invoice_item_id']);
            if (!$invoice || !$item || (int) $invoice->user_id !== (int) $d->user_id
                || (int) $item->invoice_id !== (int) $invoice->id || $item->reference_type !== $n['service']->getMorphClass()
                || (int) $item->reference_id !== (int) $d->service_id || !in_array($invoice->status, ['paid', 'cancelled'], true)) {
                throw new \DomainException('Historical invoice relation requires review.');
            }
            $key = HistoryLink::keyFor((int) $d->id, (int) $invoice->id, (int) $item->id);
            if ($links->contains('link_key', $key)) { throw new \DomainException('Historical invoice was already linked.'); }
            $link = HistoryLink::create(['domain_id' => $d->id, 'service_id' => $d->service_id, 'user_id' => $d->user_id,
                'invoice_id' => $invoice->id, 'invoice_item_id' => $item->id, 'source' => 'native_service',
                'linked_by' => $a->actor_id, 'reason' => $a->reason, 'linked_at' => $now,
                'evidence' => ['version' => 1, 'adoption_id' => (int) $a->id, 'request_digest' => $a->request_digest,
                    'relation' => 'exact_native_service', 'status_at_link' => $invoice->status,
                    'invoice_stamp' => AdoptionMapping::stamp($invoice->getRawOriginal()), 'item_stamp' => AdoptionMapping::stamp($item->getRawOriginal())]]);
            if (!$link->exists || !$link->id || !HistoryLink::whereKey($link->id)->where('link_key', $key)->exists()) {
                throw new \DomainException('Historical invoice link was not retained.');
            }
        }
    }
}
