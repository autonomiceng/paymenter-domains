<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native fixtures and synthetic registrar only.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
require __DIR__.'/renewal-execution.php';
require __DIR__.'/owner-pages.php';
require __DIR__.'/staff-panel.php';
use App\Helpers\ExtensionHelper;
use App\Models\{Invoice,ServiceCancellation};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController,OwnerAutoRenewController};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Models\{Operation,WatchAudit};
use Paymenter\Extensions\Servers\Domains\Services\{DomainSummaries,OwnerAutoRenew,RenewalStop,RuntimeIneligible,RuntimeReason};
\Paymenter\Extensions\Servers\Domains\Services\HoldingProvenanceSchema::install();
\Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();
\Paymenter\Extensions\Servers\Domains\Services\ReminderSchema::install();
$preference=new OwnerAutoRenew($clock);$controller=new OwnerAutoRenewController($owners,$preference);
$session=app('request')->session();$other=commerceUser();
$holder=static function(string $name)use($prepare,$reconcile){[$op,$binding]=$prepare($name);same('succeeded',$reconcile->run($op->id)->state,'Confirmed registration');return [$op->domain()->firstOrFail(),$binding];};
$server=$product->server;
foreach(['domains_manual_renewals_enabled','domains_billing_enabled'] as $key){$server->settings()->updateOrCreate(['key'=>$key],['value'=>true,'type'=>'boolean']);}
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');
$req=static function($domain,array $data,$actor=null,string $intent='auto-renew')use($session,$user):Request {
    $r=Request::create('/domains/'.$domain->id.'/auto-renew','POST',$data);$r->setUserResolver(fn()=>$actor??$user);$r->setLaravelSession($session);
    $r->request->set('_request_key',FormKeys::issue($r,'owner:'.$domain->id.':'.$intent));return $r;
};
$save=static function($domain,bool $enabled)use($controller,$req,$session):void {
    $session->forget('errors');$response=$controller->save($req($domain,['enabled'=>$enabled?'1':'0','confirmed'=>'1']),$domain->id);
    same(route('domains.domains.show',$domain->id),$response->getTargetUrl(),'Preference returns to domain');
    same(null,$session->get('errors'),'Preference succeeds');
};
$page=static function($domain)use($owners,$req):string {storefrontViews();return (new OwnedDomainController($owners))->show($req($domain,[]),$domain->id)->render();};
