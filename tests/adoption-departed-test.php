<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/Support/departure-fixture.php';
$invoice=$issueDepartureRenewal();
require_once __DIR__.'/Support/adoption-preview.php';
use App\Models\Credit;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Data\Adoption\Target;
use Paymenter\Extensions\Servers\Domains\Models\{Adoption,Domain};
use Paymenter\Extensions\Servers\Domains\Services\{DepartureProof,HoldingDeparture,HoldingLocks,MoneyOutcome,PricingRule,Refunds,StaffHoldingReview};
use Paymenter\Extensions\Servers\Domains\Holding\Adoption\AdoptionCommit;

$nativeSuccessful=false;
staffPanel();
$staff=staffUser([...AdoptionCommit::PERMISSIONS,HoldingDeparture::PERMISSION]);
$renewal=$d->operations()->where('action','renew')->where('invoice_id',$invoice->id)->sole();
$ticket=StaffHoldingReview::ticket((int)$staff->id,(int)$d->id,HoldingDeparture::ACTION,StaffHoldingReview::stamp(HoldingLocks::take($d->fresh(),advisory:true)),$clock);
$result=(new HoldingDeparture($clock))->confirm($staff,(int)$d->id,$ticket,'other_registrar','Checked the registrar account.');
same(MoneyOutcome::Applied,$result->outcome,'The old holding departs through the real confirmation');
same(true,DepartureProof::verified($d->fresh()),'The old holding has a frozen released proof');
$departed=$d->fresh()->getAttributes();$oldService=$d->service->fresh()->getAttributes();
$oldOperations=$d->operations()->orderBy('id')->get()->map->getAttributes()->all();
$cash=static fn(int $owner)=>PricingRule::minor((string)(Credit::where('user_id',$owner)->where('currency_code','USD')->value('amount')??'0.00'));
$oldCredit=$cash((int)$d->user_id);

// The new owner's import uses another account. It has no authority over the former owner's money.
$departureClock=$clock;
config(['app.url'=>'https://domains-tests.invalid']);
// The preceding checkout fixture retains a cached cart; the imported service is arranged without checkout.
App\Classes\Cart::get()->items()->delete();Illuminate\Support\Once::flush();
DB::table('categories')->where('slug','domains')->update(['slug'=>'departed-domains','full_slug'=>'departed-domains']);
extract(adoptionFixture(Environment::Live,registrars:['enom']));
Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::reset();
$newOwner=$owner;Credit::create(['user_id'=>$newOwner->id,'currency_code'=>'USD','amount'=>'2.00']);
DB::table('roles')->where('id',$staff->role_id)->update(['permissions'=>json_encode(AdoptionCommit::PERMISSIONS)]);
App\Models\Property::withoutEvents(fn()=>$property->update(['value'=>$d->domain]));
$target=new Target($d->domain,$service->id,$newOwner->id,$binding->serverId,Environment::Live);
foreach($wire->payloads as $key=>$payload){$wire->payloads[$key]=str_replace(['example.com','SLD="example"'],[$d->domain,'SLD="'.$d->sld.'"'],$payload);}
$importBindings=$bindings;
$ready=$reader->preview($target,$context);
same('ready',$ready->code,'A verified departed name permits a new owner to preview adoption: '.$ready->code);
$preview=Adoption::findOrFail($ready->previewId);
same([], $preview->evidence['history_link_ids'],'The new import links no old invoices');
same(null,$preview->domain_id,'The new import does not select the old holding');
config(['domains.adoption.enabled'=>true]);$commit=new AdoptionCommit($mapping,$importBindings,$clock);
$confirm=static fn()=>$commit->confirm($preview->request_key,$preview->request_digest,$staff);
$refuse=static function(string $why)use($confirm,$d,$cash,$newOwner,$oldCredit):string {
    $count=Domain::count();$blocked=false;$code='';
    try{$confirm();}catch(DomainException $e){$blocked=true;$code=$e->getMessage();}
    same(true,$blocked,$why);same($count,Domain::count(),$why.': no holding created');
    same([$oldCredit,200],[$cash((int)$d->user_id),$cash((int)$newOwner->id)],$why.': no credit moved');
    return $code;
};

// Both preview and locked confirmation refuse a damaged proof or a still-reserved name.
foreach(['reservation_name'=>$d->domain,'status'=>'active','service_id'=>$service->id] as $field=>$value){
    DB::table('domains_domains')->where('id',$d->id)->update([$field=>$value]);
    same('competing_holding',$reader->preview($target,$context)->code,'An invalid departure cannot be previewed: '.$field);
    $refuse('An invalid departure cannot be committed: '.$field);
    DB::table('domains_domains')->where('id',$d->id)->update([$field=>$departed[$field]]);
}
$proof=$d->fresh()->renewal_policy;$proof['departure']['identity']['name']='another.example.com';
DB::table('domains_domains')->where('id',$d->id)->update(['renewal_policy'=>encrypt(json_encode($proof),false)]);
$refuse('A mismatched frozen identity refuses adoption');
DB::table('domains_domains')->where('id',$d->id)->update(['renewal_policy'=>$departed['renewal_policy']]);
$rebound=$d->fresh();$rebound->service_id=$service->id;$proof=$rebound->renewal_policy;
$proof['departure']['identity']=DepartureProof::identity($rebound);
DB::table('domains_domains')->where('id',$d->id)->update(['service_id'=>$service->id,'renewal_policy'=>encrypt(json_encode($proof),false)]);
$refuse('Even a matching departure proof cannot reuse the old service');
DB::table('domains_domains')->where('id',$d->id)->update(['service_id'=>$departed['service_id'],'renewal_policy'=>$departed['renewal_policy']]);
$terminalState=$renewal->fresh()->state;
DB::table('domains_operations')->where('id',$renewal->id)->update(['state'=>'running']);
same('competing_holding',$reader->preview($target,$context)->code,'A non-terminal old operation refuses preview');
$refuse('A non-terminal old operation refuses confirmation');
DB::table('domains_operations')->where('id',$renewal->id)->update(['state'=>$terminalState]);

// Coverage: a departed peer must still be cancelled, owned by the proved owner and dedicated to that row.
foreach(['status'=>'active','user_id'=>$newOwner->id] as $field=>$value){
    DB::table('services')->where('id',$d->service_id)->update([$field=>$value]);
    same('competing_holding',$reader->preview($target,$context)->code,'An invalid old service refuses preview: '.$field);
    $refuse('An invalid old service refuses confirmation: '.$field);
    DB::table('services')->where('id',$d->service_id)->update([$field=>$oldService[$field]]);
}
// Fixture-only corruption bypasses the unique index to exercise the defensive second-row guard.
Illuminate\Support\Facades\Schema::table('domains_domains',fn($t)=>$t->dropUnique(['service_id']));
$extra=$departed;unset($extra['id']);$extra['domain']='another.example.com';
$extraId=(int)DB::table('domains_domains')->insertGetId($extra);
same('competing_holding',$reader->preview($target,$context)->code,'A second row on the old service refuses preview');
$refuse('A second row on the old service refuses confirmation');
DB::table('domains_domains')->where('id',$extraId)->delete();
Illuminate\Support\Facades\Schema::table('domains_domains',fn($t)=>$t->unique('service_id'));

// Distinct cancelled services without name properties isolate the domain scan from the peer-service cap.
$overflow=[];$historyIds=[];$serviceIds=[];
$historical=$departed;unset($historical['id']);$peerService=$oldService;unset($peerService['id']);
for($i=1;$i<=100;$i++){
    $historical['service_id']=$serviceIds[]=(int)DB::table('services')->insertGetId($peerService);
    $id=$historyIds[]=(int)DB::table('domains_domains')->insertGetId($historical);
    $row=Domain::findOrFail($id);$proof=$row->renewal_policy;$proof['departure']['identity']=DepartureProof::identity($row);
    DB::table('domains_domains')->where('id',$id)->update(['renewal_policy'=>encrypt(json_encode($proof),false)]);
    if($i===99){same('ready',$reader->preview($target,$context)->code,'One hundred matching departed rows remain eligible');}
}
$overflow[]=$reader->preview($target,$context)->code;
$overflow[]=$refuse('One hundred and one matching departed rows refuse confirmation');
DB::table('domains_domains')->whereIn('id',$historyIds)->delete();
DB::table('services')->whereIn('id',$serviceIds)->delete();

$operation=$renewal->fresh()->getAttributes();unset($operation['id']);
$operation['invoice_id']=null;$operation['invoice_item_id']=null;$operationIds=[];
for($i=count($oldOperations);$i<=100;$i++){
    $operation['operation_key']='departed-adoption-limit-'.$i;
    $operationIds[]=(int)DB::table('domains_operations')->insertGetId($operation);
    if($i===99){same('ready',$reader->preview($target,$context)->code,'One hundred terminal old operations remain eligible');}
}
$overflow[]=$reader->preview($target,$context)->code;
$overflow[]=$refuse('One hundred and one old operations refuse confirmation');
DB::table('domains_operations')->whereIn('id',$operationIds)->delete();
same(array_fill(0,4,'competing_holding'),$overflow,'Over-limit name matches and old operations refuse preview and locked confirmation');

// Two eligible histories must never hide a third live matching row beyond the former limit(2).
$historical=$departed;unset($historical['id']);
$peerService=$oldService;unset($peerService['id']);
$secondService=(int)DB::table('services')->insertGetId($peerService);
$secondProperty=(int)DB::table('properties')->insertGetId(['model_type'=>$service->getMorphClass(),'model_id'=>$secondService,'key'=>'domain','value'=>$d->domain]);
$historical['service_id']=$secondService;
$secondId=(int)DB::table('domains_domains')->insertGetId($historical);
$second=Domain::findOrFail($secondId);$p=$second->renewal_policy;$p['departure']['identity']=DepartureProof::identity($second);
DB::table('domains_domains')->where('id',$secondId)->update(['renewal_policy'=>encrypt(json_encode($p),false)]);
same('ready',$reader->preview($target,$context)->code,'Several verified departed histories are eligible');
$refuse('Historical membership added since preview must be checked again');
$liveId=(int)DB::table('domains_domains')->insertGetId([...$historical,'service_id'=>$service->id+1001,'status'=>'active']);
same('competing_holding',$reader->preview($target,$context)->code,'A third live matching holding still refuses a fresh preview');
$refuse('A third live matching holding is not hidden by departed histories');
DB::table('domains_domains')->whereIn('id',[$secondId,$liveId])->delete();
DB::table('properties')->where('id',$secondProperty)->delete();DB::table('services')->where('id',$secondService)->delete();

$money=$nativeMoney();$new=$confirm();
same($money,$nativeMoney(),'Adoption preserves every native invoice, payment and credit');
same(['active',$newOwner->id,$service->id,$d->domain],[$new->status,$new->user_id,$new->service_id,$new->reservation_name],'Adoption creates distinct managed authority for the new owner');
expect($new->id!==$d->id&&$new->service_id!==$d->service_id,'The old holding and service are never reused');
same($departed,$d->fresh()->getAttributes(),'Adoption leaves the old holding unchanged');
same($oldService,$d->service->fresh()->getAttributes(),'Adoption leaves the old service unchanged');
same($oldOperations,$d->operations()->orderBy('id')->get()->map->getAttributes()->all(),'Adoption leaves old operation and money evidence unchanged');
same([$oldCredit,200],[$cash((int)$d->user_id),$cash((int)$newOwner->id)],'Adoption touches neither owner credit balance');

$newRow=$new->fresh()->getAttributes();$newService=$service->fresh()->getAttributes();
App\Helpers\ExtensionHelper::addPayment($invoice->fresh(),null,$renewal->amount,'0.00','departed-adoption-late-receipt');
$refunds=new Refunds($departureClock);$refunds->creditRejected((int)$renewal->id);$refunds->creditRejected((int)$renewal->id);
same($oldCredit+PricingRule::minor((string)$renewal->amount),$cash((int)$d->user_id),'A late old receipt credits the old owner exactly once');
same(200,$cash((int)$newOwner->id),'The new owner receives none of the old receipt');
same($newRow,$new->fresh()->getAttributes(),'The late receipt leaves the new holding unchanged');
same($newService,$service->fresh()->getAttributes(),'The late receipt leaves the new service unchanged');
same(0,$new->operations()->count(),'No old receipt work attaches to the new holding');
same([],DB::table('domains_history_links')->where('domain_id',$new->id)->get()->all(),'The new holding exposes none of the old invoices');
echo "Adoption of a departed name isolates owners, history and late receipts, and refuses invalid departures.\n";
$nativeSuccessful=true;
