<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/staff-operation-fixture.php';
require_once __DIR__.'/fixture-checkpoint.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Labels,OperationActions,RequestFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Data\RenewalCode;

// Shared release assertions; cases live in focused entrypoints.
$mismatches=[];$reading=false;
$guidance=static function($row,array $notice,bool $visible):void{
    if(!in_array($row->safe_code,['pre_send_interrupted','renewal_term_refused','registry_details_unsupported'],true)){return;}
    $body=(string)($notice['body']??'');
    expect($visible||!str_contains($body,'Staff must release the hold after reviewing the saved order.'),'Release guidance requires an actual offer: '.$row->safe_code);
    if(!$visible){
        expect(str_contains($body,'Staff must check the original registrar result before retrying'),'A refused release keeps registrar-result guidance: '.$row->safe_code);
        same(['domain',null],[$notice['link']??null,$notice['link_label']??null],'A refused release opens its domain for review');
    }else{
        same('Staff must release the hold after reviewing the saved order.',\Paymenter\Extensions\Servers\Domains\Admin\Support\RequestHoldReasons::for($row)['body'],'An offered recovery keeps its release guidance');
    }
};
$all=[...$all,Staff::COSTS,Staff::METADATA];$staff->role->update(['permissions'=>$all]);
$deployment=[];foreach(['domains.enom_identity.environment','domains.enom_catalogue.enabled','domains.enom_writes.registration','domains.enom_writes.transfer'] as $key){$deployment[$key]=config($key);}
DB::listen(static function($query)use(&$reading):void{
    if($reading){expect(DB::transactionLevel()===0&&preg_match('/^\s*select\b/i',$query->sql)&&!str_contains(strtolower($query->sql),'for update'),'Offer loads read-only facts without locks');}
});
