<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Staff Domains list: Auto-renew on every state, demo sync paused, fixed queries per page.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
require_once __DIR__.'/Support/accounts.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ListDomains;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
staffPanel();[$held,$active]=staffData();Paymenter\Extensions\Servers\Domains\Services\HoldingProvenanceSchema::install();Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
$viewer=staffUser([Staff::VIEW]);Auth::setUser($viewer);
$list=function(?callable $queries=null)use($viewer){
    DB::flushQueryLog();DB::enableQueryLog();$page=Livewire::actingAs($viewer)->test(ListDomains::class)->assertSuccessful();
    $log=DB::getQueryLog();DB::disableQueryLog();DB::flushQueryLog();
    $instance=$page->instance();return [$instance->getTable()->getColumns(),$instance->getTableRecords()->keyBy('id'),count($log),$log];
};

// Customer has its own column, and search finds a full name.
$owner=App\Models\User::create(['first_name'=>'Ada','last_name'=>'Lovelace','email'=>'ada@example.test','password'=>'synthetic-password']);DB::table('domains_domains')->where('id',$active->id)->update(['user_id'=>$owner->id]);same(true,isset($list()[0]['customer']),'Customer column');
Livewire::actingAs($viewer)->test(ListDomains::class)->assertSee('Search domain or customer')->searchTable($owner->first_name.' '.$owner->last_name)->assertCanSeeTableRecords([$active]);
Livewire::actingAs($viewer)->test(ListDomains::class)->searchTable('nobody-by-this-name')->assertCountTableRecords(0);
// Duplicate staff account names remain distinguishable in filters and rows.
App\Models\Server::whereIn('id',[$held->server_id,$active->server_id])->update(['name'=>'Shared account']);
$accountsPage=Livewire::actingAs($viewer)->test(ListDomains::class);
$options=$accountsPage->instance()->getTable()->getFilter('server_id')->getOptions();
foreach([$held,$active] as $domain){
    same('Shared account #'.$domain->server_id,$options[$domain->server_id],'Filter keeps account ID');
    $accountsPage->filterTable('server_id',$domain->server_id)->assertCanSeeTableRecords([$domain]);
    $other=$domain->id===$held->id?$active:$held;$accountsPage->assertCanNotSeeTableRecords([$other]);
    $record=$accountsPage->instance()->getTableRecords()->firstWhere('id',$domain->id);
    same('Shared account #'.$domain->server_id,$accountsPage->instance()->getTable()->getColumn('server_id')->record($record)->getState(),'Registrar row keeps account ID');
}
unset($accountsPage);

// Auto-renew applies to every domain state (status-model.md 3, rules 1 and 4): pending and under-review rows show On, Off or Missing, never blank.
$cases=['registration pending'=>['registration_pending','register','queued',null],'transfer pending'=>['transfer_pending','transfer','queued',null],
    'awaiting payment'=>['registration_pending','register','awaiting_payment',null],'held request'=>['transfer_pending','transfer','held','transfer_needs_action'],
    'uncertain request'=>['transfer_pending','transfer','uncertain',null],'under review'=>['needs_review','transfer','held','transfer_needs_action']];
foreach ($cases as $case=>[$status,$action,$state,$code]) {
    DB::table('domains_domains')->where('id',$held->id)->update(['status'=>$status,'provider_expires_at'=>null]);
    DB::table('domains_operations')->where('domain_id',$held->id)->update(['action'=>$action,'state'=>$state,'safe_code'=>$code]);
    [$columns,$records]=$list();$cell=$columns['auto_renew']->record($records[$held->id]);
    same(DomainFacts::for($held->fresh())['Renewal invoices']['value']??'Missing',$cell->getState(),'Auto-renew cell matches the domain page: '.$case);
    expect(in_array($cell->getState(),['On','Off','Missing'],true),'Auto-renew cell is not blank: '.$case);
}
// A pending import never bills renewals: the list says Off, Until import, like the domain page. By status or by provenance alone.
$saved=(array)DB::table('domains_domains')->where('id',$held->id)->first(['status','provenance','provider_expires_at']);
foreach (['status'=>['status'=>'adoption_pending'],'provenance'=>['provenance'=>'adoption_pending']] as $case=>$pending) {
    DB::table('domains_domains')->where('id',$held->id)->update([...$pending,'provider_expires_at'=>today()->addDays(200)->format('Y-m-d')]);
    [$columns,$records]=$list();$cell=$columns['auto_renew']->record($records[$held->id]);$page=DomainFacts::for($held->fresh())['Renewal invoices'];
    same(['Off','Until import'],[$page['value'],$page['sub']],'Domain page says Off until import: pending '.$case);
    same([$page['value'],$page['sub']],[$cell->getState(),$cell->getDescriptionBelow()],'List matches the domain page: pending '.$case);
    DB::table('domains_domains')->where('id',$held->id)->update($saved);
}
DB::table('domains_domains')->where('id',$held->id)->update(['status'=>'cancelled']);
[$columns,$records]=$list();same(null,$columns['auto_renew']->record($records[$held->id])->getState(),'Ended domains leave the cell blank');
DB::table('domains_domains')->where('id',$held->id)->update($saved);
DB::table('domains_domains')->where('id',$held->id)->update(['renewal_policy'=>'not-readable']);
[$columns,$records]=$list();$cell=$columns['auto_renew']->record($records[$held->id]);
same(['Missing',DomainFacts::UNREADABLE_RENEWAL],[$cell->getState(),$cell->getDescriptionBelow()],'Unreadable renewal settings show Missing with the cause');
DB::table('domains_domains')->where('id',$held->id)->update(['renewal_policy'=>Domain::find($active->id)->getRawOriginal('renewal_policy')]);

// One demo-paused definition: the list, the page and the customer view agree, and the list row says why sync stops.
$demo=Domain::create([...fixtureAccount((int)App\Models\Server::max('id')+1,'enom','demo-enom'),'domain'=>'demo-held.test','sld'=>'demo-held','tld'=>'test',
    'user_id'=>$viewer->id,'service_id'=>499,'status'=>'active','provider_expires_at'=>today()->addDays(200)->format('Y-m-d'),'snapshot'=>['offline_demo'=>true],
    'observation'=>['facts'=>[]],'renewal_policy'=>[]]);
foreach (['test'=>true,'live'=>false] as $environment=>$paused) {
    config(['domains.enom_identity.environment'=>$environment,'domains.enom_writes.registration'=>false,'domains.enom_writes.renewal'=>false]);
    [$columns,$records]=$list();
    same($paused?'Sync paused.':null,$columns['note']->record($records[$demo->id])->getState(),'Demo list row says sync is paused: '.$environment);
    same($paused?'Paused':null,DomainFacts::for($demo->fresh())['Last sync']['value']??null,'Staff page agrees: '.$environment);
    same($paused,DemoData::syncPaused($demo->fresh()),'Customer view agrees: '.$environment);
    same($paused?'paused':null,DomainFacts::sync($demo->fresh()),'Standalone sync lazily loads the valid demo account: '.$environment);
}
config(['domains.enom_identity.environment'=>'test']);
same(false,DemoData::syncPaused($active->fresh()),'A real account is never demo paused');

// The list query count does not grow with rows on the page.
[, $records,$before]=$list();$rows=count($records);
$clone=function(string $table,int $id,array $changes):int{$row=(array)DB::table($table)->where('id',$id)->first();unset($row['id']);return (int)DB::table($table)->insertGetId([...$row,...$changes]);};
foreach ([$held,$active,$demo] as $source) { for ($i=0;$i<4;$i++) {
    $name='extra'.$i.'-'.$source->domain;$id=$clone('domains_domains',$source->id,['domain'=>$name,'reservation_name'=>$source->reservation_name===null?null:$name,'sld'=>'extra'.$i.'-'.$source->sld,'service_id'=>$source->service_id+100*($i+1)]);
    foreach (DB::table('domains_operations')->where('domain_id',$source->id)->pluck('id') as $op) {
        $clone('domains_operations',(int)$op,['domain_id'=>$id,'service_id'=>$source->service_id+100*($i+1),'operation_key'=>'extra-'.$i.'-'.$op]);
    }
} }
[, $records,$after]=$list();
same($rows+12,count($records),'Twelve more rows on the same page');
same($before,$after,'No queries per additional row');
// A loaded missing or mismatched account stays missing without another query per row.
[, , ,$queries]=$list();$accountReads=static fn($log)=>count(array_filter($log,fn($q)=>str_contains($q['query'],'from "domains_accounts"')));
$beforeMissing=$accountReads($queries);$missing=[];
foreach(['absent','mismatched'] as $fault){for($i=0;$i<3;$i++){
    $name=$fault.$i.'.example.test';$changes=['domain'=>$name,'reservation_name'=>$name,'sld'=>$fault.$i,'tld'=>'example.test','service_id'=>6000+count($missing)];
    $changes[$fault==='absent'?'server_id':'account_fingerprint']=$fault==='absent'?999999:str_repeat('e',64);
    $id=$clone('domains_domains',$demo->id,$changes);$missing[]=$id;
    if($i===0){$row=Domain::findOrFail($id);DB::table('domains_observation_work')->insert(['domain_id'=>$id,'server_id'=>$row->server_id,
        'account_fingerprint'=>$row->account_fingerprint,'generation'=>$row->authority_generation,'phase'=>'holding','due_at'=>0,'safe_code'=>'read_failed']);}
}}
[$columns,$records,,$queries]=$list();same($rows+18,count($records),'Missing-account controls are on the same rendered page');
same($beforeMissing,$accountReads($queries),'Missing or mismatched accounts do not add row queries');
foreach($missing as $i=>$id){$row=$records[$id];
    same($i%3===0?'failed':null,DomainFacts::sync($row),'Direct callers still lazily read the identity and stopped state');
    same(DomainFacts::NO_ACCOUNT.($i%3===0?' Sync stopped. Ask staff who can resume sync.':''),$columns['note']->record($row)->getState(),'Missing accounts never falsely pause demo sync');
}
// A stopped sync names the control these staff have: Sync now for Manage staff, else Resume sync.
foreach([[['admin.domains.manage','admin.domains.operations.resume_observation'],'Use Sync now on the domain.'],[['admin.domains.operations.resume_observation'],'Use Resume sync on the domain.']] as [$rights,$step]){
    Auth::setUser(staffUser([Staff::VIEW,...$rights]));
    same(DomainFacts::NO_ACCOUNT.' Sync stopped. '.$step,$columns['note']->record($records[$missing[0]])->getState(),'The list Note names the step on the page: '.$step);
}
Auth::setUser($viewer);
echo "Staff Domains list: Auto-renew on every state, one demo-paused definition and a fixed query count passed.\n";$nativeSuccessful=true;
