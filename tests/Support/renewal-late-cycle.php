<?php
// Native late payment fixture; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/renewal-batch-fixture.php';
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\PricingRule;
$at=static fn(string $when)=>new DateTimeImmutable($when);
$earlyRenewal=static function(string $name,string $when)use($makeRenewal,$clock,$at):array {
    $made=$makeRenewal(placeholderComFixtureName($name),false,1,false,static function()use($clock,$at,$when):void {$clock->instant=$at($when);});
    Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$made[0]->id)->update(['created_at'=>$at($when)->format('Y-m-d H:i:s')]);return $made;
};
// Scheduled replacement cases retain the original scheduled-billing setup.
$product->server->settings()->updateOrCreate(['key'=>'domains_billing_enabled'],['value'=>true,'type'=>'boolean']);
// Money can land after the week: Stripe writes no local row until its first webhook, so a closed invoice can still be paid. It all becomes credit.
config(['settings.credits_auto_use'=>true]);$refunds=new \Paymenter\Extensions\Servers\Domains\Services\Refunds($clock);$cash=static fn()=>PricingRule::minor((string)($user->credits()->first()?->amount??'0.00'));$pay=App\Helpers\ExtensionHelper::class;
$closed=static function(string $name)use($earlyRenewal,$cycles,$clock,$at,$user):array {
    $user->credits()->update(['amount'=>'0.00']);$made=$earlyRenewal($name,'2027-08-01T12:00:00Z');config(['settings.credits_auto_use'=>true]);$clock->instant=$at('2027-08-08T12:00:00Z');$cycles->closeEarlyUnpaid($made[1]->id);same('cancelled',$made[3]->fresh()->status,'The week closes the invoice');
    $clock->instant=$at('2027-08-09T12:00:00Z');return $made;
};
$notes=static fn($domain)=>Operation::where('domain_id',$domain->id)->get()->map(fn($op)=>\Paymenter\Extensions\Servers\Domains\Services\RenewalCycles::lateNote($op))->filter()->values()->all();
$words=static fn(string $amount)=>'Paid after this invoice closed. We added '.$amount.' to account credit. You can apply it on an invoice.';
