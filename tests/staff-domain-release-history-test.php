<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native staff domain page and action boundaries.
declare(strict_types=1);
require __DIR__.'/Support/staff-domain-edits.php';
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainEditActions,DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainManagement,DomainObservation,ManagementForms,ObservationSchema,OwnedDomains,OwnerPorts};
use Paymenter\Extensions\Servers\Domains\Services\{StaffOperationReview,StaffOperations};
$viewer=staffUser([Staff::VIEW]);
// Real paid requests and Livewire Release exercise the saved audit and the domain rail together.
// A unit row fixture cannot prove that the real action records the actor and time the page reads.
$releaseStaff=staffUser([Staff::VIEW,'admin.domains.manage',StaffOperationReview::permission('hold'),StaffOperationReview::permission('release')]);
$releaseStaff->update(['first_name'=>'Alex','last_name'=>'Admin']);
$releaseOps=[$new(placeholderComFixtureName('rail-release-placeholder.com'),action:'transfer'),$new(placeholderComFixtureName('rail-held-placeholder.com'),action:'transfer')];
$reviews=new StaffOperations($clock);
foreach ($releaseOps as $request) {
    $key=StaffOperationReview::ticket($releaseStaff->id,$request->domain_id,$request->id,'hold',StaffOperationReview::stamp($request,$request->domain),$clock);
    $reviews->run($releaseStaff,$request->domain_id,$request->id,'hold',$key,'Put on hold');
}
$clock->instant=$clock->now()->modify('+5 minutes');
DB::table('domains_accounts')->where('server_id',$releaseOps[0]->server_id)->update(['processing_enabled'=>false]);
$before=$nativeMoney();
Livewire::actingAs($releaseStaff)->test(ViewOperation::class,['record'=>$releaseOps[0]->id])->assertActionEnabled('release')
    ->callAction('release')->assertHasNoActionErrors()->assertNotified('Hold released');
same('queued',$releaseOps[0]->fresh()->state,'The real Release queues the request while Orders is off');
same($before,$nativeMoney(),'Paused release preserves money');
foreach ($releaseOps as $i=>$request) {
    $html=Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$request->domain_id])->assertSuccessful()->html();
    $dom=new DOMDocument;@$dom->loadHTML($html);$xpath=new DOMXPath($dom);
    $rail='//*[contains(concat(" ",normalize-space(@class)," ")," domains-rail ")]';
    $entries=$xpath->query($rail.'//*[contains(concat(" ",normalize-space(@class)," ")," domains-trace-item ")][contains(.,"Hold released")]');
    same($i===0?1:0,$entries->length,'Only the released domain rail has a staff release entry');
    if ($i===0) {
        expect(str_contains($entries->item(0)->textContent,'Alex Admin'),'The rail names the staff member who released the hold');
        same($clock->now()->format(DATE_ATOM),$xpath->query('.//time',$entries->item(0))->item(0)?->getAttribute('datetime'),'The release row uses the action time');
    }
    $railText=preg_replace('/\s+/',' ',$xpath->query($rail)->item(0)->textContent);
    expect(str_contains($railText,'Request #'.$request->id.' Transfer '.($i===0?'Under review':'On hold')),'Only the retained staff hold reads On hold');
    // The header names the released request's Orders-off reason; its rail entry does not say it again.
    if ($i===0) { same(1,substr_count(preg_replace('/\s+/',' ',$dom->documentElement->textContent),'Not sent. Orders are off for this account.'),'The released request keeps its Orders-off reason, said once');
        expect(!str_contains($railText,'Not sent. Orders are off for this account.'),'The rail does not repeat the header'); }
}
// The History tab follows the same rule: the request the head names keeps its status there, but not the reason the head already says.
$reason='Not sent. Orders are off for this account.';$released=$releaseOps[0];
// The tab is mounted with the properties the domain page gives it, as Filament does inside the page.
$rowsIn=static function(string $html):array{
    $dom=new DOMDocument;@$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    $x=new DOMXPath($dom);$text=static fn(DOMNode $n)=>trim(preg_replace('/\s+/',' ',$n->textContent));
    return array_map(fn(DOMNode $row)=>array_map($text,iterator_to_array($x->query('./td',$row))),iterator_to_array($x->query('//tbody/tr')));};
$historyRows=static function(int $domain)use($rowsIn):array{
    $managers=Livewire::actingAs(Auth::user())->test(ViewDomain::class,['record'=>$domain])->assertSuccessful()->instance()->getRelationManagers();
    $config=array_values(array_filter($managers,fn($m)=>$m instanceof \Filament\Resources\RelationManagers\RelationManagerConfiguration&&$m->relationManager===RequestsRelationManager::class));
    same(1,count($config),'The domain page configures its History tab');
    return $rowsIn(Livewire::actingAs(Auth::user())->test(RequestsRelationManager::class,['ownerRecord'=>\Paymenter\Extensions\Servers\Domains\Models\Domain::findOrFail($domain),'pageClass'=>ViewDomain::class,...$config[0]->getProperties()])->assertSuccessful()->html());};
// A request's own row has the request in its Change cell; hold and release rows name it only in Detail.
$rowOf=static fn(array $rows,int $request)=>array_values(array_map(fn(array $cells)=>implode(' ',$cells),array_filter($rows,fn(array $cells)=>preg_grep('/\ARequest #'.$request.' [^.]+\z/',$cells)!==[])));
Auth::setUser($viewer);
$releasedRows=$historyRows($released->domain_id);$releasedRow=$rowOf($releasedRows,$released->id);same(1,count($releasedRow),'History lists the released request once: '.json_encode($releasedRows));
expect(str_contains($releasedRow[0],'Under review'),'History keeps the released request status: '.$releasedRow[0]);
expect(!str_contains($releasedRow[0],$reason),'History does not repeat the head reason: '.$releasedRow[0]);
$heldRow=$rowOf($historyRows($releaseOps[1]->domain_id),$releaseOps[1]->id);same(1,count($heldRow),'History lists the held request once');
expect(str_contains($heldRow[0],'On hold'),'The retained hold keeps its status in History: '.$heldRow[0]);
// Another request with the same words is not the one the head names, so it keeps them.
$other=$released->domain->operations()->create(['server_id'=>$released->server_id,'registrar_key'=>$released->registrar_key,'account_fingerprint'=>$released->account_fingerprint,
    'service_id'=>$released->service_id,'authority_generation'=>$released->authority_generation,'operation_key'=>'history-other-queued','action'=>'contacts','state'=>'queued',
    'safe_code'=>$released->safe_code,'currency'=>'USD','amount'=>'0.00','attempt'=>[],'funding'=>[],'cost'=>[]]);
$page=Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$released->domain_id])->assertSuccessful()->html();
expect(str_contains($page,$reason),'The head still names the released request reason');
$rows=$historyRows($released->domain_id);
expect(!str_contains($rowOf($rows,$released->id)[0],$reason),'History still leaves out the head reason for the request it names');
expect(str_contains($rowOf($rows,$other->id)[0]??'',$reason),'Another request keeps its own reason in History: '.($rowOf($rows,$other->id)[0]??'missing'));
DB::table('domains_operations')->where('id',$other->id)->delete();
// The page keeps History mounted while the head polls. The browser sends the page poll and the mounted History tab in one
// update request, so History must follow a head that changed after it was mounted.
$orders=static fn(bool $on)=>DB::table('domains_accounts')->where('server_id',$released->server_id)->update(['processing_enabled'=>$on]);
$orders(true);
// Staff open the History tab, which mounts it inside the page.
$page=Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$released->domain_id])->assertSuccessful()->set('activeRelationManager','0');$mounted=$page->html();
expect(!str_contains($mounted,$reason),'With Orders on, the head does not say the Orders-off reason');
preg_match_all('/wire:snapshot="([^"]*)"/',$mounted,$found);$tabSnapshot=null;
foreach ($found[1] as $raw) { $snapshot=json_decode(html_entity_decode($raw,ENT_QUOTES|ENT_HTML5),true);if (str_ends_with($snapshot['memo']['name'],'requests-relation-manager')) { $tabSnapshot=$snapshot; } }
expect($tabSnapshot!==null,'The page renders with its History tab mounted');$pageSnapshot=$page->snapshot;
$orders(false);
app('livewire')->flushState();
[,$pageEffects]=app('livewire')->update($pageSnapshot,[],[['path'=>'','method'=>'pollManagement','params'=>[]]]);
[$tabSnapshot,$tabEffects]=app('livewire')->update($tabSnapshot,[],[]);
expect(str_contains($pageEffects['html']??'',$reason),'After the poll the head says the Orders-off reason');
$polledRow=$rowOf($rowsIn($tabEffects['html']??''),$released->id);same(1,count($polledRow),'The mounted History tab lists the released request once');
expect(str_contains($polledRow[0],'Under review')&&!str_contains($polledRow[0],$reason),'The mounted History tab follows the new head: '.$polledRow[0]);
// A later History-only request, such as a search or a page change, keeps the head the poll passed.
app('livewire')->flushState();
[,$tabEffects]=app('livewire')->update($tabSnapshot,[],[['path'=>'','method'=>'$refresh','params'=>[]]]);
$laterRow=$rowOf($rowsIn($tabEffects['html']??''),$released->id);
expect(count($laterRow)===1&&!str_contains($laterRow[0],$reason),'A History-only request keeps the current head: '.($laterRow[0]??'missing'));
// With Orders off in the head, a Registrar alert keeps its whole cause and the row drops only the paused words.
\Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts::record($released->domain->fresh(),'renewal_funds',time());
$cause=DomainFacts::alertCause('renewal_funds');
$dom=new DOMDocument;@$dom->loadHTML('<?xml encoding="utf-8" ?>'.Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$released->domain_id])->assertSuccessful()->html());
$registrar=(new DOMXPath($dom))->query('//*[contains(concat(" ",normalize-space(@class)," ")," domains-row ")][.//*[normalize-space(.)="Registrar"]]');
same(1,$registrar->length,'One rendered Registrar row');$registrarText=trim(preg_replace('/\s+/',' ',$registrar->item(0)->textContent));
same(1,substr_count($registrarText,$cause),'The Registrar row says the whole alert cause once: '.$registrarText);
expect(!str_contains(strtolower($registrarText),'orders paused'),'The Registrar row leaves Orders off to the head: '.$registrarText);
expect(str_contains(preg_replace('/\s+/',' ',$dom->documentElement->textContent),$reason),'The head still says Orders are off');
DB::table('domains_obligation_alerts')->where('domain_id',$released->domain_id)->delete();
echo "Staff release history passed.\n";$nativeSuccessful=true;
