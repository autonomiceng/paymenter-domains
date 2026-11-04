<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native rows are needed to distinguish retained contractual retail from obsolete price columns.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Capabilities, OfferState};
use Paymenter\Extensions\Servers\Domains\Models\Tld;
expect(is_file(dirname(__DIR__).'/Domains/Catalogue/Capabilities.php'), 'Independent current capability facts adapter required');
require __DIR__.'/Support/offer-commerce.php';
extract(offerCommerceFixture());
$allowed = true;
$caps = new Capabilities($ports, $clock, static function () use (&$allowed): void { if (!$allowed) { throw new DomainException('Revoked'); } });
same(null, Capabilities::routed(), 'Production remains legacy before complete checked composition');
same(['com'], $caps->endings($binding, 'transfer'), 'Capability endings come from current independent facts');
DB::table('domains_sales_policies')->where('tld', 'com')->update(['register_enabled'=>false, 'transfer_enabled'=>false]);
DB::table('domains_tlds')->where('id', $tlds[0]->id)->update(['catalogue_intent'=>OfferState::json(['sell'=>false]), 'catalogue_published_revision'=>null]);
$t = Tld::findOrFail($tlds[0]->id);
same([1,3], $caps->facts($binding, 'com')->terms['register'], 'Deselection and staff stops do not withdraw provider capabilities');
same('10.40', $caps->holder($binding, 'com')->retail, 'Holder retail survives public withdrawal');
same('8.0000', $caps->cost($binding, 'com')->total, 'Cost uses normalized observation');
DB::table('domains_prices')->where('server_id', $binding->serverId)->update(['wholesale'=>'999.0000', 'retail'=>'999.00']);
same('8.0000', $caps->cost($binding, 'com')->total, 'Obsolete wholesale does not affect current costs');
DB::transaction(function () use ($caps, $binding, $t): void {
    $t = Tld::whereKey($t->id)->lockForUpdate()->sole();
    $rows = Paymenter\Extensions\Servers\Domains\Models\Price::where('server_id', $binding->serverId)->where('tld','com')->lockForUpdate()->get();
    $snapshot = $caps->holding($binding, $t, $rows);
    $queries = []; DB::listen(static function ($q) use (&$queries): void { $queries[]=$q->sql; });
    same('10.40', $snapshot['retail']->retail, 'Locked retained retail uses prelocked snapshot');
    same('8.0000', $snapshot['cost']?->total, 'Locked current cost uses prelocked snapshot');
    same(true, $caps->facts($binding, 'com', $t)->renewalSafe, 'Locked capability uses prelocked TLD');
    same([], $queries, 'No catalogue lock acquisition after holding/operation prefix');
});
$op = new Paymenter\Extensions\Servers\Domains\Models\Operation;
$op->setRelation('domain', new Paymenter\Extensions\Servers\Domains\Models\Domain(['tld'=>'com']));
$accept = new Paymenter\Extensions\Servers\Domains\Services\WriteAcceptance($binding,true,true,25,null,null,$caps,catalogue: $GLOBALS['caps']??null);
$order = new Paymenter\Extensions\Servers\Domains\Data\Query('capability-placeholder.com',Paymenter\Extensions\Servers\Domains\Data\Action::Register,1);
same([1,3],$accept->requireCurrent($binding,$order,$op)->terms,'Execution uses independent provider terms while unsold');
$op->catalogueTld=Tld::findOrFail($t->id);
DB::transaction(fn()=>$accept->requireCurrent($binding,$order,$op,true));
$clock->instant = $now->modify('+2 days');
commerceReject(fn()=>$accept->requireCurrent($binding,$order,$op));
commerceReject(fn () => $caps->facts($binding, 'com'));
same(null, $caps->cost($binding, 'com'), 'Expired normalized cost is unknown');
same('10.40', $caps->holder($binding, 'com')->retail, 'Stale facts/cost retain annual retail');
DB::table('domains_tlds')->where('id', $t->id)->update(['catalogue_facts'=>null]);
same([], $caps->endings($binding), 'Legacy acceptance cannot manufacture missing provider facts');
expect(method_exists($caps, 'identities'), 'Retained Enom reads need identities independently of fresh spending capabilities');
same(['com'], $caps->identities($binding), 'Unknown facts preserve the bound converted suffix used to parse historical reads');
// The checklist describes acquisition decisions, while execution support survives a sales stop.
$clock->instant=$now;
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_facts'=>OfferState::encodeFacts(new \Paymenter\Extensions\Servers\Domains\Catalogue\Facts(
    ['register'=>[1],'renew'=>[1],'transfer'=>[1]],[],true,false,false,'synthetic',$now,$now->modify('+1 day')))]);
$q=new \Paymenter\Extensions\Servers\Domains\Catalogue\Query($binding,['net'],'register');
$revision=fn()=>$ports->readOffers($q,\Paymenter\Extensions\Servers\Domains\Catalogue\Context::Staff)[0]->revision;
$costs=[];
foreach(['register','renew','transfer'] as $action){$costs[]=new \Paymenter\Extensions\Servers\Domains\Catalogue\Cost($action,1,true,'8.0000',\Paymenter\Extensions\Servers\Domains\Data\CostUnit::TermTotal,'synthetic',$now,$now->modify('+1 day'));}
$ports->apply(new \Paymenter\Extensions\Servers\Domains\Catalogue\FeedObserved($binding,'net',$costs),$revision());
$ports->apply(new \Paymenter\Extensions\Servers\Domains\Catalogue\FactsObserved($binding,'net',new \Paymenter\Extensions\Servers\Domains\Catalogue\Facts(
    ['register'=>[1],'renew'=>[1],'transfer'=>[1]],[],true,false,false,'synthetic',$now,$now->modify('+1 day'))),$revision());
$ports->apply(\Paymenter\Extensions\Servers\Domains\Catalogue\Edit::override($binding,'net',new \Paymenter\Extensions\Servers\Domains\Catalogue\Intent(sell:true)),$revision());
$ports->apply(\Paymenter\Extensions\Servers\Domains\Catalogue\Edit::seller($binding,'net',['register']),$revision());
require __DIR__.'/Support/staff-tlds.php';tldPanel();
$actor=staffUser([\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW]);
\Illuminate\Support\Facades\Auth::setUser($actor);
$staff=new \Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue($ports,$clock);
$a=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$binding->serverId)->sole();
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
$rows=\Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::setup($a,['label'=>'Connected'],$staff);
$row=collect($rows)->firstWhere('label','What you sell');
same(\Paymenter\Extensions\Servers\Domains\Presentation\TldNames::summary(['net']),$row['value'],'What you sell excludes a stopped deselected ending beside an available sibling');
same(['com','net'],$caps->endings($binding),'Independent execution support still includes the stopped ending');
$allowed = false; commerceReject(fn () => $caps->holder($binding, 'com'));
commerceReject(fn () => $caps->identities($binding));
same(0, $reads->calls, 'Capabilities and holders never read a provider');
$nativeSuccessful = true;
echo "Offer capabilities: ok\n";
