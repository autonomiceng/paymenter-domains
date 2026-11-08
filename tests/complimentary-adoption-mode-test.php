<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/adoption-confirm.php';
use Paymenter\Extensions\Servers\Domains\Models\{Adoption,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccounts,DomainBilling,NativeDomainGuard,HolderPricing};
extract(adoptionCommitFixture());
ComplimentaryAccounts::set($owner->id,$staff,true,'Cover future imported renewals',$clock);
$ready=$reader->preview($target,$context);same('ready',$ready->code,'Complimentary customer has a real ready import');
$a=Adoption::findOrFail($ready->previewId);$money=scenarioMoney();
$d=$commit->confirm($a->request_key,$a->request_digest,$staff);
same('complimentary',$d->price_mode,'A new imported holding inherits current account funding');
same($money,scenarioMoney(),'Import creates no invoice, payment or credit');
same(0,Operation::count(),'Import grants no provider operation');
$clock->time=new DateTimeImmutable('2027-09-29T12:00:00Z');
$bindings->setProcessing($binding->serverId,true,syntheticActivation());
activeCommerceFacts($clock->now(),$clock->now()->modify('+30 days'));
$billing=new DomainBilling($bindings,new NativeDomainGuard(new HolderPricing($prices),$clock),$clock);
App\Models\Server::findOrFail($binding->serverId)->settings()->updateOrCreate(['key'=>'domains_manual_renewals_enabled'],['value'=>true,'type'=>'boolean']);
$invoice=$billing->invoice($d->id,$owner);$op=Operation::where('invoice_id',$invoice->id)->sole();
same(['complimentary','paid',0.0,0],[$op->funding_basis,$invoice->status,(float)$invoice->total,$invoice->transactions()->count()],'First imported renewal gets a paid zero receipt');
ComplimentaryAccounts::set($owner->id,$staff,false,'Future terms return to retail',$clock);
same($invoice->id,$billing->invoice($d->id,$owner)->id,'Switch-off retains the already issued complimentary term');
same(0,App\Models\Credit::count(),'Adoption and first renewal invent no customer credit');
echo "Complimentary import and first native renewal passed\n";$nativeSuccessful=true;
