<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real completed holdings and engine, synthetic transport.
declare(strict_types=1);
require __DIR__.'/owner-management-ready.php';
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnerActionController, OwnedDomainController};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Services\{OwnedDomains,OwnerPorts,OwnerSetup,TransferInputs,Refunds,DomainManagement};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
// Provider-specific contact errors come from real engine validation, before any request is created.
$invalidContact=$registrant;$invalidContact['country']='US';$invalidContact['state_province']='';$invalidContact['first_name']=str_repeat('x',$free?33:256);
$editing='contacts';$before=Operation::count();$r=$post($editing,['role'=>'all','contact'=>$invalidContact]);$controller->manage($r,$d->id,$editing);
same($before,Operation::count(),'Invalid provider contact fields create no operation');
$contactMessages=['state_province'=>$free?'For the US or Canada, enter the 2-letter state or province code. For other countries, enter its name.':'Enter your state or province for the US, Canada or Australia.','first_name'=>'Enter your first name. Use '.($free?32:255).' or fewer characters. Accented and non-Latin letters count as 2 or more.'];
app('view')->share('errors',$session->get('errors'));$contactDom=storefrontDom((new OwnedDomainController($owners))->show($r,$d->id)->render());
foreach($contactMessages as$field=>$message){
    same($message,$session->get('errors')->first('contact.'.$field),'Exact registrar-specific contact error: '.$field);
    $input=$contactDom->query('//form[contains(@action,"/manage/'.$editing.'")]//*[@name="contact['.$field.']"][@aria-invalid="true"]');same(1,$input->length,'Contact field has its own error');
    $descriptions=[];foreach(explode(' ',$input->item(0)->getAttribute('aria-describedby'))as$id){foreach($contactDom->query('//*[@id="'.$id.'"]')as$node){$descriptions[]=trim($node->textContent);}}
    expect(in_array($message,$descriptions,true),'Exact contact error describes '.$field);
}
// Plain edits need no acknowledgement: this contacts request reaches engine validation.
$r=$post($editing,['role'=>'all','contact'=>[]]);$r->request->remove('confirmed');$controller->manage($r,$d->id,$editing);
expect($session->get('errors')->has('contact.phone'),'Engine contact paths map to submitted fields');
app('view')->share('errors',$session->get('errors'));
$html=(new OwnedDomainController($owners))->show($r,$d->id)->render();$dom=storefrontDom($html);
expect($dom->query('//input[@name="contact[phone]"][@aria-invalid="true"]')->length>0,'Real Blade identifies the invalid contact field');
expect($dom->query('//input[@name="contact[phone]"][@aria-invalid="true"]/ancestor::dialog[@open]')->length>0,'Invalid contact forms open without script');
expect(str_contains($html,'Enter a phone number with its country code, such as +1 206 555 0100.'),'Contact error explains the expected format');
$r=$post('auth_code_email');$controller->manage($r,$d->id,'auth_code_email');
$dto=$owners->present($d->fresh());same([],$dto->management['auth_code_email']['actions'],'Email cooldown removes the action while retaining its stored status');
$peer=$d->operations()->where('action','auth_code_email')->sole();Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$peer->id)->update(['state'=>'uncertain']);
$dto=$owners->present($d->fresh());expect($dto->management['auth_code_email']['progress']['pending'],'An unresolved peer retains its pending status');same([],$dto->management['auth_code_email']['actions'],'Pending email has no repeat action');
$before=Operation::count();
Illuminate\Support\Facades\DB::table('services')->where('id',$d->service_id)->update(['user_id'=>commerceUser()->id]);$r=$post('lock',['locked'=>'0']);
try{$controller->manage($r,$d->id,'lock');expect(false,'Native ownership drift refused');}catch(Illuminate\Database\Eloquent\ModelNotFoundException){}
same($before,Operation::count(),'Foreign native owner creates no request');
// Real native CSRF middleware refuses absent and mismatched tokens before a controller can run.
app()->instance('env','production');$csrf=new Illuminate\Foundation\Http\Middleware\ValidateCsrfToken(app(),app('encrypter'));
foreach([[],['_token'=>'wrong']]as$data){$r=Request::create('/domains/'.$d->id.'/manage/lock','POST',$data);$r->setLaravelSession($session);
    try{$csrf->handle($r,fn()=>throw new RuntimeException('CSRF bypass'));expect(false,'CSRF refused');}catch(Illuminate\Session\TokenMismatchException){}}
app()->instance('env','testing');
$nativeSuccessful=true;echo "Owner management contact errors, email cooldown, native ownership and CSRF passed.\n";
