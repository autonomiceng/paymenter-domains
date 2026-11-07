<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
// Real Blade/Filament markup. Optional artifacts also feed the offline browser witness.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/storefront.php';
require __DIR__.'/Support/staff-panel.php';
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
storefrontViews();staffPanel();[$held,$active]=staffData();
$actor=staffUser([Staff::VIEW]);Illuminate\Support\Facades\Auth::setUser($actor);
$history=Livewire\Livewire::actingAs($actor)->test(RequestsRelationManager::class,['ownerRecord'=>$active,'pageClass'=>ViewDomain::class])->html();
$families=['stale'=>false,'at'=>null,'failed_family'=>null,'last_complete_at'=>null,'caa'=>[], 'rows'=>[
    ['label'=>'DNS records','kind'=>'dns','state'=>'unknown','value'=>'Unknown','staff'=>'No current complete read. Sync this domain.','customer'=>'We’re checking these records.','last_good_at'=>null],
    ['label'=>'Contacts','kind'=>'contacts','state'=>'partial','value'=>'Partial','staff'=>'Registrant returned; some contacts did not. Refresh contacts before editing.','customer'=>'We’re checking the rest.','last_good_at'=>null],
    ['label'=>'Nameservers','kind'=>'nameservers','state'=>'complete','value'=>'Complete','staff'=>'2 nameservers checked.','customer'=>'2 nameservers checked.','last_good_at'=>null],
    ['label'=>'Glue records','kind'=>'glue','state'=>'unsupported','value'=>'Unsupported','staff'=>'No supported read contract.','customer'=>'These records cannot be read here.','last_good_at'=>null]]];
$staff=view('domains::partials.staff-styles')->render().'<section class="domains-sec">'.view('domains::partials.record-families',['recordFamilies'=>$families,'staff'=>true])->render().view('domains::admin.term-prices',['prices'=>[['term'=>1,'current'=>'10.99','cost'=>'8.9500','synced'=>true],['term'=>2,'current'=>null,'cost'=>null,'synced'=>false]]])->render().view('domains::admin.monitoring-evidence',['evidence'=>['state'=>'Unknown','separation'=>'Public DNS cannot confirm a registrar change.','public'=>['A: 192.0.2.10'],'registrar'=>[],'public_at'=>null,'registrar_at'=>null,'at'=>null]])->render().'</section><section class="domains-history-fixture">'.$history.'</section>';
$section=['inventory'=>[['name'=>'@','type'=>'A','address'=>'192.0.2.10','ttl'=>3600],['name'=>'www','type'=>'CNAME','address'=>'hosting.example.com','ttl'=>300]],'caa'=>[['name'=>'@','value'=>'0 issue "ca.example"','ttl'=>3600]],'actions'=>[]];
$customer='<div class="domains">'.view('domains::partials.styles')->render().view('domains::partials.dns-inventory',compact('section'))->render().'</div>';
$tokens='';foreach(\Filament\Support\Facades\FilamentColor::getColors() as $name=>$palette){foreach($palette as $shade=>$color){$tokens.='--'.$name.'-'.$shade.':'.$color.';';}}
$staff='<style>:root{'.$tokens.'}</style>'.$staff;
$dir=storage_path('framework/r10-layout');mkdir($dir,0700,true);
foreach(compact('staff','customer') as $name=>$html){file_put_contents($dir.'/'.$name.'.html','<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>*{box-sizing:border-box}body{margin:0;padding:24px;font:14px/20px sans-serif;background:#0b1229;color:#dce5f3}table{border-collapse:collapse}th,td{padding:0}dl,dd{margin:0}:root{--gray-200:#ddd;--gray-700:#334155;--primary-500:#00c2ff}.domains-history-fixture{width:600px}.fi-ta-table{width:100%;table-layout:fixed}.fi-ta-cell{overflow:hidden}.fi-ta-text{padding:8px}</style>'.$html);}
// The native render keeps labels for stacked DNS cells and does not attach the
// staff single-line truncation class to History changes. Browser QA consumes these
// same optional artifacts, outside the PHP fixture process.
$failures=[];
$check=static function(bool $ok,string $message)use(&$failures):void{if(!$ok){$failures[]=$message;}};
$dom=storefrontDom($customer);
$check($dom->query('//table[contains(@class,"domains-stackable")]')->length===1,'DNS inventory uses the shared mobile card layout');
$check($dom->query('//tbody/tr/td[@data-label="Value"]')->length===3,'Every DNS record value carries its mobile label');
$dom=storefrontDom($history);
$check($dom->query('//*[contains(@class,"domains-cut") and contains(.,"Request #")]')->length===0,'History Change is not assigned single-line truncation');
$style=view('domains::partials.staff-styles')->render();
$check(str_contains($style,'.domains-tbl td{')&&str_contains($style,'.domains-sec .domains-card>div{'),'Shared staff facts and tables have layout rules');
$dom=storefrontDom($staff);
$check($dom->query('//*[@data-record-family="contacts"]/span[contains(@class,"domains-tone-warn")]')->length===1,'Staff Partial uses the attention mark');
$check($dom->query('//*[@data-record-family="nameservers"]/span[contains(@class,"domains-tone-ok")]')->length===1,'Staff Complete uses the healthy mark');
$check($dom->query('//*[@data-record-family="glue"]/span[contains(@class,"domains-tone-muted")]')->length===1,'Unsupported retains the quiet state');
$check($dom->query('//*[@data-record-family="dns"]/span')->length===0,'Unknown remains unmarked');
// Browser QA checks the rendered pseudo-elements and resolved native/skin tokens.
$check(str_contains($style,'.domains-status::before')&&str_contains($style,'mask:'),'Staff family statuses render a shaped mark');
foreach(['ok','info','warn','bad','muted'] as $tone){$check(str_contains($style,'.domains-status.domains-tone-'.$tone),'Staff supports the '.$tone.' status tone');}
if(isset($argv[1])){expect(is_dir($argv[1]),'Artifact directory must exist');foreach(['staff','customer'] as $name){copy($dir.'/'.$name.'.html',$argv[1].'/'.$name.'.html');}}
expect($failures===[],implode('; ',$failures));
$nativeSuccessful=true;echo "Demo C R10 native layouts passed.\n";
