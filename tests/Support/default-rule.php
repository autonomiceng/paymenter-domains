<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

/** Fixture save of Default pricing. The staff writer owns the real one, with review, audit and account by account publish. */
function saveDefaultRule(array $rule, ?\Closure $republish = null): array
{
    Illuminate\Support\Facades\DB::transaction(static fn () => (new Paymenter\Extensions\Servers\Domains\Services\PricingRules)->saveDefaultRuleLocked(Paymenter\Extensions\Servers\Domains\Services\PricingRule::validate($rule)));
    $summary = ['published' => 0, 'failed' => []];
    foreach ($republish ? Paymenter\Extensions\Servers\Domains\Models\Tld::where('use_default_rule', true)->orderBy('id')->get() : [] as $tld) {
        try { $republish($tld); $summary['published']++; } catch (\Throwable) { $summary['failed'][] = '.'.$tld->tld; }
    }
    return $summary;
}
