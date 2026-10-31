<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Every client page names a domain's state with the same status label.
declare(strict_types=1);
require __DIR__.'/Support/owned.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController;
use Paymenter\Extensions\Servers\Domains\Services\{AdoptionSchema, HistoryLinkSchema, NativePresenter};

$op=$newTransfer(placeholderComFixtureName('status-pages-placeholder.com'),0,false,false); $d=$op->domain; storefrontViews();
AdoptionSchema::install(); HistoryLinkSchema::install();
$controller=new OwnedDomainController($owners); $session=app('request')->session();
$request=static function(array $query=[])use($session,$user){$r=Request::create('/domains','GET',$query);$r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);return $r;};
$text=static fn(DOMXPath $dom,string $class,$context=null)=>array_map(fn($n)=>trim($n->textContent),iterator_to_array($dom->query('.//*[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]',$context??$dom->document->documentElement)));
$day=static fn(int $days)=>$clock->now()->setTimezone(new DateTimeZone('UTC'))->modify(($days<0?'':'+').$days.' days')->format('Y-m-d 00:00:00');
$off=Crypt::encryptString(json_encode(['owner_auto_renew'=>false]));
// case => [domain columns, acquisition columns, label, reason]
$cases=[
    'payment'=>[[],['state'=>'awaiting_payment'],'Action required','Invoice not paid'],
    'code'=>[[],['state'=>'awaiting_code'],'Action required','Auth code needed'],
    'transfer'=>[[],['state'=>'queued'],'Pending transfer',null],
    'transfer-held'=>[[],['state'=>'held','safe_code'=>'transfer_setup_review_required'],'Under review','Transfer on hold'],
    'transfer-uncertain'=>[[],['state'=>'uncertain'],'Under review','Transfer not confirmed'],
    'register-held'=>[['status'=>'registration_pending'],['action'=>'register','state'=>'held','safe_code'=>null],'Pending registration',null],
    'setup'=>[['status'=>'registration_pending'],['action'=>'register','state'=>'held','safe_code'=>'contact_details_required'],'Action required','Finish setup'],
    'active'=>[['status'=>'active','provider_expires_at'=>$day(200)],['state'=>'succeeded'],'Active',null],
    'auto-renew-on-soon'=>[['status'=>'active','provider_expires_at'=>$day(10)],['state'=>'succeeded'],'Active',null],
    'renewal-off-later'=>[['status'=>'active','provider_expires_at'=>$day(200),'renewal_policy'=>$off],['state'=>'succeeded'],'Active',null],
    'renewal-off-soon'=>[['status'=>'active','provider_expires_at'=>$day(10),'renewal_policy'=>$off],['state'=>'succeeded'],'Action required','Auto-renew off'],
    'no-expiry'=>[['status'=>'active','provider_expires_at'=>null],['state'=>'succeeded'],'Under review','Expiry missing'],
    'past-expiry'=>[['status'=>'active','provider_expires_at'=>$day(-1)],['state'=>'succeeded'],'Expired',Paymenter\Extensions\Servers\Domains\Presentation\DomainStatus::LATE],
    'expired'=>[['status'=>'expired','provider_expires_at'=>$day(-5)],['state'=>'succeeded'],'Expired',Paymenter\Extensions\Servers\Domains\Presentation\DomainStatus::LATE],
    'grace'=>[['status'=>'grace','provider_expires_at'=>$day(-5)],['state'=>'succeeded'],'Expired',Paymenter\Extensions\Servers\Domains\Presentation\DomainStatus::LATE],
    'redemption'=>[['status'=>'redemption','provider_expires_at'=>$day(-40)],['state'=>'succeeded'],'Redemption',Paymenter\Extensions\Servers\Domains\Presentation\DomainStatus::LATE],
    'review'=>[['status'=>'needs_review'],['state'=>'succeeded'],'Under review',null],
    'registration-rejected'=>[['status'=>'registration_rejected'],['action'=>'register','state'=>'rejected'],'Failed','Registration did not finish'],
    'transfer-rejected'=>[['status'=>'transfer_rejected'],['state'=>'rejected'],'Failed','Transfer did not finish'],
    'cancelled'=>[['status'=>'cancelled'],['state'=>'disposed'],'Cancelled',null],
    'adoption'=>[['status'=>'adoption_pending'],['state'=>'succeeded'],'Under review','Import not confirmed'],
];
$base=['status'=>'transfer_pending','provider_expires_at'=>null,'renewal_policy'=>null];
$keys=[];
foreach ($cases as $case=>[$domain,$acquisition,$label,$reason]) {
    DB::table('domains_domains')->where('id',$d->id)->update([...$base,...$domain]);
    DB::table('domains_operations')->where('id',$op->id)->update(['action'=>'transfer','safe_code'=>null,...$acquisition]);
    // Cancelled rows stay out of the default list, behind their chip.
    $hidden=$case==='cancelled';same($hidden?0:1,$controller->index($request())->getData()['domains']->total(),'Default list: '.$case);
    $list=storefrontDom($controller->index($request($hidden?['status'=>'cancelled']:[]))->render());
    $row=$list->query('//tbody/tr')->item(0);
    $show=storefrontDom($controller->show($request(),$d->id)->render());
    $header=$show->query('//div[@class="domains-ph"]/span[@class="domains-pill"]')->item(0);
    $card=storefrontDom(view('domains::service',['domain'=>$owners->present($d->fresh())])->render());
    $native=app()->make(NativePresenter::class,['owned'=>$owners])->service($user,(int)$d->service_id);
    $seen=['list'=>$text($list,'domains-status',$row),'detail'=>$text($show,'domains-status',$header),'service card'=>$text($card,'domains-status'),'services page'=>[$native?->status]];
    foreach ($seen as $page=>$labels) { same([$label],$labels,'One status label on the '.$page.': '.$case); }
    $reasons=['list'=>$text($list,'domains-pill-reason',$row),'detail'=>$text($show,'domains-pill-reason',$header),'service card'=>$text($card,'domains-pill-reason')];
    foreach ($reasons as $page=>$lines) { same($reason===null?[]:[$reason],$lines,'Same reason line on the '.$page.': '.$case); }
    // Chips name the statuses present; the chip for this status keeps the row, any other chip drops it.
    same(['All '.($hidden?0:1),$label.' 1'],array_map(fn($chip)=>preg_replace('/\s+/',' ',$chip),$text($list,'domains-chip')),'Chips are the statuses present with counts: '.$case);
    parse_str((string)parse_url($list->query('//a[contains(@class,"domains-chip")]/@href')->item(1)?->value??'',PHP_URL_QUERY),$query);
    $key=(string)($query['status']??''); $keys[$label]=$key;
    same(1,$controller->index($request(['status'=>$key]))->getData()['domains']->total(),'Status chip keeps the row: '.$case);
    same(0,$controller->index($request(['status'=>$label==='Active'?'expired':'active']))->getData()['domains']->total(),'Other chips drop the row: '.$case);
}
same(count($keys),count(array_unique($keys)),'Each status label has its own filter key');
// A real expired domain gets its next step, and never a link to the ticket page that answers 404 when tickets are off.
DB::table('domains_domains')->where('id',$d->id)->update([...$base,'status'=>'expired','provider_expires_at'=>$day(-5)]);
DB::table('domains_operations')->where('id',$op->id)->update(['action'=>'transfer','safe_code'=>null,'state'=>'succeeded']);
$expired=static function()use($controller,$request,$d){$dom=storefrontDom($controller->show($request(),$d->id)->render());return [trim((string)$dom->query('//span[@class="domains-notice__tx"]')->item(0)?->textContent),array_map(fn($a)=>$a->value,iterator_to_array($dom->query('//div[@class="domains"]//a/@href[contains(.,"tickets")]')))];};
$next='This domain has expired. Contact support to check whether it can still be renewed.';
same([$next,[route('tickets.create')]],$expired(),'Tickets on: the expired notice links the ticket form');
config(['settings.tickets_disabled'=>true]);
same([$next,[]],$expired(),'Tickets off: the same words, with no link to a 404');
config(['settings.tickets_disabled'=>false]);
$nativeSuccessful=true;
