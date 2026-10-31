<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/adoption-confirm.php';
use App\Models\ServiceCancellation;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Adoption;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks, RenewalStop, DomainBilling};
extract(adoptionCommitFixture());
$r = $reader->preview($target, $context); $a = Adoption::findOrFail($r->previewId);
$d = $commit->confirm($a->request_key, $a->request_digest, $staff);
same(0, ServiceCancellation::count(), 'Renewal-on adoption has no cancellation');
$money = scenarioMoney();
$c = ServiceCancellation::create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'Customer stops next renewal']);
same('end_of_period', $c->fresh()->type, 'Adopted customer can stop renewal before first renewal');
staffPanel(); \Illuminate\Support\Facades\Auth::login($staff);
$d->update(['observation' => ['adoption_review' => ['inside_issue_window' => true]]]);
$facts = \Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::for($d->fresh());
same(['Off', 'Cancels at period end'], [$facts['Auto-renew']['value'], $facts['Auto-renew']['sub']], 'Later stop is the customer cancellation, not the import intent');
expect(!isset($facts['Renewal invoices']), 'Later stop reads as one state');
$l = DB::transaction(fn () => HoldingLocks::take($d));
expect(RenewalStop::stopped($l), 'Later native cancellation stops future renewal');
expect(!RenewalStop::blocksExisting($l), 'End-of-period stop does not disable domain management');
$clock->time = new DateTimeImmutable('2027-09-29T00:00:00Z');
same(null, (new DomainBilling($bindings, $guard, $clock))->invoice($d->id), 'Next scheduled billing honors later cancellation');
same($money, scenarioMoney(), 'Later cancellation makes no charge and preserves history');
$nativeSuccessful = true; echo "Renewal-on adoption accepts later native renewal stop without disabling management\n";
