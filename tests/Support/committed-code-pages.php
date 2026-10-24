<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Committed owner POST followed by actual customer/staff renders.
declare(strict_types=1);
require_once __DIR__.'/staff-panel.php';
require_once __DIR__.'/reserved-fixtures.php';
$createTransfer = $newTransfer;
$newTransfer = static function (string $name, int $i = 0, bool $paid = true, bool $code = true) use ($createTransfer) {
    return $createTransfer(placeholderComFixtureName($name), $i, $paid, $code);
};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController;
use Paymenter\Extensions\Servers\Domains\Services\{ContactRecovery,HolderPricing,NativePresenter};
// The parent fixture's code POST service, native session and both-holder checkout are used unchanged.
$assertSessionCodeAbsent=static function(array $values,string $code):void {
    $encoded=json_encode($values,JSON_THROW_ON_ERROR);
    $needle=substr(json_encode($code,JSON_THROW_ON_ERROR),1,-1);
    expect(!str_contains($encoded,$needle)&&!str_contains($encoded,substr(json_encode($needle,JSON_THROW_ON_ERROR),1,-1)),'Transfer code is absent from session');
};
$controller=new OwnedDomainController($owners);
$presenter=new NativePresenter($owners,$native,new HolderPricing($prices));
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
staffPanel();$viewer=staffUser(array_keys(Staff::permissions()));
foreach([0,1]as$i){
    $user->properties()->where('key','phone')->update(['value'=>'']);$user->unsetRelation('properties');
    $committed=$newTransfer('committed-private-page-placeholder-'.$i.'.com',$i,true,false);
    $beforeEpp=$committed->fresh()->payload['epp']??null;$keyRequest=$req($committed->domain,'code');
    $invalidCode="bad\nvalue";
    $json=Request::create('/domains/'.$committed->domain_id.'/code','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],
        json_encode(['_request_key'=>$keyRequest->request->get('_request_key'),'transfer_code'=>$invalidCode],JSON_THROW_ON_ERROR));
    $json->setUserResolver(fn()=>$user);$json->setLaravelSession($session);$jsonResult=$action->code($json,$committed->domain_id);
    same(302,$jsonResult->getStatusCode(),'Invalid JSON code returns the form');
    expect(!$json->json()->has('transfer_code'),'Invalid JSON code is scrubbed from the JSON bag');
    $assertSessionCodeAbsent($session->all(),$invalidCode);
    same($beforeEpp,$committed->fresh()->payload['epp']??null,'Invalid JSON code leaves saved transfer payload unchanged');
    $sentinel=bin2hex(random_bytes(32));
    foreach ([$invalidCode,$sentinel] as $control) {
        $nested=['nested'=>['transfer_code'=>'prefix-'.$control.'-suffix']];
        foreach ([$nested,json_encode($nested,JSON_THROW_ON_ERROR)] as $value) {
            $contaminated=$session->all();$contaminated['synthetic_leak']=$value;
            try{$assertSessionCodeAbsent($contaminated,$control);throw new LogicException('Contaminated session was accepted');}
            catch(RuntimeException $error){same('Transfer code is absent from session',$error->getMessage(),'Session assertion rejects nested or serialized contaminated control');}
        }
    }
    $r=$req($committed->domain,'code',['transfer_code'=>$sentinel]);
    $result=$action->code($r,$committed->domain_id);
    expect($result->getStatusCode()===302&&DB::transactionLevel()===0,'Real owner POST commits outside a test rollback');
    expect(($committed->fresh()->payload['epp']??null)===$sentinel,'Committed secret fixture exists');
    expect(!$r->request->has('transfer_code'),'Secret scrubbed from committed request');
    $assertSessionCodeAbsent($session->all(),$sentinel);
    expect(!str_contains(DB::table('domains_operations')->where('id',$committed->id)->value('payload'),$sentinel),'Committed code encrypted at rest');
    ContactRecovery::hold($committed->id);
    foreach(['show','setup','code']as$page){
        storefrontViews();app('view')->getFinder()->flush();
        $r=Request::create('/domains/'.$committed->domain_id,'GET');$r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);
        $view=$controller->$page($r,$committed->domain_id);$html=$view->render();$dto=$view->getData()['domain'];
        expect(!str_contains($html,$sentinel),'Committed code absent from real owner markup');
        if($page!=='code'){expect($dto->setup['contacts']&&str_contains($html,$page==='show'?'Finish setup to start registration.':'Use updated account details'),'Customer render includes the committed contact hold');}
        $dom=storefrontDom($html);
        foreach($dom->query('//@*')as$attribute){expect(!str_contains(html_entity_decode($attribute->value,ENT_QUOTES|ENT_HTML5),$sentinel),'Committed code absent from DOM attributes');}
        foreach($dom->query('//input[@name="transfer_code"]')as$input){
            expect($input->getAttribute('type')==='password'&&$input->getAttribute('value')==='','Offered code entry is an empty password field');
        }
        expect(!str_contains(json_encode($dto,JSON_THROW_ON_ERROR),$sentinel),'Owner DTO serialization excludes committed code');
        $serviceDto=$presenter->service($user,$committed->service_id);$invoiceDtos=$presenter->invoice($user,$committed->invoice_id);
        expect(($serviceDto?->transfer['received']??false)===true,'Native service presenter maps the committed operation');
        expect(count($invoiceDtos)===1&&(reset($invoiceDtos)->transfer['received']??false)===true,'Native invoice presenter maps the committed operation');
        expect(!str_contains(json_encode($serviceDto,JSON_THROW_ON_ERROR),$sentinel),'Native service DTO excludes committed code');
        expect(!str_contains(json_encode($invoiceDtos,JSON_THROW_ON_ERROR),$sentinel),'Native invoice DTO excludes committed code');
        $assertSessionCodeAbsent($session->all(),$sentinel);
        if($page==='code'){
            expect($dto->transfer['received']&&substr_count($html,\Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController::CODE_SAVED)===1,'Real committed-code page acknowledges receipt');
            same(1,$dom->query('//input[@name="transfer_code"]')->length,'Unsent before-cutoff code editor is offered without prefill');
        }
    }
    Auth::setUser($viewer);
    $component=Livewire::actingAs($viewer)->test(ViewOperation::class,['record'=>$committed->id]);
    expect(str_contains($component->html(),'Auth code')&&str_contains($component->html(),'Saved')&&!str_contains($component->html(),'Customer has not entered it'),'Staff render positively confirms committed encrypted code receipt');
    expect(!str_contains($component->html(),$sentinel),'Same committed code absent from real staff markup');
    expect(!str_contains(json_encode($component->snapshot,JSON_THROW_ON_ERROR),$sentinel),'Same committed code absent from public Livewire state');
    $assertSessionCodeAbsent($session->all(),$sentinel);
    $user->properties()->where('key','phone')->update(['value'=>'+1.2025550100']);$user->unsetRelation('properties');
}
Auth::setUser($user);storefrontViews();app('view')->getFinder()->flush();
