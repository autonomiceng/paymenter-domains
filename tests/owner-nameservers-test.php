<?php
// Synthetic native owner nameserver inputs; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/owned.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
require_once __DIR__.'/Support/raw-input.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController, OwnerActionController};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{OwnerSetup, DomainManagement};
config(['settings.company_name'=>'Example Hosting']);
$action=new OwnerActionController($owners,new OwnerSetup($clock,$ports),$inputs,new DomainManagement($bindings,$clock),$ports);
$session=app('request')->session();
$request=static function($domain,array $data)use($user,$session):Request {
    config(['settings.company_name'=>'Example Hosting']);
    $session->forget(['errors','_old_input','domains_status']);
    $r=Request::create('/domains/'.$domain->id.'/setup','POST',$data);
    $r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);
    $r->request->set('_request_key',FormKeys::issue($r,'owner:'.$domain->id.':setup'));
    return nativeNormalizeInput($r);
};
// Match the queued, virgin Enom registration from the demo without any installed-app writes.
$name=placeholderComFixtureName('owner-nameservers-setup-placeholder.com');
$cart=commerceCart($user);commerceAdd($cart,$native->product($tlds[0],'register'),$name);commerceCheckout($user,$cart);
storefrontViews();
$d=Domain::where('domain',$name)->sole();$op=$d->operations()->sole();
DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'paid']);
App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>$op->amount,'fee'=>'0.00','status'=>'succeeded']);
$op->update(['state'=>'queued']);
same([],$op->attempt??[],'Virgin registration has no attempt');
$nativeMoney=static fn()=>array_map(fn($table)=>DB::table($table)->orderBy('id')->get()->toJson(),['invoices','invoice_items','invoice_transactions','credits','jobs','failed_jobs']);
$money=$nativeMoney();$count=Operation::count();
foreach ([
    [['ns1.example.com'],'Enter nameservers as hostnames, one per line.'],
    ["NS_BAD.example.com\nns2.example.com","NS_BAD.example.com isn't a valid hostname. Use a name like ns1.example.com."],
    ["ns1.example.com..\nns2.example.com","ns1.example.com.. isn't a valid hostname. Use a name like ns1.example.com."],
    ['solo.example.com.','Enter at least 2 nameservers, one per line, or leave this blank to use Example Hosting nameservers.'],
    [implode("\n",array_map(fn($i)=>'ns'.$i.'.example.com',range(1,13))),'Enter no more than 12 nameservers.'],
    ["bad_host.example.com\nns2.example.com","bad_host.example.com isn't a valid hostname. Use a name like ns1.example.com."],
    ["ns1.example.com\nNS1.EXAMPLE.COM.",'ns1.example.com is listed more than once.'],
    ["<bad>.example.com\nns2.example.com","<bad>.example.com isn't a valid hostname. Use a name like ns1.example.com."],
] as [$text,$message]) {
    $r=$request($d,['nameservers'=>$text]);$response=$action->setup($r,$d->id);
    same(302,$response->getStatusCode(),'Invalid setup redirects');
    same($message,$session->get('errors')->first('nameservers'),'Exact cause appears beside nameservers: '.json_encode($session->get('errors')->getMessages()));
    expect(!isset($op->fresh()->policy['owner_setup']),'Invalid input never freezes setup');
    app('view')->share('errors',$session->get('errors'));
    $html=(new OwnedDomainController($owners))->setup($r,$d->id)->render();$dom=storefrontDom($html);
    same($message,$dom->query('//*[@id="setup-ns-error"]')->item(0)?->textContent,'Real Blade renders the precise escaped field error');
    expect(!str_contains($html,'<bad>'),'Submitted markup is escaped');
    expect(str_contains($html,'Check the highlighted fields below.'),'Top banner stays short');
}
foreach ([''," \n \r\n",null] as $blank) {
    $r=$request($d,['nameservers'=>$blank]);same(null,$r->request->get('nameservers'),'Native middleware converts blank input to null');
    $response=$action->setup($r,$d->id);
    same(route('domains.domains.show',$d->id),$response->getTargetUrl(),'Blank setup saves and returns to the domain');
    same('Setup saved.',$session->get('domains_status'),'Blank save succeeds');
    same(['nameservers'=>[],'privacy'=>false],$op->fresh()->policy['owner_setup']['desired']??null,'Default nameservers saved, including repeated save');
}
same($count,Operation::count(),'Setup creates no operation');same($money,$nativeMoney(),'Setup leaves finance unchanged');
same('queued',$op->fresh()->state,'Setup does not dispatch');same([],$op->fresh()->attempt??[],'No attempt or provider work');
// A fresh registration accepts root dots, normalizes case and keeps only hostnames.
$name=placeholderComFixtureName('owner-nameservers-dots-placeholder.com');
$cart=commerceCart($user);commerceAdd($cart,$native->product($tlds[0],'register'),$name);commerceCheckout($user,$cart);
storefrontViews();
$d=Domain::where('domain',$name)->sole();$op=$d->operations()->sole();
$r=$request($d,['nameservers'=>"NS1.EXAMPLE.COM.\r\nns2.example.com."]);$action->setup($r,$d->id);
same(['ns1.example.com','ns2.example.com'],$op->fresh()->payload['nameservers']??null,'Trailing root dots are removed before setup validation');
// Purchase rules differ from existing-domain management at this provider.
$policy=DB::table('domains_sales_policies')->where('tld','com')->first();
$sellers->change('com',$policy->revision,$tlds[1]->server_id,true,true,$clock->now());
$name=placeholderComFixtureName('owner-nameservers-purchase-placeholder.com');
$cart=commerceCart($user);commerceAdd($cart,$native->product($tlds[1],'register'),$name);commerceCheckout($user,$cart);storefrontViews();
$d=Domain::where('domain',$name)->sole();$op=$d->operations()->sole();
foreach ([
    ['', 'Enter at least 2 nameservers, one per line.'],
    ['ns1.'.$d->domain."\nns2.example.com",'Use nameservers outside '.$d->domain.', such as ns1.example.com.'],
    ["xn--bad.example.com\nns2.example.com","xn--bad.example.com isn't a valid hostname. Use a name like ns1.example.com."],
] as [$text,$message]) {
    $action->setup($request($d,['nameservers'=>$text]),$d->id);
    same($message,$session->get('errors')->first('nameservers'),'Purchase-specific refusal explains the cause');
    expect(!isset($op->fresh()->policy['owner_setup']),'Invalid purchase input never freezes setup');
}
$action->setup($request($d,['nameservers'=>"NS1.12-34.\nns2.example.com."]),$d->id);
same(['ns1.12-34','ns2.example.com'],$op->fresh()->payload['nameservers']??null,'Purchase permits its own final-label grammar and normalizes root dots');
$nativeSuccessful=true;echo "Owner nameserver exact errors, escaped render, middleware blank defaults, retries and trailing dots passed.\n";
