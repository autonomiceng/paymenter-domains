<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Persist native Invoice and Operation models, then render the real staff facts and Filament page.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use App\Models\Invoice;
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
staffPanel();[, $d]=staffData(true);$actor=staffUser([Staff::VIEW]);Auth::setUser($actor);
$planned=today()->addDays(120-21)->format('M j, Y');$due=today()->addDays(100);
same($planned,DomainFacts::for($d->fresh())['Next invoice']['value'],'Without an issued invoice staff see the planned issue date');
$invoice=Invoice::withoutEvents(fn()=>Invoice::create(['user_id'=>$d->user_id,'currency_code'=>'USD','status'=>'pending','due_at'=>$due]));
Operation::create(['domain_id'=>$d->id,'service_id'=>$d->service_id,'server_id'=>$d->server_id,'registrar_key'=>$d->registrar_key,
    'account_fingerprint'=>$d->account_fingerprint,'authority_generation'=>$d->authority_generation,'operation_key'=>'staff-open-renewal',
    'invoice_id'=>$invoice->id,'action'=>'renew','term'=>1,'currency'=>'USD','amount'=>'10.99','state'=>'awaiting_payment',
    'base_expires_at'=>$d->provider_expires_at,'policy'=>['calendar'=>['due'=>$due->format('Y-m-d')]]]);
$line='Due '.$due->format('M j, Y');$facts=DomainFacts::for($d->fresh());
same([$line,'Invoice #'.$invoice->id],[$facts['Next invoice']['value'],$facts['Open invoice']['value']],'An issued renewal invoice takes precedence over the future issue date');
Livewire\Livewire::actingAs($actor)->test(ViewDomain::class,['record'=>$d->id])->assertSee($line)->assertDontSee($planned);
$policy=$d->renewal_policy??[];$d->update(['renewal_policy'=>[...$policy,'owner_auto_renew'=>false]]);
same($line,DomainFacts::for($d->fresh())['Next invoice']['value'],'Turning off future renewals does not hide an already-issued invoice');
// Closing this synthetic invoice restores the ordinary future schedule after renewals resume.
DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'paid']);$d->update(['renewal_policy'=>$policy]);
same($planned,DomainFacts::for($d->fresh())['Next invoice']['value'],'A closed invoice no longer replaces the schedule');
$nativeSuccessful=true;echo "Staff Next invoice reflects the open renewal invoice.\n";
