<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\CanonicalName;
use Paymenter\Extensions\Servers\Domains\Models\Domain;

/** One structural identity from the already locked native prefix. Never acquisition proof. */
final class HoldingNativeIdentity
{
    public static function capture(Domain $d, array $n, \Paymenter\Extensions\Servers\Domains\Models\Tld $tld): array
    {
        $s = $n['service']; $p = $n['product']; $plan = $n['plan'];
        $order = $n['orders']->firstWhere('id', $s->order_id);
        $props = $n['properties']->where('key', 'domain'); $m = $n['markers'];
        foreach (['domains_action', 'domains_tld_id', 'domains_catalogue', 'domains_registrar'] as $key) {
            if ($m->where('key', $key)->count() !== 1) { throw new HoldingNativeMarkersUnavailable('Holding product markers changed.'); }
        }
        $action = $m->where('key', 'domains_action')->first()->value;
        $tldId = $m->where('key', 'domains_tld_id')->first()->value;
        $name = CanonicalName::parse($d->domain, [$d->tld]);
        if (!$order || (int) $order->user_id !== (int) $d->user_id || (int) $s->user_id !== (int) $d->user_id
            || (int) $n['user']->id !== (int) $d->user_id || (int) $s->id !== (int) $d->service_id
            || $s->currency_code !== 'USD' || (string) $s->quantity !== '1' || $s->expires_at !== null
            || (int) $p->server_id !== (int) $d->server_id || $name['domain'] !== $d->domain
            || $plan->priceable_type !== $p->getMorphClass() || (int) $plan->priceable_id !== (int) $p->id
            || $plan->type !== 'recurring' || $plan->billing_unit !== 'year' || (int) $plan->billing_period !== 1
            || !$p->hidden || $p->allow_quantity !== 'separated' || !in_array($action, ['register', 'transfer'], true)
            || $tldId !== (string) $tld->id || (int) $tld->server_id !== (int) $d->server_id
            || $tld->tld !== $d->tld || $tld->registrar_key !== $d->registrar_key || $tld->account_fingerprint !== $d->account_fingerprint
            || $p->slug !== NativeCatalogue::slug($tld, $action)
            || $n['configs']->isNotEmpty() || $n['upgrades']->isNotEmpty() || $n['links']->isNotEmpty()
            || $props->count() !== 1 || $props->first()->value !== $d->domain) { throw new \DomainException('Holding native authority changed.'); }
        foreach (['domains_catalogue' => '1', 'domains_tld_id' => $tldId, 'domains_action' => $action, 'domains_registrar' => $d->registrar_key] as $key => $value) {
            $rows = $m->where('key', $key);
            if ($rows->count() !== 1 || $rows->first()->value !== $value || $rows->first()->encrypted || $rows->first()->type !== 'string') {
                throw new \DomainException('Holding product markers changed.');
            }
        }
        return ['user_id' => (int) $s->user_id, 'service_id' => (int) $s->id, 'order_id' => (int) $s->order_id,
            'product_id' => (int) $p->id, 'annual_plan_id' => (int) $plan->id, 'server_id' => (int) $d->server_id,
            'route_action' => $action, 'catalogue_slug' => $p->slug, 'domain_property_id' => (int) $props->first()->id,
            'domain_property' => $d->domain];
    }
}
