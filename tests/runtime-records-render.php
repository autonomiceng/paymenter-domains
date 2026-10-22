<?php
// Installed panel and actual observer/owner projections. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/runtime-records.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Auth,Blade};
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{DomainObservation,ObservationRecords};
use Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain;
$out=$argv[1]??'';$shell=file_get_contents($argv[2]??'');expect(is_dir($out),'Output directory required');
$registerRecords();$state->dns=array_map(fn($i)=>['name'=>'record'.$i,'type'=>'TXT','address'=>' exact TXT value '.$i.' ','mx_pref'=>10],range(12,1));
$state->srv=array_map(fn($i)=>['service'=>'sip'.$i,'protocol'=>'tcp','priority'=>0,'weight'=>5,'port'=>443,'target'=>'sip.example.com.'],range(12,1));
app(\Paymenter\Extensions\Servers\Domains\Services\ObservationWork::class)->enroll($d->server_id);$dueRecords();$runtimePorts->initialManagedRecordObservations();
same('complete',ObservationRecords::current($d->fresh())['families']['dns']['state'],'Actual installed scheduled record producer');
$emit=static function(string $stateName)use($out,$shell,$owners,&$d):void {
    $dto=$owners->present($d->fresh());storefrontViews();app('view')->getFinder()->flush();
    foreach(['dns','srv']as$kind){
        $copy=new OwnedDomain(...[...get_object_vars($dto),'management'=>[$kind=>$dto->management[$kind]]]);
        $html=view('domains::show',['domain'=>$copy,'keys'=>array_fill_keys(\Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController::ACTIONS,str_repeat('a',64))])->render();
        preg_match('#<body>(.*)</body>#s',$html,$body);$content=$body[1]??$html;
        file_put_contents($out.'/customer-'.$kind.'-'.$stateName.'.html',str_replace('@@SLOT@@',$content,$shell));
    }
};
$emit('complete');
$p=$d->fresh()->observation;unset($p['records']);$d->fresh()->update(['observation'=>$p]);$emit('unknown');
ObservationRecords::hold($d->fresh(),'records_unavailable',$clock->now());$emit('held');
$staff=staffUser(array_keys(Staff::permissions()));Auth::setUser($staff);
$css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
Filament::getCurrentPanel()->viteTheme([])->theme(new HtmlString('<link rel="stylesheet" href="fixture.css">'))->brandName('Paymenter');
$staffPage=static function(string $name)use($staff,$out,$css,&$d):void {
$test=Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id])->assertSee($name==='staff-scheduled-stop'?'Scheduled reads stopped':'Record checks paused');
if ($name==='staff-scheduled-stop') { $test->assertSee('1 check'); }
$html=Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',['page'=>$test->instance(),'body'=>$test->html()]);
$html=preg_replace('~<script\b[^>]*>.*?</script>~si','',$html);$html=preg_replace('~<link\b[^>]*>~si','',$html);
$html=str_replace('x-cloak="-lg"','',$html);$html=str_replace('class="fi-main-ctn"','style="display:flex;opacity:1" class="fi-main-ctn"',$html);
$html=preg_replace('~src="https?://[^\"]+"~','src="data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#64748b"/></svg>').'"',$html);
$html=str_replace('</head>','<style>'.$css.'</style>'.\Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles().'</head>',$html);
file_put_contents($out.'/'.$name.'.html',$html);
};
$staffPage('staff-record-hold');
$p=$d->fresh()->observation;unset($p['record_hold']);$d->fresh()->update(['observation'=>$p]);
$bindings->setProcessing($d->server_id,false);$observation->fault='info_missing';$dueRecords();$runtimePorts->initialManagedRecordObservations();
$bindings->setProcessing($d->server_id,true,static fn()=>null);
same(1,(int)Illuminate\Support\Facades\DB::table('domains_observation_work')->where('domain_id',$d->id)->value('tries'),'Stopped fixture retains one actual attempted check');
$emit('stopped');$staffPage('staff-scheduled-stop');
$strings=[];foreach(glob($out.'/*.html')as$file){$strings[basename($file)]=trim(preg_replace('/\s+/u',' ',strip_tags(preg_replace('~<(style|script)\b[^>]*>.*?</\1>~si','',file_get_contents($file)))));}
file_put_contents($out.'/strings.json',json_encode($strings,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo "Installed staff record hold, 12-row numeric customer DNS/SRV and unknown notes rendered.\n";$nativeSuccessful=true;
