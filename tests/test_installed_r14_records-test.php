<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Services\{Installation,ObservationRecords};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,DomainFacts};
use Paymenter\Extensions\Servers\Domains\Presentation\RecordFamilies;
(new Installation)->install();staffPanel();[, $d]=staffData(ordinaryCompleted:true);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$now=new DateTimeImmutable('2026-11-05T18:00:00Z');
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new class($now) implements \Paymenter\Extensions\Servers\Domains\Contracts\Clock{public function __construct(private DateTimeImmutable $now){}public function now():DateTimeImmutable{return $this->now;}});
// Synthetic retained failure receipt, with no provider results. Native encrypted casts,
// persisted work and actual staff renderers are needed to reproduce the affected rows.
$d->update(['observation'=>['record_failure'=>['identity'=>ObservationRecords::identity($d),'at'=>$now->format(DATE_ATOM)],'read_retry'=>['at'=>$now->format(DATE_ATOM)]],'last_synced_at'=>null]);
DB::table('domains_observation_work')->insert(['domain_id'=>$d->id,'server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint,'generation'=>$d->authority_generation,'phase'=>'holding','due_at'=>$now->getTimestamp()+60,'tries'=>2,'safe_code'=>'read_failed']);
$d->server->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['type'=>'boolean','value'=>true]);
$facts=DomainFacts::for($d->fresh());$recordFamilies=RecordFamilies::read($d->fresh(),$now);
$body=view('domains::partials.record-families',compact('recordFamilies')+['staff'=>true])->render();
$nativePage=new \Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
$row=new ReflectionMethod($nativePage,'row');$rows=[];
foreach(['Last sync','DNS records']as$label){$rows[]=$row->invoke($nativePage,$label,$facts[$label],[]);}
$top=\Filament\Schemas\Schema::make($nativePage)->components($rows)->toHtml();
writeRenderArtifact((getenv('R14_RENDER_DIR')?:sys_get_temp_dir()).'/r14-records.html','<!doctype html><html class="dark"><head><meta charset="utf-8"><style>'.file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css').'</style>'.view('domains::partials.staff-styles')->render().'</head><body><main class="domains-sec">'.$top.$body.'</main></body></html>');
$dom=new DOMDocument;@$dom->loadHTML($body);$xp=new DOMXPath($dom);$errors=[];
foreach($xp->query('//span[@data-record-family]//svg')as$icon){if((int)$icon->getAttribute('width')<1||(int)$icon->getAttribute('width')>20||(int)$icon->getAttribute('height')<1||(int)$icon->getAttribute('height')>20){$errors[]='Missing icon has no compact intrinsic size';break;}}
// ui-r15 R4: the reason shows once, on Last sync; DNS records keeps a plain red Missing.
same(null,$facts['DNS records']['sub'],'DNS records does not repeat the failed read');
foreach(['Last sync','DNS records']as$label){$text=json_encode($facts[$label]);
 if($label==='Last sync'&&!str_contains($text,'The registrar read failed.')){$errors[]=$label.' does not explain the failed read';}
 if(str_contains($text,'Fills in by itself')||str_contains($text,'Use Sync now')){$errors[]=$label.' promises a day or asks for a manual sync';}}
expect($errors===[],implode('; ',$errors));
expect(str_contains($top,'The registrar read failed.')&&str_contains($top,'Next automatic retry:'),'Native top rows render the actual failed read and due time');
// ui-r15 R4: a registrar read can return contacts while the domain has none saved here (an import). The moved Contacts row
// shows the read state above its own red Missing, as it did before it moved onto the family row.
$known=['state'=>'complete','records'=>[]];
$d->update(['observation'=>['records'=>['identity'=>ObservationRecords::identity($d->fresh()),'at'=>$now->format(DATE_ATOM),'sequence'=>0,'format'=>1,'capability_revision'=>$d->registrar_key.'-records-1',
    'expected_families'=>array_fill_keys(['dns','srv','ds','glue'],'supported'),'families'=>array_fill_keys(['dns','srv','ds','glue'],$known),
    'settings'=>['nameservers'=>['state'=>'complete','facts'=>['nameservers'=>['ns1.example.net','ns2.example.net']]],'contacts'=>['state'=>'complete','facts'=>[]],'flags'=>['state'=>'complete','facts'=>[]]]]]]);
$contactsFamily=array_column(RecordFamilies::read($d->fresh(),$now)['rows'],null,'kind')['contacts'];$contactsFact=DomainFacts::for($d->fresh())['Contacts'];
same([null,'complete'],[$contactsFact['value'],$contactsFamily['state']],'Fixture: contacts read complete, none saved');
$familyRow=new ReflectionMethod($nativePage,'familyRow');
$contactsHtml=\Filament\Schemas\Schema::make($nativePage)->components([$familyRow->invoke($nativePage,$contactsFamily,$contactsFact,[])])->toHtml();
$contactsDom=new DOMDocument;@$contactsDom->loadHTML($contactsHtml);$contactsXp=new DOMXPath($contactsDom);
same(1,$contactsXp->query('//span[@data-record-family="contacts"]//*[normalize-space(.)="Complete"]')->length,'The read state stays above the row');
same(1,$contactsXp->query('//div[contains(@class,"fi-color-danger")][normalize-space(.)="Missing"]')->length,'No saved contacts stays red Missing under the read state');
expect(str_contains($contactsHtml,DomainFacts::ADD_CONTACTS),'Missing contacts keep their own step during a failed read');
// ui-r15 R5: once the read stopped, a sync won't help. DS is a plain red Missing, and partial families ask for no refresh.
DB::table('domains_observation_work')->where('domain_id',$d->id)->update(['safe_code'=>'read_unavailable','tries'=>5,'due_at'=>0]);
$s=$d->fresh()->observation;$s['records']['families']['ds']=['state'=>'unknown','records'=>[]];$s['records']['families']['glue']=['state'=>'partial','records'=>[]];$d->update(['observation'=>$s]);
same('Read stopped',DomainFacts::for($d->fresh())['Last sync']['value'],'Fixture: the read stopped');
$ds=\Paymenter\Extensions\Servers\Domains\Presentation\DsState::staff($d->fresh());same([null,null],[$ds['value'],$ds['sub']],'Stopped read: DS is plain Missing');
$dsHtml=\Filament\Schemas\Schema::make($nativePage)->components([$familyRow->invoke($nativePage,array_column(RecordFamilies::read($d->fresh(),$now)['rows'],null,'kind')['ds'],$ds,[])])->toHtml();
expect(!str_contains($dsHtml,'Sync this domain')&&str_contains($dsHtml,'fi-color-danger'),'Stopped read: the DS row is red and asks for no sync');
$glue=array_column(RecordFamilies::read($d->fresh(),$now)['rows'],null,'kind')['glue'];
expect($glue['state']==='partial'&&!str_contains($glue['staff'],'before editing'),'Stopped read: a partial family asks for no refresh');
$nativeSuccessful=true;echo "Compact native staff Missing and consistent failed-read guidance passed.\n";
