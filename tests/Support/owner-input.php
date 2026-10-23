<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic owner retry and list projection regressions.
declare(strict_types=1);
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\ContactDetails;
$contact=ContactDetails::collect($user)['registrant'];
$contact['first_name']='Typed <contact> & name';$contact['phone']='bad phone';
foreach($free?['contact_profile','contacts']:['contacts']as$intent){
    $session->forget(['_old_input','domains_failed_form']);
    $before=Operation::count();
    $r=$post($intent,['role'=>'tech','contact'=>[...$contact,'password'=>'PRIVATE-NESTED'],
        'transfer_code'=>'PRIVATE-CODE','password'=>'PRIVATE-PASSWORD','_token'=>'PRIVATE-TOKEN']);
    $response=$controller->manage($r,$d->id,$intent);
    same(302,$response->getStatusCode(),'Invalid contact redirects to its page');
    same(route('domains.domains.show',$d->id),$response->getTargetUrl(),'Contact error returns to domain');
    same($before,Operation::count(),'Invalid contact creates no operation');
    same(['contact'=>$contact,'role'=>'tech'],$session->get('_old_input'),'Only displayed contact fields and role are flashed');
    expect($session->get('errors')->has('contact.phone'),'Phone error retained');
    app('view')->share('errors',$session->get('errors'));
    $html=(new OwnedDomainController($owners))->show($r,$d->id)->render();$retry=storefrontDom($html);
    if(isset($argv[1])&&is_dir($argv[1])){file_put_contents($argv[1].'/retry-'.($free?'namesilo':'enom').'-'.$intent.'.html',$html);}
    foreach($contact as$field=>$value){
        $nodes=$retry->query('//form[contains(@action,"/manage/'.$intent.'")]//*[@name="contact['.$field.']"]');
        same(1,$nodes->length,'Failed form contains contact field '.$field);
        $node=$nodes->item(0);
        same($value,$node->tagName==='select'?$retry->query('option[@selected]',$node)->item(0)?->getAttribute('value'):$node->getAttribute('value'),'Typed contact survives redirect/render: '.$field);
    }
    same(1,$retry->query('//dialog[contains(@class,"domains-edit")][@open]')->length,'Only the failed contact details open');
    same(1,$retry->query('//input[@name="contact[phone]"][@aria-invalid="true"]')->length,'Only the failed contact has its field error');
    same('tech',$retry->query('//form[contains(@action,"/manage/'.$intent.'")]//select[@name="role"]/option[@selected]')->item(0)?->getAttribute('value'),'Contact role survives');
    expect(!str_contains($html,'<contact>')&&!str_contains($html,'PRIVATE-'),'Retained contact HTML is escaped and secrets are absent');
    if($free){$otherIntent=$intent==='contacts'?'contact_profile':'contacts';
        same('',$retry->query('//form[contains(@action,"/manage/'.$otherIntent.'")]//input[@name="contact[first_name]"]')->item(0)->getAttribute('value'),'Other contact step stays blank');}
    // Simulate a different domain form during the same flash lifetime.
    $session->put('domains_failed_form',route('domains.domains.manage',[$d->id+100,$intent]));
    $otherPage=storefrontDom((new OwnedDomainController($owners))->show($r,$d->id)->render());
    same(0,$otherPage->query('//dialog[contains(@class,"domains-edit")][@open]')->length,'Another domain marker cannot open this form');
    same('',$otherPage->query('//input[@name="contact[first_name]"]')->item(0)->getAttribute('value'),'Another domain marker cannot replay contact data');
}
$session->forget(['_old_input','domains_failed_form']);app('view')->share('errors',new Illuminate\Support\ViewErrorBag);
$r=$post('nameservers',['nameservers'=>"ns1.example.com\ninvalid",'transfer_code'=>'PRIVATE-CODE','password'=>'PRIVATE-PASSWORD']);
$controller->manage($r,$d->id,'nameservers');
same(['nameservers'=>"ns1.example.com\ninvalid"],$session->get('_old_input'),'Only nameserver text is retained');
app('view')->share('errors',$session->get('errors'));
$retry=storefrontDom((new OwnedDomainController($owners))->show($r,$d->id)->render());
same("ns1.example.com\ninvalid",$retry->query('//textarea[@name="nameservers"]')->item(0)->textContent,'Nameserver retry retains text');
$session->forget(['_old_input','domains_failed_form']);app('view')->share('errors',new Illuminate\Support\ViewErrorBag);
$r=$post('lock',['locked'=>'0','confirmed'=>'0','password'=>'PRIVATE-PASSWORD']);$response=$controller->manage($r,$d->id,'lock');
same(null,$session->get('_old_input'),'Lock confirm step flashes no submitted fields');
$retry=storefrontDom($response->getContent());
same('0',$retry->query('//select[@name="locked"]/option[@selected]')->item(0)?->getAttribute('value'),'Lock state survives the confirm step');
same(0,$retry->query('//input[@name="confirmed"]')->length,'Confirmation is never replayed');
expect(!str_contains($response->getContent(),'PRIVATE-PASSWORD'),'Confirm step never echoes unknown fields');
$r=$post('privacy_disable',['password'=>'PRIVATE-PASSWORD']);$r->request->remove('confirmed');$controller->manage($r,$d->id,'privacy_disable');
expect(empty($session->get('_old_input')),'Privacy requests retain no submitted fields');
$session->forget(['_old_input','domains_failed_form']);app('view')->share('errors',new Illuminate\Support\ViewErrorBag);
// Compare the same real holding in both projections, including activity; only form availability differs.
DB::flushQueryLog();DB::enableQueryLog();$full=$owners->present($d->fresh());$fullQueries=DB::getQueryLog();
DB::flushQueryLog();$row=$owners->present($d->fresh(),false);$rowQueries=DB::getQueryLog();DB::disableQueryLog();
$settingsReads=static fn($queries)=>array_values(array_filter($queries,fn($q)=>str_contains($q['query'],'"settings"')));
expect(count($settingsReads($fullQueries))>0,'Full management projection reads native settings');
same([],$settingsReads($rowQueries),'List projection does not read server settings');
same([],$row->management,'List rows contain no management forms');
same([...get_object_vars($full),'management'=>[],'changesUnavailable'=>false,'owner'=>null],get_object_vars($row),'Other row facts remain identical');
$list=storefrontDom((new OwnedDomainController($owners))->index($r)->render());
same(1,$list->query('//tbody/tr')->length,'Index retains the owned row');
expect(str_contains($list->query('//tbody/tr')->item(0)->textContent,$full->name),'Index retains domain name');
