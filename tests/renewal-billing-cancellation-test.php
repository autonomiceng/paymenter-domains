<?php
// Enom 444f31c/b561b9f domain-billing and billing-integration scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/reconciliation-fixture.php';
use App\Models\{Invoice, Service};
use Illuminate\Support\Facades\{Artisan, DB, Queue};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation, Price};
use Paymenter\Extensions\Servers\Domains\Presentation\DomainListRow;
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, HolderPricing, NativeDomainGuard, StaffSettingsReview, StaffSettingsWrites};

$guard = new NativeDomainGuard(new HolderPricing($prices), $clock); $guard->boot(); $guard->boot();
$billing = new DomainBilling($bindings, $guard, $clock,catalogue: $GLOBALS['caps']??null); Queue::fake();
[$original]=$prepare(placeholderComFixtureName('billing-cancellation-placeholder.com'));
same('succeeded',$reconcile->run($original->id)->state,'Real completed cancellation anchor');
$d=$original->domain()->firstOrFail();$s=$d->service;
$server = $product->server; $setting = static fn ($key, $value) => $server->settings()->updateOrCreate(['key' => $key], ['value' => $value, 'type' => 'boolean']);
$setting('domains_billing_enabled',true);$setting('domains_manual_renewals_enabled',false);
require __DIR__.'/Support/cancelled-billing.php';
// A cancelled renewal invoice reads cancelled only while the renewal is proven unpaid and unsent. Otherwise it stays under review with its registrar check.
require __DIR__.'/Support/owner-pages.php';
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');
$o=$otherSource->domain()->firstOrFail();$cycle=Operation::where('domain_id',$o->id)->where('action','renew')->sole();$raw=$cycle->getRawOriginal();
DB::table('invoices')->where('id',$cycle->invoice_id)->update(['status'=>'cancelled']);
app('view')->share('keys',array_fill_keys(['setup','recovery','code','renew','auto-renew',...\Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController::ACTIONS],str_repeat('a',64)));
app('view')->share('errors',new Illuminate\Support\ViewErrorBag);
$text=static fn(string $html):string=>trim(preg_replace('/\s+/',' ',html_entity_decode(strip_tags(preg_replace('~<(style|script)\b[^>]*>.*?</\1>~si','',$html)),ENT_QUOTES)));
$views=static function()use($owners,$o,$user,$clock,$text):array {
    $dto=$owners->present($o->fresh());$summary=(new Paymenter\Extensions\Servers\Domains\Services\DomainSummaries)->one($user,'managed',$o->id,$clock->now()->getTimestamp());
    return [$dto,$summary,DomainListRow::renewal($summary)['note']??null,$text(view('domains::show',['domain'=>$dto,'summary'=>$summary])->render())];
};
$ledger=static fn():string=>serialize([DB::table('invoices')->get(),DB::table('invoice_items')->get(),DB::table('invoice_transactions')->get(),DB::table('credits')->get(),Operation::all()->map->getRawOriginal()->all()]);
[$dto,,$note]=$views();
same([['status'=>'cancelled'],'Invoice cancelled'],[$dto->renewNow,$note],'Unpaid unsent renewal shows its cancelled invoice on page and list');
$payment=DB::table('invoice_transactions')->insertGetId(['invoice_id'=>$cycle->invoice_id,'amount'=>$cycle->amount,'fee'=>'0.00','status'=>'succeeded','created_at'=>now(),'updated_at'=>now()]);
[$dto,,$note]=$views();
same(['review','Next invoice: Under review'],[$dto->renewNow['status']??null,$note],'A cancelled invoice with payment history stays under review');
$cycle->update(['state'=>'uncertain','policy'=>[...$cycle->policy,'status_check'=>['outcome'=>'awaiting_answer','answer_after'=>$clock->now()->modify('+1 day')->getTimestamp()]]]);
$before=$ledger();
[$dto,$summary,$note,$page]=$views();
same(['review','Next invoice: Under review'],[$dto->renewNow['status']??null,$note],'A sent renewal with a cancelled invoice stays under review on page and list');
$confirming='We’re confirming this renewal with the registrar.';
expect(str_contains($page,$confirming)&&str_contains($page,'Under review')&&!str_contains($page,'Invoice cancelled'),'The page keeps the registrar check, not a cancelled invoice');
same($before,$ledger(),'Page and list are read-only');
DB::table('invoice_transactions')->where('id',$payment)->delete();DB::table('domains_operations')->where('id',$cycle->id)->update($raw);
echo "Native cancellation billing passed\n";$nativeSuccessful=true;
