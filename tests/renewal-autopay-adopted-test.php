<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Committed adopted intent, offline only.
declare(strict_types=1);
require __DIR__.'/Support/adoption-confirm.php';
use App\Models\{BillingAgreement, Gateway, Invoice, ServiceCancellation};
use Paymenter\Extensions\Servers\Domains\Models\Adoption;
use Paymenter\Extensions\Servers\Domains\Services\DomainBilling;
extract(adoptionCommitFixture());
class AdoptedAutoPayGateway
{
    public static int $calls = 0;
    public function __construct(array $settings) {}
    public function charge(Invoice $i, $amount, BillingAgreement $a): bool { self::$calls++; throw new LogicException('No charge permitted'); }
}
class_alias(AdoptedAutoPayGateway::class, 'Paymenter\\Extensions\\Gateways\\AdoptedAutoPayDouble\\AdoptedAutoPayDouble');
$g = Gateway::create(['name'=>'Synthetic', 'extension'=>'AdoptedAutoPayDouble', 'type'=>'gateway', 'enabled'=>true]);
$agreement = BillingAgreement::create(['user_id'=>$owner->id,'gateway_id'=>$g->id,'name'=>'Synthetic','external_reference'=>'synthetic']);
$service->update(['billing_agreement_id'=>$agreement->id]);
// Retained end-of-period intent predates adoption, matching the native-intent fixture.
Illuminate\Support\Facades\DB::table('service_cancellations')->insert(['service_id'=>$service->id,'type'=>'end_of_period',
    'reason'=>'Synthetic renewal off','created_at'=>now(),'updated_at'=>now()]);
$r = $reader->preview($target, $context); $a = Adoption::findOrFail($r->previewId);
expect($a->do_not_renew, 'Preview retains adopted renewal Off');
$d = $commit->confirm($a->request_key, $a->request_digest, $staff);
$clock->time = new DateTimeImmutable('2027-09-29T00:00:00Z');
$before = scenarioMoney();
same(null, (new DomainBilling($bindings,$guard,$clock))->invoice($d->id), 'Scheduled adopted renewal Off creates no debt');
same($before, scenarioMoney(), 'Adopted renewal Off preserves native money');
same(0, AdoptedAutoPayGateway::$calls, 'Saved method never charged for adopted renewal Off');
echo "Committed adopted renewal Off skips saved-method collection.\n";
$nativeSuccessful = true;
