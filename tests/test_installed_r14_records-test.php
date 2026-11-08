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
foreach(['Last sync','DNS records']as$label){$text=json_encode($facts[$label]);
 if(!str_contains($text,'The registrar read failed.')){$errors[]=$label.' does not explain the failed read';}
 if(str_contains($text,'Fills in by itself')||str_contains($text,'Use Sync now')){$errors[]=$label.' promises a day or asks for a manual sync';}}
$d->update(['provider_expires_at'=>null]);$facts=DomainFacts::for($d->fresh());
expect(!str_contains($facts['Contacts']['sub']??'','Next automatic retry:'),'Missing Contacts retain their own repair instruction');
expect(!str_contains($facts['Expires']['sub']??'','Next automatic retry:'),'Missing confirmed expiry does not promise an observation confirmation');
expect(str_contains($facts['Nameservers']['sub']??'','Next automatic retry:'),'Unread nameservers retain automatic read guidance');
expect($errors===[],implode('; ',$errors));
expect(str_contains($top,'The registrar read failed.')&&str_contains($top,'Next automatic retry:'),'Native top rows render the actual failed read and due time');
$nativeSuccessful=true;echo "Compact native staff Missing and consistent failed-read guidance passed.\n";
