<?php
// Offline late-renewal boundaries; MIT, Copyright (c) 2026 Autonomic Engineering.
// Native invoice events, financial freeze and after-commit collection require real Paymenter models and transactions.
declare(strict_types=1);
require __DIR__.'/renewal-execution.php';
require_once __DIR__.'/staff-tlds.php';
use App\Models\{BillingAgreement,Gateway,Invoice};
use Illuminate\Support\Facades\{Cache,Mail};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling,EnomLateRenewal,EnomTestRenewal,EnomTestSendLimitSchema,PricingRule,RenewalAutoPay,StaffSettingsReview};
set_exception_handler(static function(Throwable $e):never{fwrite(STDERR,$e::class.': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);EnomTestSendLimitSchema::install();Mail::fake();
$server=$product->server;$checkout=['domains.enom_identity.environment'=>null,'domains.enom_catalogue.enabled'=>false,'domains.enom_writes.renewal'=>false];
$on=['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.renewal'=>true,'domains.enom_test_sends_per_day'=>10000,'domains.enom_test_sends_per_minute'=>10000];
$lateBilling=new DomainBilling($renewBindings,$guard,$clock,catalogue: $GLOBALS['caps']??null);
$holder=static function(string $suffix)use($makeRenewal,$checkout,$on,$clock,$cycles,$server):array{
    config($checkout);[$old,$d,$b]=$makeRenewal(placeholderComFixtureName('late-'.$suffix.'-placeholder.com'),false);
    $clock->instant=new DateTimeImmutable('2027-10-19T12:00:00Z');$cycles->expireUnpaid($d->id);
    same('renewal_cancelled_unpaid',$old->fresh()->safe_code,'Untouched ordinary debt closes at expiry');
    config($on);$s=$server->settings()->firstOrNew(['key'=>EnomTestRenewal::KEY]);$s->encrypted=true;$s->type='string';$s->value=json_encode(['fingerprint'=>$b->fingerprint,...EnomTestRenewal::protocol($b)]);$s->save();
    return [$d,$b];
};
$actor=staffUser(['admin.domains.view','admin.domains.manage-policy',StaffSettingsReview::permission('late_renewal')]);
$toggle=static function(bool $on)use($actor,$server):void{
    $ticket=StaffSettingsReview::ticket($actor->id,$server->id,null,'late_renewal',StaffSettingsReview::stamp($server->id,null));
    $r=new StaffSettingsReview($actor->id,$server->id,null,'late_renewal',$ticket,'Account renewal review');$r->input(['accept'=>$on]);EnomLateRenewal::review($r,['accept'=>$on]);
};
tldPanel();app()->instance(\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsWrites::class,new \Paymenter\Extensions\Servers\Domains\Services\StaffSettingsWrites($native,$sellers,$renewBindings));
