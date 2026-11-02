<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native transactions, paid-invoice identity, one-use forms and queue dispatch cannot be proved by a unit fixture.
require __DIR__.'/Support/owned.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController;
use Paymenter\Extensions\Servers\Domains\Jobs\RunOperation;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\{ContactDetails,ContactRecovery,DomainManagement,OwnerSetup};

app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
\Paymenter\Extensions\Servers\Domains\Services\RuntimeSchema::install();
Queue::fake();$session=app('request')->session();
PHPUnit\TextUI\Configuration\Registry::init((new PHPUnit\TextUI\CliArguments\Builder)->fromParameters([]),PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration::create());
$actions=new OwnerActionController($owners,new OwnerSetup($clock,$ports),$inputs,new DomainManagement($bindings,$clock),$ports);
$post=static function($op,array $contact,bool $save=false)use($user,$session):Request {
    $r=Request::create('/domains/'.$op->domain_id.'/account-contacts','POST',['contact'=>$contact,'save_account'=>$save?'1':'0']);
    $r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);
    $r->request->set('_request_key',FormKeys::issue($r,'owner:'.$op->domain_id.':recovery'));return $r;
};
foreach([false,true]as$save){
    $user->properties()->where('key','phone')->update(['value'=>'']);
    $name=placeholderComFixtureName('inline-contact-'.(int)$save.'-placeholder.com');
    $cart=commerceCart($user);commerceAdd($cart,$native->product($tlds[0],'register'),$name);
    app('request')->cookies->set('cart',$cart->ulid);Illuminate\Support\Once::flush();
    storefrontViews();app('view')->getFinder()->flush();
    $checkout=view('domains::checkout-contact')->render();$fields=storefrontDom($checkout);
    same('1 Synthetic Street',$fields->query('//input[@name="contact[address1]"]')->item(0)->getAttribute('value'),'Checkout contact prefills from account');
    same(1,$fields->query('//form[@id="domains-checkout-contact"]')->length,'Shared contact form compiles on native checkout');
    same(0,$fields->query('//input[@name="save_account"][@checked]')->length,'Account saving defaults off');
    commerceLegacyCheckout($user,$cart);
    $op=Domain::where('domain',$name)->sole()->operations()->sole();
    App\Helpers\ExtensionHelper::addPayment($op->invoice_id,null,$op->amount);ContactRecovery::hold($op->id);
    storefrontViews();app('view')->getFinder()->flush();
    $before=$op->fresh()->getRawOriginal();$account=ContactDetails::collect($user);
    $contact=$account['registrant'];$contact['address1']='42 Corrected Street';$contact['phone']='invalid';
    $response=$actions->contacts($post($op,$contact,$save),$op->domain_id);
    same(422,$response->getStatusCode(),'Invalid inline recovery stays on the form');
    if (($out=$argv[1]??null) && !$save) {
        require_once __DIR__.'/Support/render-artifacts.php';
        writeRenderArtifact($out.'/checkout.html','<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body>'.$checkout.'</body></html>');
        writeRenderArtifact($out.'/recovery.html',$response->getContent());
    }
    $dom=storefrontDom($response->getContent());
    same($save?1:0,$dom->query('//input[@name="save_account"][@checked]')->length,'Account consent retained after a field error');
    same(1,$dom->query('//input[@name="contact[phone]"][@aria-invalid="true"]')->length,'Exact field error is attached to phone');
    same('42 Corrected Street',$dom->query('//input[@name="contact[address1]"]')->item(0)->getAttribute('value'),'Submitted valid fields retained inline');
    same($before,$op->fresh()->getRawOriginal(),'Invalid whole contact preserves encrypted paid snapshot');
    same($account,ContactDetails::collect($user),'Invalid recovery cannot save account fields');
    $contact['phone']='+1.2065550199';$response=$actions->contacts($post($op,$contact,$save),$op->domain_id);
    same(302,$response->getStatusCode(),'Valid inline recovery proceeds');$fixed=$op->fresh();
    same(['queued',null],[$fixed->state,$fixed->safe_code],'Corrected registration enters the existing queue path');
    same('42 Corrected Street',$fixed->payload['contacts']['tech']['address1'],'Whole validated order contact replaces all roles');
    same(1,count($fixed->policy['contact_readmissions']),'Exactly one audited replacement');
    same($save,$fixed->policy['contact_readmissions'][0]['saved_account'],'Explicit account choice audited');
    same($save?'+1.2065550199':'',ContactDetails::collect($user)['registrant']['phone'],'Account changes only with the separate checkbox');
    Queue::assertPushed(RunOperation::class,fn($job)=>$job->operationId===$fixed->id);
    $raw=$fixed->getRawOriginal();$actions->contacts($post($fixed,$contact),$fixed->domain_id);
    same($raw,$fixed->fresh()->getRawOriginal(),'Repeated submitted contact form cannot replace a queued snapshot');
}
$cart=commerceCart($user);$name=placeholderComFixtureName('checkout-save-placeholder.com');commerceAdd($cart,$native->product($tlds[0],'register'),$name);
$contact=commerceOrderContact($user);$contact['address1']='42 Account Street';
Illuminate\Support\Once::flush();
Livewire\Livewire::actingAs($user)->withCookies(['cart'=>$cart->ulid])->test(App\Livewire\Cart::class)->set('use_credits',false)
    ->call('checkout',['contact'=>$contact,'save_account'=>true])->assertHasNoErrors();
same('42 Account Street',ContactDetails::collect($user)['registrant']['address1'],'Checkout saves account only with explicit consent');
same(config('app.countries.US'),$user->properties()->where('key','country')->value('value'),'Saved account country remains a native select value');
same('42 Account Street',Domain::where('domain',$name)->sole()->operations()->sole()->payload['contacts']['tech']['address1'],'Consent preserves the validated order snapshot');
same([], $state->calls, 'Inline contact recovery performs no direct provider sends');
same(0, $state->reads, 'Inline contact recovery performs no direct provider reads');
$nativeSuccessful=true;echo "Inline paid contact recovery, exact errors, account consent, audit and immediate dispatch passed.\n";
