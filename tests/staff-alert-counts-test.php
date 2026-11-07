<?php
// The registrar account page: each count links to a list showing exactly those rows, and amber means staff act; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarAccount,RegistrarSetup};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ListDomains;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ListOperations;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,RegistrarFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
// One staff price read checks staff access and the catalogue gate a fixed few times; per term it would add 36 here.
const GRANT_READS=12;
extract(commerceFixture(true,registrars:['enom']));tldPanel();Carbon\Carbon::setTestNow($now);$server=(int)$tlds[0]->server_id;
$staff=staffUser([Staff::VIEW,Staff::POLICY]);Auth::setUser($staff);
$account=Account::where('server_id',$server)->sole();
$bind=['server_id'=>$server,'registrar_key'=>$account->registrar_key,'account_fingerprint'=>$account->account_fingerprint];
// One domain per request, so every request is bound to its own domain as the Registrar requests list requires.
$request=static function(string $name,string $action,string $state,array $extra=[])use($bind):Operation {
    static $n=0;$n++;
    $d=Domain::create([...$bind,'domain'=>$name.'.com','sld'=>$name,'tld'=>'com','user_id'=>900+$n,'service_id'=>900+$n,'status'=>'registration_pending']);
    return Operation::create([...$bind,'domain_id'=>$d->id,'service_id'=>$d->service_id,'authority_generation'=>$d->authority_generation,
        'operation_key'=>'counts-'.$n,'action'=>$action,'currency'=>'USD','amount'=>'10.00','state'=>$state,'payload'=>[],'attempt'=>[],'policy'=>[],'cost'=>[],'funding'=>[],...$extra]);
};
$request('counts-paid-unconfirmed','register','uncertain',['funding_basis'=>'paid_invoice']);
$request('counts-held-renewal','renew','held',['safe_code'=>'renewal_definitely_unsent']);
$request('counts-unconfirmed-change','update_nameservers','uncertain');
$request('counts-held-order','register','held');
$request('counts-unpaid','renew','awaiting_payment');
$request('counts-done','transfer','succeeded');
// A request whose domain moved to another service is not listed, so it is not counted either.
$moved=$request('counts-moved','renew','queued');Domain::whereKey($moved->domain_id)->update(['service_id'=>999]);

$html=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server])->assertSuccessful()->html();
// The page row for a label: its value, sub-line links and action links.
$row=static function(string $label,string $html):array {
    $doc=Dom\HTMLDocument::createFromString($html,LIBXML_NOERROR);
    foreach($doc->querySelectorAll('.domains-row') as $node){
        if(!str_contains($node->textContent,$label)){continue;}
        $links=[];foreach($node->querySelectorAll('a[href]') as $a){$links[trim(preg_replace('/\s+/',' ',$a->textContent))]=['href'=>$a->getAttribute('href'),'class'=>(string)$a->getAttribute('class')];}
        return ['text'=>trim(preg_replace('/\s+/',' ',$node->textContent)),'links'=>$links];
    }
    expect(false,'Row on the page: '.$label);return [];
};
$requests=$row('Registrar requests',$html);
expect(str_contains($requests['text'],'5 open'),'Five open requests');
expect(str_contains($requests['text'],'1 awaiting payment')&&str_contains($requests['text'],'1 on hold, 3 under review'),'Every open request is in the breakdown: '.$requests['text']);
// A request count and a domain alert count count different records, but every domain count uses the same current rule.
$ops=Operation::where('server_id',$server)->where('state','uncertain')->get();
same(null,DomainResource::getNavigationBadge(),'Initially no domain alerts');
foreach($ops as $op){ObligationAlerts::record($op->domain,'operation_review',now()->getTimestamp(),(int)$op->id);}
$alerts=\Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::alerts($account);
same('2',$alerts['value'],'Two domains now have open alerts');
same('2',DomainResource::getNavigationBadge(),'The badge immediately agrees with the account row');
DB::table('domains_obligation_alerts')->update(['resolved_at'=>now()->getTimestamp()]);
same(null,DomainResource::getNavigationBadge(),'Resolving the alerts immediately clears the badge');
$nativeSuccessful=true;echo "Staff counts explain all open requests and stay current.\n";
