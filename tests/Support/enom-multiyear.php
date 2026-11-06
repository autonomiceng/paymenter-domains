<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/renewal-execution.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Cost, FeedObserved, OfferState, Query, TermSupport};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CostUnit, Environment, Money};
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestSendLimitSchema, RenewalCycles};

// Native debt, funding, seal and completion need the existing financial fixture; a pure rule test cannot
// prove invoice totals, the final no-send boundary or preservation of an incurred charge discrepancy.
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class, $clock);
EnomTestSendLimitSchema::install();
$review = static function ($d) use ($ports, $clock): void {
    $t = Tld::where('server_id', $d->server_id)->where('tld', 'com')->sole();
    $facts = TermSupport::attach('com', OfferState::facts($t->catalogue_facts), TermSupport::REVIEWED_EVIDENCE['enom']['com'] ?? null);
    DB::table('domains_tlds')->where('id', $t->id)->update(['catalogue_facts' => OfferState::encodeFacts($facts)]);
    $b = \Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id', $d->server_id)->sole());
    $q = new Query($b, ['com'], 'renew');
    $ports->apply(new FeedObserved($b, 'com', [new Cost('renew', 2, true, '16.0000', CostUnit::TermTotal,
        'enom_renew_annual_total', $clock->now(), $clock->now()->modify('+30 days'))]), $ports->readOffers($q, Context::Staff)[0]->revision);
    $choices = $GLOBALS['billing']->renewalChoices($d);
    same([1, 2], $choices['terms'], 'Normal owner panel offers reviewed Enom TEST two-year renewal');
    same(\Paymenter\Extensions\Servers\Domains\Services\PricingRule::total($choices['next_annual'], 2), $choices['lines'][2]['total'], 'Panel charges twice annual sell price');
};
