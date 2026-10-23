<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/adoption-confirm.php';
require __DIR__.'/Support/storefront.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\Adoption\StaffContext;
use Paymenter\Extensions\Servers\Domains\Models\Adoption;
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, OwnedDomains, OwnerPorts, RenewalCycles, Refunds, StaffOperationReview, StaffOperations};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController, OwnerRenewalController};
use Illuminate\Http\Request;
extract(adoptionCommitFixture()); require dirname(__DIR__).'/Domains/routes/owned.php'; storefrontViews();
$old=App\Models\Invoice::create(['user_id'=>$owner->id,'currency_code'=>'USD','status'=>'paid','number'=>'H-100']);
$old->items()->create(['reference_type'=>$service->getMorphClass(),'reference_id'=>$service->id,'description'=>'Historical registration','quantity'=>1,'price'=>'13.00']);
DB::table('invoices')->where('id',$old->id)->update(['number'=>'H-100']);
$collision=App\Models\Invoice::create(['user_id'=>$owner->id,'currency_code'=>'USD','status'=>'paid']);DB::table('invoices')->where('id',$collision->id)->update(['number'=>(string)$old->id]);
$r=$reader->preview($target,new StaffContext($staff->id,'Verified original customer',true));$a=Adoption::findOrFail($r->previewId);$d=$commit->confirm($a->request_key,$a->request_digest,$staff);
config(['settings.company_name'=>' <Synthetic & Company> ']);
$billing=new DomainBilling($bindings,$guard,$clock);$owners=new OwnedDomains($clock,new OwnerPorts(),billing:$billing);$dto=$owners->present($d,false);
same('Off',$dto->renewal,'DNR badge');same(['label'=>'Imported','state'=>'Completed'],$dto->progress[0],'Adoption activity');same($old->fresh()->number,$dto->history[0]['number'],'Batch native invoice number');same('Paid',$dto->history[0]['status'],'Historical invoice label');same(null,$dto->plannedRenewalDue,'No future due CTA for DNR');
same(route('invoices.show',$old->fresh()),$dto->history[0]['url'],'Historical URL uses native number route key despite numeric ID collision');
$html=view('domains::show',['domain'=>$dto])->render();
expect(!str_contains($html,'Company&gt; Registration')&&!str_contains($html,'<Synthetic & Company>'),'Detail has no registration badge and never renders the company unescaped');expect(str_contains($html,'<span class="domains-sub">Cancels ')&&preg_match('~<dt>Auto-renew .*?</dt><dd>Off <a class="domains-a" href="[^"]+">Contact support</a></dd>~s',$html)===1,'Approved cancellation copy');expect(str_contains($html,'<td>Imported</td> <td>Completed</td>'),'Adoption visible in activity');
same('cancellation',$dto->renewalOff,'Native cancellation takes precedence over adopted stop');
DB::transaction(function()use($d,$bindings):void {
$l=\Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($d);$l['cancel']=collect();
$l['domain']=clone $l['domain'];$policy=$l['domain']->renewal_policy;
$l['domain']->renewal_policy=[...$policy,'owner_auto_renew'=>false,'renewals_stopped'=>true];
same('staff',\Paymenter\Extensions\Servers\Domains\Services\RenewalStop::offReason($l),'Staff takes precedence over adopted and owner');
$l['domain']->renewal_policy=[...$policy,'owner_auto_renew'=>false];
expect(\Paymenter\Extensions\Servers\Domains\Services\RenewalStop::blocked($l),'Original adopted do-not-renew is a hard stop without cancellation');
same('adopted',\Paymenter\Extensions\Servers\Domains\Services\RenewalStop::offReason($l),'Adopted reason when no cancellation or staff stop');
try{\Paymenter\Extensions\Servers\Domains\Services\DomainBilling::requireAuthority($l,$bindings,true);expect(false,'Adopted manual authority refused');}
catch(\Paymenter\Extensions\Servers\Domains\Services\RuntimeIneligible $e){same(\Paymenter\Extensions\Servers\Domains\Services\RuntimeReason::LifecycleNotReady,$e->reason,'Adopted authority refuses for lifecycle');}
});
$preference=new \Paymenter\Extensions\Servers\Domains\Services\OwnerAutoRenew($clock);
$refused=false;try{$preference->set($owner,$d->id,true);}catch(DomainException){$refused=true;}expect($refused,'Adopted DNR switch cannot be toggled');
same([],$owners->present($d)->autoRenew,'Adopted hard stop hides switch');same([],$owners->present($d)->renewNow,'Adopted hard stop hides manual request');
$text=strip_tags(preg_replace('~<(script|style)\b[^>]*>.*?</\1>~si','',$html));foreach(['Enom','NameSilo','account-enom','synthetic-secret','provider_domain_id','account_fingerprint']as$private){expect(!str_contains($text,$private),'Customer excludes registrar/private identity');}
$money=scenarioMoney();$clock->time=new DateTimeImmutable('2027-10-21T00:00:00Z');$expired=$owners->present($d,false);same(['Expired','bad'],[$expired->status,$expired->tone],'Read-only expired display');same($dto->history,$expired->history,'Expired history remains accessible');same('example.com',$d->fresh()->reservation_name,'Expired name stays reserved');same($money,scenarioMoney(),'Presentation never rewrites finances');
try{$owners->owned($other,$d->id);expect(false,'Cross-owner detail must refuse');}catch(Illuminate\Database\Eloquent\ModelNotFoundException){}
config(['settings.company_name'=>' ']);
// Separate serialized renewal-on adoption, retaining the same native service and history.
DB::table('domains_domains')->where('id',$d->id)->delete();DB::table('domains_adoptions')->where('id',$a->id)->delete();
DB::table('service_cancellations')->where('service_id',$service->id)->delete();$clock->time=new DateTimeImmutable('2026-10-19T00:00:00Z');
$r=$reader->preview($target,$context);same('ready',$r->code,'Fresh renewal-on customer fixture');$a=Adoption::findOrFail($r->previewId);$d=$commit->confirm($a->request_key,$a->request_digest,$staff);
foreach (['2027-10-12'=>'2027-10-13','2027-10-13'=>null,'2027-10-14'=>null] as $today=>$due) {
 $clock->time=new DateTimeImmutable($today.'T00:00:00Z');$projected=$owners->present($d->fresh(),false);
 same($due,$projected->plannedRenewalDue,'Unissued renewal projection respects cutoff '.$today);
 $projectionHtml=view('domains::show',['domain'=>$projected])->render();
 if($due===null){same('review',$projected->renewalInvoice['state'],'Cutoff requires support review');expect(!str_contains($projectionHtml,'An invoice will be issued'),'Cutoff never promises unissuable debt');}
}
foreach (['2027-10-13T00:30:00+05:30'=>null,'2027-10-12T23:30:00-07:00'=>'2027-10-13'] as $local=>$due) {
 $clock->time=new DateTimeImmutable($local);$projected=$owners->present($d->fresh(),false);
 same($due,$projected->plannedRenewalDue,'Presentation uses actual billing clock date '.$local);
}
$clock->time=new DateTimeImmutable('2027-09-25T00:00:00Z');
$product->server->settings()->updateOrCreate(['key'=>'domains_manual_renewals_enabled'],['value'=>true,'type'=>'boolean']);
$invoice=$billing->invoice($d->id,$owner);$op=Operation::where('invoice_id',$invoice->id)->sole();
$product->server->settings()->updateOrCreate(['key'=>'domains_due_days'],['value'=>10,'type'=>'string']);
$issued=$owners->present($d->fresh(),false);same('2027-10-13',$issued->renewalDue,'Issued due remains frozen after setting edit');same(null,$issued->plannedRenewalDue,'Issued debt hides projected replacement date');
$issuedHtml=view('domains::show',['domain'=>$issued])->render();expect(str_contains($issuedHtml,'<dt>Next invoice</dt><dd>Due Oct 13, 2027 <a class="domains-a" href="'.route('invoices.show',$invoice).'">Pay invoice</a>'),'Actual issued-debt label');expect(!str_contains($issuedHtml,'An invoice will be issued'),'Issued debt never promises a second invoice');
$c=App\Models\ServiceCancellation::create(['service_id'=>$service->id,'type'=>'end_of_period','reason'=>'Stop the next renewal after issued debt']);
$stopped=$owners->present($d->fresh(),false);same('Off',$stopped->renewal,'Later stop turns future renewal off');same(false,$stopped->originalDoNotRenew,'Later stop is distinct from original DNR');same('pending',$stopped->renewalInvoice['state'],'Issued debt remains visible after later stop');
$stoppedHtml=view('domains::show',['domain'=>$stopped])->render();expect(preg_match('~<dt>Auto-renew .*?</dt><dd>Off <a class="domains-a" href="[^"]+">Contact support</a></dd>~s',$stoppedHtml)===1&&str_contains($stoppedHtml,'>Pay invoice</a>'),'Later stop asks for support');expect(!str_contains($stoppedHtml,'No renewal invoices or renewal reminders are scheduled.'),'Later stop never promises suppression of issued reminders');
DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'paid']);
$paid=$owners->present($d->fresh(),false);same(null,$paid->plannedRenewalDue,'Paid issued cycle never projects a new invoice');same('paid',$paid->renewalInvoice['state'],'Paid debt presented distinctly');
$paidHtml=view('domains::show',['domain'=>$paid])->render();expect(str_contains($paidHtml,'<dt>Next invoice</dt><dd>Paid')&&str_contains($paidHtml,'<span class="domains-sub">Updates when the renewal completes</span>'),'Actual paid renewal label');expect(!str_contains($paidHtml,'An invoice will be issued'),'Paid cycle never promises another invoice');
// The adopted page uses the current cycle for both retained debt and replacement requests.
foreach(['WatchDnsSchema','ManagedPublicWorkSchema','ManagedPublicSchema','WatchScheduleSchema','WatchNoticeSchema'] as $schema){('Paymenter\\Extensions\\Servers\\Domains\\Services\\'.$schema)::install();}
$c->delete();$session=app('request')->session();$controller=new OwnerRenewalController($owners,$billing);
$request=static function(string $method,string $path,array $data=[])use($owner,$session):Request {
    $r=Request::create($path,$method,$data);$r->setUserResolver(fn()=>$owner);$r->setLaravelSession($session);return $r;
};
$page=static fn()=>(new OwnedDomainController($owners))->show($request('GET','/domains/'.$d->id),$d->id)->render();
$post=static function()use($controller,$request,$d){$r=$request('POST','/domains/'.$d->id.'/renew',['years'=>'1']);
    $r->request->set('_request_key',FormKeys::issue($r,'owner:'.$d->id.':renew'));return $controller->renew($r,$d->id);};
$debt=static fn()=>serialize([scenarioMoney(),Operation::where('domain_id',$d->id)->get()->map->getRawOriginal()->all()]);
DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'pending']);
$cycles=new RenewalCycles($bindings,$clock);$cycles->retireUnpaid($op->id);
same(['state'=>'cancelled'],$owners->present($d->fresh(),false)->renewalInvoice,'Adopted retired cycle remains visible without forms');
config(['settings.credits_auto_use'=>false]);
foreach(['unpaid','refund'] as $replacementCase){
    $before=$debt();$dto=$owners->present($d->fresh());$html=$page();
    same([],$dto->renewalInvoice,'Adopted replaceable cycle does not suppress form: '.$replacementCase);
    expect(isset($dto->renewNow['url'])&&str_contains($html,'>Create invoice<'),'Adopted replacement request visible: '.$replacementCase);
    expect(!str_contains($html,'Renewal being checked')&&!str_contains($html,'Invoice cancelled'),'Adopted replacement page has no stale cycle status: '.$replacementCase);
    same($before,$debt(),'Adopted replacement page is read-only: '.$replacementCase);
    $response=$post();$replacement=Operation::where('domain_id',$d->id)->where('action','renew')->orderByDesc('id')->firstOrFail();
    same($op->operation_key.':replacement:'.($replacementCase==='unpaid'?1:2),$replacement->operation_key,'Adopted replacement has its own cycle key: '.$replacementCase);
    same(route('invoices.show',$replacement->invoice_id),$response->getTargetUrl(),'Adopted POST returns replacement invoice: '.$replacementCase);
    $after=$debt();same($response->getTargetUrl(),$post()->getTargetUrl(),'Adopted replacement POST retry returns same invoice: '.$replacementCase);same($after,$debt(),'Adopted retry creates no duplicate debt: '.$replacementCase);
    $html=$page();same(1,substr_count($html,'>Pay invoice</a>'),'One adopted pending cycle panel: '.$replacementCase);
    expect(str_contains($html,'>Pay invoice</a>')&&!str_contains($html,'>Create invoice<'),'Adopted current debt replaces request form: '.$replacementCase);
    $item=App\Models\InvoiceItem::findOrFail($replacement->invoice_item_id);$rawItem=$item->getRawOriginal();
    DB::table('invoice_items')->where('id',$item->id)->update(['price'=>'0.01']);$html=$page();
    expect(str_contains($html,'Renewal being checked')&&!str_contains($html,'>Pay invoice</a>')&&!str_contains($html,'>Create invoice<'),'Adopted invalid retained item shows review: '.$replacementCase);
    DB::table('invoice_items')->where('id',$item->id)->update($rawItem);

    if($replacementCase==='refund'){break;}
    // A real native payment and staff refund preserve the old debt before replacement review.
    App\Helpers\ExtensionHelper::addPayment($replacement->invoice_id,null,$replacement->amount);
    $replacement->fresh()->update(['state'=>'held']);
    DB::table('roles')->where('id',$staff->role_id)->update(['permissions'=>json_encode([...$permissions,'admin.invoices.update','admin.invoice_transactions.create','admin.domains.operations.replacement'])]);
    (new Refunds($clock))->refundHeld($staff->fresh(),$replacement->id,'staff_review');
    $ticket=StaffOperationReview::ticket($staff->id,$d->id,$replacement->id,'replacement',StaffOperationReview::stamp($replacement->fresh(),$d->fresh()),$clock);
    (new StaffOperations($clock))->run($staff->fresh(),$d->id,$replacement->id,'replacement',$ticket,'Allow replacement after unsent renewal refund');
    $reviewedId=$replacement->id;$reviewed=$replacement->fresh()->getRawOriginal();
}
same($reviewed,Operation::findOrFail($reviewedId)->getRawOriginal(),'Adopted replacement retains reviewed refund evidence');
$nativeSuccessful=true;echo "Adopted customer badge, original invoices, renewal-off, expiry and safe escaped Blade passed\n";
