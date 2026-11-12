<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. The Domains list and the domain page agree on every status case.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/reminders.php';
require __DIR__.'/Support/owner-pages.php';
use Illuminate\Support\Facades\{Crypt, DB};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainListRow, DomainPage, StatusPill};
use Paymenter\Extensions\Servers\Domains\Services\DomainSummaries;

[$d,$invoice,$cycle]=$make(placeholderComFixtureName('state-parity-placeholder.com'));
$user=$d->service->user;$op=Operation::where('domain_id',$d->id)->whereIn('action',['register','transfer'])->sole();
Paymenter\Extensions\Servers\Domains\Services\AdoptionSchema::install();Paymenter\Extensions\Servers\Domains\Services\HoldingProvenanceSchema::install();Paymenter\Extensions\Servers\Domains\Services\HoldingAdoptionSchema::install();
$rows=['domain'=>(array)DB::table('domains_domains')->where('id',$d->id)->first(),'op'=>(array)DB::table('domains_operations')->where('id',$op->id)->first(),'cycle'=>(array)DB::table('domains_operations')->where('id',$cycle->id)->first(),
    'invoice'=>(array)DB::table('invoices')->where('id',$invoice->id)->first(),'order'=>(array)DB::table('invoices')->where('id',$op->invoice_id)->first()];
$start=$clock->instant;$expiry=$d->provider_expires_at->format('Y-m-d');
$requests=static fn(bool $on)=>$product->server->settings()->updateOrCreate(['key'=>'domains_manual_renewals_enabled'],['value'=>$on,'type'=>'boolean']);
$retire=static fn()=>(new Paymenter\Extensions\Servers\Domains\Services\RenewalCycles($bindings,$clock))->retireUnpaid($cycle->id);
$off=Crypt::encryptString(json_encode(['owner_auto_renew'=>false]));
// The renewal invoice as each view words it: paid, review, cancelled, closed, missing, or other (a due invoice, a planned date or nothing).
$listInvoice=static function(array $r):string {
    $note=(string)($r['note']??'');
    return match (true) { $r['invoiceMissing'] => 'missing', isset($r['noteState']) => 'review', $note==='Invoice cancelled' => 'cancelled',
        str_starts_with($note,'Renewal invoice paid') => 'paid', str_starts_with($note,'Renewal did not finish') => 'closed', default => 'other' };
};
$pageInvoice=static function(?array $r):string {
    if ($r===null) { return 'other'; }
    if ($r['missing']??false) { return match (true) { ($r['cause']??null)==='Invoice cancelled' => 'cancelled', str_starts_with((string)($r['cause']??''),'Not issued yet') => 'missing', default => 'other' }; }
    return match (true) { ($r['tone']??null)==='info' => 'review', $r['value']==='Paid' => 'paid', $r['value']==='Renewal did not finish' => 'closed', default => 'other' };
};
$views=static function()use($d,$user,$clock,$owners,$listInvoice,$pageInvoice):array {
    // The same reads the Domains list and the domain page make: listed() for the rows, present() and one() for the page.
    $now=$clock->now()->getTimestamp();$all=(new DomainSummaries)->listed($user,$now);$row=$all['managed:'.$d->id]??null;
    $page=$owners->present($d->fresh());$summary=(new DomainSummaries)->one($user,'managed',(int)$d->id,$now);
    $renewalRows=$page->renewal!==''&&!$page->setupPending;$pill=StatusPill::owned($page,$summary);$expires=DomainPage::expires($page,$summary,'/support');
    $detail=['pill'=>[$pill['label'],$pill['tone'],$pill['reason']],'renewal'=>$renewalRows?($page->autoRenew?($page->autoRenew['enabled']?'On':'Off'):$page->renewal):null,
        'invoice'=>$pageInvoice(DomainPage::nextInvoice($page,$summary,'/support')),'expires'=>[$page->expiry,$expires['tone']??null]];
    if ($row===null) { return ['list'=>null,'detail'=>$detail]; }
    $listPill=StatusPill::summary($row);$renewal=DomainListRow::renewal($row);
    return ['list'=>['pill'=>$listPill?[$listPill['label'],$listPill['tone'],$listPill['reason']]:null,'renewal'=>$renewal['value'],'invoice'=>$listInvoice($renewal),
        'expires'=>[$row->expiry?substr($row->expiry,0,10):null,DomainListRow::expires($row)['tone']??null]],'detail'=>$detail];
};
$reset=static function()use($d,$op,$cycle,$invoice,$rows,$clock,$start,$requests):void {
    $requests(true);
    DB::table('domains_domains')->where('id',$d->id)->update($rows['domain']);DB::table('domains_operations')->where('id',$op->id)->update($rows['op']);
    DB::table('domains_operations')->where('id',$cycle->id)->update($rows['cycle']);DB::table('invoices')->where('id',$invoice->id)->update($rows['invoice']);DB::table('invoices')->where('id',$op->invoice_id)->update($rows['order']);
    DB::table('invoice_transactions')->where('invoice_id',$invoice->id)->delete();DB::table('domains_adoptions')->where('domain_id',$d->id)->delete();$clock->instant=$start;
};
$adopt=static function(bool $stop)use($d,$clock,$user):void {
    $record=new Paymenter\Extensions\Servers\Domains\Models\Adoption([...$d->only(['service_id','user_id','server_id','registrar_key','account_fingerprint','authority_generation']),
        'request_key'=>'parity-adoption-'.DB::table('domains_adoptions')->count(),'request_digest'=>str_repeat('b',64),'domain_id'=>$d->id,'state'=>'committed','origin'=>'staff','environment'=>'test',
        'actor_id'=>$user->id,'reason'=>'Synthetic import','do_not_renew'=>$stop,'cancellation_id'=>$stop?999999:null,
        'evidence'=>['version'=>1,'registrar_key'=>$d->registrar_key,'environment'=>'test','resulting_generation'=>$d->authority_generation],
        'observed_at'=>$clock->now(),'valid_until'=>$clock->now()->modify('+1 hour'),'committed_at'=>$clock->now()]);
    DB::table('domains_domains')->where('id',$d->id)->update(['provenance'=>'adopted','adoption_id'=>DB::table('domains_adoptions')->insertGetId($record->getAttributes())]);
};
// An open order: the registration is pending with its request and invoice in these states.
$order=static fn(string $state,string $paid)=>function()use($d,$op,$cycle,$state,$paid):void {
    DB::table('domains_operations')->where('id',$cycle->id)->update(['authority_generation'=>0]);
    DB::table('domains_domains')->where('id',$d->id)->update(['status'=>'registration_pending','provider_expires_at'=>null]);
    DB::table('domains_operations')->where('id',$op->id)->update(['state'=>$state,'safe_code'=>$state==='rejected'?'provider_refused':null]);
    DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>$paid]);
};
// case => change. Each case starts from an active domain with an issued, unpaid renewal invoice.
$cases=[
    'renewal due'=>fn()=>null,
    'renewal paid'=>function()use($invoice,$cycle){DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'paid']);DB::table('domains_operations')->where('id',$cycle->id)->update(['state'=>'queued']);},
    'renewal invoice cancelled, unpaid'=>fn()=>DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'cancelled']),
    'renewal invoice cancelled after a payment'=>function()use($invoice,$cycle){DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'cancelled']);
        DB::table('invoice_transactions')->insert(['invoice_id'=>$invoice->id,'amount'=>$cycle->amount,'fee'=>'0.00','status'=>'succeeded','created_at'=>now(),'updated_at'=>now()]);},
    'renewal closed without its retirement record'=>function()use($invoice,$cycle,$clock){DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'cancelled']);
        DB::table('domains_operations')->where('id',$cycle->id)->update(['state'=>'rejected','safe_code'=>'renewal_cancelled_unpaid','completed_at'=>$clock->now()]);},
    // The cutoff's closed state over a charged receipt: billing refuses a complimentary close it cannot match, so both views check it.
    'complimentary renewal closed over a charged receipt'=>function()use($invoice,$cycle,$clock){DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'paid']);$closed=Operation::findOrFail($cycle->id);
        $closed->forceFill(['funding_basis'=>'complimentary','state'=>'rejected','safe_code'=>Paymenter\Extensions\Servers\Domains\Services\FundsWaits::RENEWAL_CLOSED,'completed_at'=>$clock->now(),
            'attempt'=>['terminal'=>['operation_key'=>$closed->operation_key,'generation'=>$closed->authority_generation,'invoice_id'=>$closed->invoice_id],'disposition'=>'rejected',
                'rejection_basis'=>'definitely_unsent','send_state'=>'definitely_unsent','seal'=>'absent']])->saveQuietly();},
    'renewal sent, not confirmed'=>fn()=>DB::table('domains_operations')->where('id',$cycle->id)->update(['state'=>'uncertain']),
    'renewal due, auto-renew off'=>fn()=>DB::table('domains_domains')->where('id',$d->id)->update(['renewal_policy'=>$off]),
    'renewal due past expiry'=>function()use($clock,$expiry){$clock->instant=new DateTimeImmutable($expiry.'T12:00:00Z');},
    'renewal paid past expiry'=>function()use($clock,$expiry,$invoice,$cycle){DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'paid']);
        DB::table('domains_operations')->where('id',$cycle->id)->update(['state'=>'queued']);$clock->instant=new DateTimeImmutable($expiry.'T12:00:00Z');},
    'no expiry with a renewal'=>fn()=>DB::table('domains_domains')->where('id',$d->id)->update(['provider_expires_at'=>null]),
    'unreadable renewal settings'=>fn()=>DB::table('domains_domains')->where('id',$d->id)->update(['renewal_policy'=>'not-encrypted']),
    'unreadable renewal settings, renewal paid'=>function()use($d,$invoice,$cycle){DB::table('domains_domains')->where('id',$d->id)->update(['renewal_policy'=>'not-encrypted']);
        DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'paid']);DB::table('domains_operations')->where('id',$cycle->id)->update(['state'=>'queued']);},
    'expired'=>fn()=>DB::table('domains_domains')->where('id',$d->id)->update(['status'=>'expired']),
    'redemption'=>fn()=>DB::table('domains_domains')->where('id',$d->id)->update(['status'=>'redemption']),
    'cancelled'=>fn()=>DB::table('domains_domains')->where('id',$d->id)->update(['status'=>'cancelled']),
    'needs review'=>fn()=>DB::table('domains_domains')->where('id',$d->id)->update(['status'=>'needs_review']),
    'renewal retired unpaid, renewals by request off'=>function()use($retire,$requests){$retire();$requests(false);},
    'adopted, no renewal invoice yet'=>function()use($d,$cycle,$adopt){$adopt(false);DB::table('domains_operations')->where('id',$cycle->id)->update(['operation_key'=>'renew:'.$d->id.':g0:2020-01-01','base_expires_at'=>'2020-01-01 00:00:00']);},
    'renewal invoice not issued on time'=>function()use($d,$cycle,$clock,$expiry){DB::table('domains_operations')->where('id',$cycle->id)->update(['operation_key'=>'renew:'.$d->id.':g0:2020-01-01','base_expires_at'=>'2020-01-01 00:00:00']);
        $clock->instant=(new DateTimeImmutable($expiry.'T12:00:00Z'))->modify('-2 days');},
    'adopted, renewal due'=>fn()=>$adopt(false),
    'adopted, do not renew'=>fn()=>$adopt(true),
    'order unpaid'=>$order('awaiting_payment','pending'),
    'order paid'=>$order('queued','paid'),
    'order invoice cancelled'=>$order('awaiting_payment','cancelled'),
    'order failed'=>$order('rejected','paid'),
    'renewal off, expiring soon'=>function()use($d,$off,$clock,$expiry){DB::table('domains_domains')->where('id',$d->id)->update(['renewal_policy'=>$off]);$clock->instant=new DateTimeImmutable($expiry.'T12:00:00Z');$clock->instant=$clock->instant->modify('-10 days');},
];
$drift=[];$seen=['invoice'=>[],'pill'=>[]];
foreach ($cases as $case=>$change) {
    $reset();$change();$v=$views();$seen['invoice'][$v['detail']['invoice']]=true;$seen['pill'][$v['detail']['pill'][0]]=true;
    if ($v['list']===null) { $drift[]=$case.': missing from the list'; continue; }
    foreach (['pill','invoice','expires'] as $axis) { if ($v['list'][$axis]!==$v['detail'][$axis]) { $drift[]=$case.' '.$axis.': list '.json_encode($v['list'][$axis]).' page '.json_encode($v['detail'][$axis]); } }
    if ($v['detail']['renewal']!==null && $v['list']['renewal']!==$v['detail']['renewal']) { $drift[]=$case.' renewal: list '.json_encode($v['list']['renewal']).' page '.json_encode($v['detail']['renewal']); }
}
same([],$drift,'The list and the domain page agree: '.implode('; ',$drift));
// The cases reach every invoice word and a spread of statuses, so agreement is not two blank views.
// Renewal did not finish needs a real complimentary checkout; complimentary-renewal-cutoff-test checks both views share it.
$tokens=array_keys($seen['invoice']);sort($tokens);
same(['cancelled','missing','other','paid','review'],$tokens,'Every renewal invoice word is covered: '.implode(', ',$tokens));
expect(count($seen['pill'])>=8,'The cases cover many statuses: '.implode(', ',array_keys($seen['pill'])));
// Where the owner can request a new invoice, the page's Renew now form replaces the settled invoice's word; the list keeps the word.
$reset();$retire();$v=$views();$page=$owners->present($d->fresh());
same(['cancelled','other'],[$v['list']['invoice'],$v['detail']['invoice']],'A retired invoice reads cancelled on the list; the page offers the form instead');
expect(isset($page->renewNow['url']),'The page offers Renew now for the retired invoice');
$reset();
echo "Domain state parity passed\n";$nativeSuccessful=true;
