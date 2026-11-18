<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/observation-records-ready.php';
use App\Models\{BillingAgreement, Gateway, Invoice};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, DomainObservation};

class MissingHoldingGateway
{
    public static int $calls=0;
    public function __construct(array $settings) {}
    public function charge(Invoice $invoice, $amount, BillingAgreement $agreement): bool
    { same(0,DB::transactionLevel(),'Payment transport stays outside locks'); self::$calls++; return false; }
}
class_alias(MissingHoldingGateway::class,'Paymenter\\Extensions\\Gateways\\MissingHoldingDouble\\MissingHoldingDouble');
$gateway=Gateway::create(['name'=>'Synthetic','extension'=>'MissingHoldingDouble','type'=>'gateway','enabled'=>true]);
$agreement=BillingAgreement::create(['user_id'=>$user->id,'gateway_id'=>$gateway->id,'name'=>'Synthetic','external_reference'=>'synthetic']);
$d->service->update(['billing_agreement_id'=>$agreement->id]);
foreach(['domains_billing_enabled','domains_manual_renewals_enabled'] as $key){$product->server->settings()->updateOrCreate(['key'=>$key],['value'=>true,'type'=>'boolean']);}
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');
$billing=new DomainBilling($bindings,$guard,$clock,$caps??null);
$read=static function(string $fault='',?DomainObservation $reader=null)use($d,$observer,$observation,$clock):string{
    $observation->fault=$fault;
    $observation->during=$fault==='timeout'?static function()use($clock):void{$clock->instant=$clock->now()->modify('+251 seconds');}:null;
    $clock->instant=$clock->now()->modify('+61 seconds');
    return ($reader??$observer)->run($d->id,readRecords:true);
};

// Create the renewal while ownership is current, then save the registrar's definite absence.
$issueDepartureRenewal=static function()use($read,$billing,$d):Invoice {
    if($read()!=='refreshed'){throw new RuntimeException('Departure fixture could not refresh the holding');}
    $invoice=$billing->invoice($d->id);
    if($read('absent')!=='domain_not_in_account'){throw new RuntimeException('Departure fixture could not establish registrar absence');}
    return $invoice;
};
