<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
$watchCase??='active';
require __DIR__.'/Support/departure-fixture.php';
$invoice=$issueDepartureRenewal();
require_once __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\{WatchAudit,WatchName,WatchProductMap,WatchSource};
use Paymenter\Extensions\Servers\Domains\Services\{HoldingDeparture,HoldingLocks,MoneyOutcome,StaffHoldingReview};

$nativeSuccessful=false;
staffPanel();
// The owner's hosting names this domain; the watch is linked to the managed holding through that hosting.
$owner=(int)$d->user_id;$name=(string)$d->domain;
$product=App\Models\Product::create(['name'=>'Hosting','slug'=>'departure-hosting','category_id'=>App\Models\Category::firstOrFail()->id,'allow_quantity'=>'combined','stock'=>10]);
$plan=$product->plans()->create(['name'=>'Monthly','type'=>'recurring','billing_period'=>1,'billing_unit'=>'month']);
$order=App\Models\Order::withoutEvents(fn()=>App\Models\Order::create(['user_id'=>$owner,'currency_code'=>'USD']));
$hosting=App\Models\Service::withoutEvents(fn()=>App\Models\Service::create(['user_id'=>$owner,'order_id'=>$order->id,'product_id'=>$product->id,'plan_id'=>$plan->id,
    'status'=>$watchCase==='active'?'active':'suspended','quantity'=>1,'price'=>'5.00','currency_code'=>'USD']));
$hosting->properties()->create(['key'=>'domain','value'=>'www.'.$name]);
$map=DB::transaction(fn()=>WatchProductMap::create(['product_id'=>$product->id,'property_key'=>'domain','edited_by'=>$owner,'reason'=>'Synthetic map']));
DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['enabled'=>true]);
$watch=DB::transaction(fn()=>WatchName::create(['user_id'=>$owner,'canonical_name'=>$name,'psl_revision'=>str_repeat('a',64),'notice_preferences'=>['expiry'=>true],
    'state'=>'linked','registration'=>'platform','managed_domain_id'=>$d->id]));
$link=DB::transaction(fn()=>WatchSource::create(['user_id'=>$owner,'service_id'=>$hosting->id,'map_id'=>$map->id,'map_revision'=>1,'hostname'=>'www.'.$name,
    'registrable_name'=>$name,'managed_domain_id'=>$d->id,'state'=>'managed_link','source_digest'=>str_repeat('b',64),'last_seen_at'=>1]));

// The staff preview names the outcome confirmation gives this watch and changes nothing: suspended hosting no longer qualifies.
$watchBefore=[$watch->fresh()->getAttributes(),$link->fresh()->getAttributes()];
same($watchCase==='active'?'active':'hosting',Paymenter\Extensions\Servers\Domains\Services\WatchDiscovery::departurePreview($d->fresh()),'The preview names the watch outcome');
same($watchBefore,[$watch->fresh()->getAttributes(),$link->fresh()->getAttributes()],'The watch preview writes nothing');
$staff=staffUser([Staff::VIEW,'admin.domains.manage',HoldingDeparture::PERMISSION]);
$ticket=StaffHoldingReview::ticket((int)$staff->id,(int)$d->id,HoldingDeparture::ACTION,StaffHoldingReview::stamp(HoldingLocks::take($d->fresh(),advisory:true)),$clock);
$result=(new HoldingDeparture($clock))->confirm($staff,(int)$d->id,$ticket,'other_registrar','Checked the registrar account.');
same([MoneyOutcome::Applied,null],[$result->outcome,$result->error?->getMessage()],'Confirmation hands back a hosting-backed watch');
same('departed',$d->fresh()->status,'The holding is departed');
$watch->refresh();
same([$watchCase,null,null],[$watch->state,$watch->managed_domain_id,$watch->registration],'The watch follows its hosting: '.$watchCase);
same('retired',$link->fresh()->state,'The managed link is retired');
$source=WatchSource::where('watch_id',$watch->id)->where('state','watch')->sole();
same([(int)$hosting->id,(int)$map->id,'www.'.$name],[(int)$source->service_id,(int)$source->map_id,$source->hostname],'The hosting source now feeds the watch');
same(1,WatchAudit::where('subject_id',$watch->id)->where('event','registration_departed')->count(),'The handoff is audited once');
same(['id'=>(int)$watch->id,'state'=>$watchCase],array_slice($d->fresh()->renewal_policy['staff_holdings'],-1)[0]['watch']??null,'The confirmation history records the watch outcome');
echo "A hosting-backed watch is handed back {$watchCase} when its domain departs.\n";
$nativeSuccessful=true;
