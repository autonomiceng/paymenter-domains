<?php
// Native list, request, domain status and history permissions require the actual Filament and encrypted model paths.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\{ListOperations,ViewOperation};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,DomainHistory,Labels,RequestFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccounts;
staffPanel();
$all=[Staff::VIEW,Staff::COSTS,'admin.domains.manage','admin.domains.fund'];
$actor=staffUser($all)->load('role');
[$op,$d]=$makeRenewal(placeholderComFixtureName('status-cost-placeholder.com'),false,1,false,
    static fn()=>ComplimentaryAccounts::set($user->id,$actor,true,'Complimentary account',$clock));
$op->update(['state'=>'held','safe_code'=>'registrar_price_increased','policy'=>[...$op->policy,
    'complimentary_cost_review'=>['amount'=>'8.01','usual'=>'8.00']]]);

// One native server-side table build shares a fresh permission snapshot across all row/cell callbacks.
Auth::setUser($actor);$render=Livewire::actingAs($actor)->test(ListOperations::class);$table=$render->instance()->getTable();
\Illuminate\Support\Facades\DB::flushQueryLog();\Illuminate\Support\Facades\DB::enableQueryLog();
for($row=0;$row<25;$row++){$table->getColumn('note')->record($op->fresh())->getState();}
$grantQueries=array_filter(\Illuminate\Support\Facades\DB::getQueryLog(),fn($q)=>str_contains($q['query'],'"roles"'));
\Illuminate\Support\Facades\DB::disableQueryLog();expect(count($grantQueries)<=2,'Table row reasons share one fresh full-grant snapshot');unset($render,$table);
$failures=[];
$cases=['view'=>[Staff::VIEW],'fund'=>[Staff::VIEW,'admin.domains.fund'],
    'manage and fund'=>[Staff::VIEW,'admin.domains.manage','admin.domains.fund'],
    'costs'=>[Staff::VIEW,Staff::COSTS],'costs and fund'=>[Staff::VIEW,Staff::COSTS,'admin.domains.fund'],
    'all'=>$all,'revoked'=>[Staff::VIEW,'admin.domains.manage','admin.domains.fund']];
foreach($cases as $role=>$permissions){
    // Keep the authenticated model's loaded role stale when permissions change; readers must reload the current grant.
    $actor->role()->first()->update(['permissions'=>$permissions]);Auth::setUser($actor);
    $costs=in_array(Staff::COSTS,$permissions,true);$approve=$costs&&in_array('admin.domains.manage',$permissions,true)&&in_array('admin.domains.fund',$permissions,true);
    $sources=[
        'shared reason'=>static fn()=>Labels::requestStatus($op->fresh())['reason'],
        'request notice'=>static fn()=>json_encode(RequestFacts::notice($op->fresh()),JSON_THROW_ON_ERROR),
        'history'=>static fn()=>json_encode(DomainHistory::row($op->fresh(),$d->fresh(),false),JSON_THROW_ON_ERROR),
        'status'=>static fn()=>json_encode(DomainFacts::status($d->fresh()),JSON_THROW_ON_ERROR),
        'list page'=>static fn()=>Livewire::actingAs($actor)->test(ListOperations::class)->assertCanSeeTableRecords([$op])->html(),
        'request page'=>static fn()=>Livewire::actingAs($actor)->test(ViewOperation::class,['record'=>$op->id])->assertSuccessful()->html(),
        'domain page'=>static fn()=>Livewire::actingAs($actor)->test(ViewDomain::class,['record'=>$d->id])->assertSuccessful()->html(),
    ];
    foreach($sources as $surface=>$read){
        try{
            $text=$read();expect(is_string($text)&&$text!=='','The current hold reason is present');
            if($costs){expect(str_contains($text,'8.01')&&str_contains($text,'8.00'),'Authorized staff retain both costs');}
            else{expect(!str_contains($text,'8.01')&&!str_contains($text,'8.00'),'Registrar costs must be redacted');}
            same($approve,str_contains($text,'Use Approve registrar cost'),'Approval instruction requires the full current grant');
            if(!$costs){expect(str_contains($text,'Ask staff with access to domain costs to review it.'),'Restricted staff retain a clear next step');}
        }catch(Throwable $e){$failures[]=$role.' / '.$surface.': '.$e->getMessage();}
    }
}
expect($failures===[],implode("\n",$failures));
echo "Current complimentary status costs and approval instructions respect fresh grants across list, request, history and domain status\n";
$nativeSuccessful=true;
