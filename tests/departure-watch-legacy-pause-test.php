<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/Support/departure-fixture.php';
$invoice=$issueDepartureRenewal();
require_once __DIR__.'/Support/staff-panel.php';
require_once __DIR__.'/Support/psl.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\{WatchAudit,WatchName,WatchProductMap,WatchSource};
use Paymenter\Extensions\Servers\Domains\Services\{HoldingDeparture,HoldingLocks,MoneyOutcome,PublicSuffixList,PslTransport,PslValidator,StaffHoldingReview,WatchDiscovery};

$nativeSuccessful=false;
staffPanel();
// The owner's hosting names this domain; the watch is linked to the managed holding through that hosting.
$owner=(int)$d->user_id;$name=(string)$d->domain;
$product=App\Models\Product::create(['name'=>'Hosting','slug'=>'departure-hosting','category_id'=>App\Models\Category::firstOrFail()->id,'allow_quantity'=>'combined','stock'=>10]);
$plan=$product->plans()->create(['name'=>'Monthly','type'=>'recurring','billing_period'=>1,'billing_unit'=>'month']);
$order=App\Models\Order::withoutEvents(fn()=>App\Models\Order::create(['user_id'=>$owner,'currency_code'=>'USD']));
$hosting=App\Models\Service::withoutEvents(fn()=>App\Models\Service::create(['user_id'=>$owner,'order_id'=>$order->id,'product_id'=>$product->id,'plan_id'=>$plan->id,
    'status'=>'active','quantity'=>1,'price'=>'5.00','currency_code'=>'USD']));
$hosting->properties()->create(['key'=>'domain','value'=>'www.'.$name]);
$map=DB::transaction(fn()=>WatchProductMap::create(['product_id'=>$product->id,'property_key'=>'domain','edited_by'=>$owner,'reason'=>'Synthetic map']));
DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['enabled'=>true]);
$watch=DB::transaction(fn()=>WatchName::withoutEvents(fn()=>WatchName::create(['user_id'=>$owner,'canonical_name'=>$name,'psl_revision'=>str_repeat('a',64),'notice_preferences'=>['expiry'=>true],
    'state'=>'paused'])));
// A pause predating the staff audit trail must survive discovery linking and later departure.
$v=new PslValidator(128,5,1);
$psl=new PublicSuffixList($clock,new PslTransport(fn()=>['status'=>200,'body'=>pslFixture()]),$v);
same('updated',$psl->update(),'A synthetic current suffix list allows discovery');
app()->instance(PublicSuffixList::class,$psl);app()->instance(PslValidator::class,$v);
$discovery=new WatchDiscovery($psl,$v,$clock);
$discovery->discover((int)$hosting->id,(int)$product->id);
same('linked',$watch->fresh()->state,'Discovery links the legacy paused watch');
$link=WatchSource::where('managed_domain_id',$d->id)->where('state','managed_link')->sole();
same('paused',WatchDiscovery::departurePreview($d->fresh()),'The departure preview retains the legacy pause');
$staff=staffUser([Staff::VIEW,'admin.domains.manage',HoldingDeparture::PERMISSION]);
$ticket=StaffHoldingReview::ticket((int)$staff->id,(int)$d->id,HoldingDeparture::ACTION,StaffHoldingReview::stamp(HoldingLocks::take($d->fresh(),advisory:true)),$clock);
$result=(new HoldingDeparture($clock))->confirm($staff,(int)$d->id,$ticket,'other_registrar','Checked the registrar account.');
same([MoneyOutcome::Applied,null],[$result->outcome,$result->error?->getMessage()],'Confirmation hands back a hosting-backed watch');
same('departed',$d->fresh()->status,'The holding is departed');
$watch->refresh();
same(['paused',null,null],[$watch->state,$watch->managed_domain_id,$watch->registration],'Active hosting does not override the legacy staff pause');
same('retired',$link->fresh()->state,'The managed link is retired');
$source=WatchSource::where('watch_id',$watch->id)->where('state','watch')->sole();
same([(int)$hosting->id,(int)$map->id,'www.'.$name],[(int)$source->service_id,(int)$source->map_id,$source->hostname],'The hosting source now feeds the watch');
same(1,WatchAudit::where('subject_id',$watch->id)->where('event','registration_departed')->count(),'The handoff is audited once');
same(['id'=>(int)$watch->id,'state'=>'paused'],array_slice($d->fresh()->renewal_policy['staff_holdings'],-1)[0]['watch']??null,'The confirmation history records the watch outcome');
echo "A legacy staff pause survives discovery linking and domain departure.\n";
$nativeSuccessful=true;
