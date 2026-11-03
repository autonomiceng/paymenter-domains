<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
expect(is_file(dirname(__DIR__).'/Domains/Catalogue/Capabilities.php'),'Independent renewal capabilities required');
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Capabilities, CallerPorts, Projection, UpgradeConversion};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances, ObservationSchema, OfferSchema, OfferWorkSchema};
ObservationSchema::install(); OfferSchema::install(); OfferWorkSchema::install();
[$pending,,$binding]=$makeRenewal(placeholderComFixtureName('offer-paid-renewal-placeholder.com'));
[$pendingEngine,$pendingWriter,$completion]=$compose($binding);
same('pending',$pendingEngine->run($pending->id,$pendingWriter)->state,'Paid renewal retains its acknowledged attempt');
[$uncertain]=$makeRenewal(placeholderComFixtureName('offer-uncertain-renewal-placeholder.com'));
[$uncertainEngine,$uncertainWriter]=$compose($binding); $state->lost=true;
same('uncertain',$uncertainEngine->run($uncertain->id,$uncertainWriter)->state,'Lost renewal reply retains uncertain attempt');
$frozen=static function(): string {
    $rows=[]; foreach(['domains_operations','invoices','invoice_items','invoice_transactions','credits'] as $table){$rows[$table]=DB::table($table)->orderBy('id')->get()->all();}
    return serialize($rows);
};
$before=$frozen(); $writes=$state->writes;
foreach(Account::all() as $account){(new UpgradeConversion)->batch(CatalogueAcceptances::binding($account));}
$caps=new Capabilities(new CallerPorts(new Projection($native),$clock,$bindings,static fn()=>null,static fn()=>true),$clock,static fn()=>null);
same('10.40',$caps->holder($binding,'com')->retail,'Renewal holder keeps retained annual retail with unknown facts');
commerceReject(fn()=>$caps->requireRenewal($binding,'com'));
same($before,$frozen(),'Conversion and capability reads preserve both frozen renewal attempts and all native money');
same('uncertain',$uncertainEngine->run($uncertain->id,$uncertainWriter)->state,'Unknown facts cannot authorize uncertain renewal replay');
same($writes,$state->writes,'Uncertain renewal sends no second write');
$state->name=$pending->domain->domain; $state->expiry='2028-10-18'; $state->charge='8.00';
expect(method_exists($caps,'identities'),'Historical Enom reconciliation needs identity-only suffixes when current facts are unknown');
$identityRegistry=new \Paymenter\Extensions\Servers\Domains\Services\RegistrarRegistry;
$identityRegistry->register('enom',['token'],$verify,fn($b,$c)=>new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver(
    new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\Client($b,'account-enom',$c['token'],$transport),$caps->identities($b)));
$completion=new \Paymenter\Extensions\Servers\Domains\Services\RenewalCompletion(new \Paymenter\Extensions\Servers\Domains\Services\AccountBindings($identityRegistry),$clock);
same('succeeded',$completion->run($pending->id)->state,'Paid pending renewal reconciles its original charge with unknown public facts');
same($writes,$state->writes,'Renewal completion is read only');
// Expiry after admission/seal must revoke spending even when no retained row changes.
[$virgin,,$binding]=$makeRenewal(placeholderComFixtureName('offer-expiring-renewal-placeholder.com'));
$factsUntil=$clock->now()->modify('+26 seconds');
DB::table('domains_tlds')->where('server_id',$binding->serverId)->where('tld','com')->update([
    'catalogue_facts'=>\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::encodeFacts(new \Paymenter\Extensions\Servers\Domains\Catalogue\Facts(
        ['register'=>[1],'renew'=>[1],'transfer'=>[1]],[],true,false,false,'synthetic',$clock->now(),$factsUntil))]);
$authority=new \Paymenter\Extensions\Servers\Domains\Services\RenewalAuthority($renewBindings,$clock,$caps);
$accepted=new \Paymenter\Extensions\Servers\Domains\Services\RenewalAcceptance($binding,true,true,25,true);
$features=new \Paymenter\Extensions\Servers\Domains\Services\WriteFeatures(renewal:true);
$admission=new \Paymenter\Extensions\Servers\Domains\Services\RenewalAdmission($authority,$renewBindings,$accepted,$clock);
$engine=new \Paymenter\Extensions\Servers\Domains\Services\RenewalExecution($renewBindings,$authority,$admission,$accepted,$features,$clock);
$atBoundary=false;$sends=0;
$writer=new \Paymenter\Extensions\Servers\Domains\Services\RenewalWriter($binding,$features,$accepted,
    function()use($clock,$factsUntil,$engine,&$atBoundary){$atBoundary=true;$clock->instant=$factsUntil->modify('+1 second');return $engine;},
    static function()use(&$sends){$sends++;throw new RuntimeException('Synthetic exchange must remain unused');});
$engine->run($virgin->id,$writer);
same(true,$atBoundary,'Expiry regression reaches the final spending boundary after successful admission');
same(0,$sends,'Expired independent renewal facts authorize zero physical sends');
$nativeSuccessful=true;
echo "Offer frozen renewals: ok\n";
