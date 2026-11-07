<?php
// Queue serialization, real observer and the native page cache require one isolated application.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
ob_start();
require __DIR__.'/owner-records.php';
require_once __DIR__.'/staff-panel.php';
use Illuminate\Support\Facades\{Artisan,Auth,Cache,DB,Queue};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationSchema};
ObservationSchema::install();[$d,$driver]=$holding(placeholderComFixtureName('staff-sync-placeholder.com'));
staffPanel();
Filament\Facades\Filament::getCurrentPanel()->viteTheme([])->theme(new Illuminate\Support\HtmlString(''));
$actor=staffUser([Staff::VIEW,'admin.domains.manage']);Auth::setUser($actor);
App\Models\Server::findOrFail($d->server_id)->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>'1']);
$observer=new DomainObservation($readBindings,$clock,new AccountReadBudget($clock),$ports);
app()->instance(DomainObservation::class,$observer);
Queue::swap(Queue::getFacadeRoot()->queue);
config(['queue.default'=>'database','queue.connections.database.retry_after'=>360,'cache.default'=>'database']);
Illuminate\Support\Facades\Schema::create('cache',function($t){$t->string('key')->primary();$t->mediumText('value');$t->integer('expiration');});
Illuminate\Support\Facades\Schema::create('cache_locks',function($t){$t->string('key')->primary();$t->string('owner');$t->integer('expiration');});
Cache::flush();

// A persistent disposable native application handles the browser's actual Livewire requests.
ob_end_clean();
set_exception_handler(static function(Throwable $error):never{fwrite(STDERR,get_class($error).': '.substr($error->getMessage(),0,2000)."\n");exit(1);});
$broker=new Livewire\Features\SupportTesting\RequestBroker(app());
$reply=static function(array $value):void{echo json_encode($value,JSON_THROW_ON_ERROR)."\n";flush();};
while(($line=fgets(STDIN))!==false){
    $message=json_decode($line,true,512,JSON_THROW_ON_ERROR);
    if($message['type']==='start'){
        $page=Livewire\Livewire::actingAs($actor)->test(ViewDomain::class,['record'=>$d->id]);
        $html=Illuminate\Support\Facades\Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',['page'=>$page->instance(),'body'=>$page->html()]);
        $html=str_replace('</body>',Livewire\Mechanisms\FrontendAssets\FrontendAssets::scripts().'</body>',$html);
        $assets=[];
        foreach([...Filament\Support\Facades\FilamentAsset::getScripts(),...Filament\Support\Facades\FilamentAsset::getAlpineComponents()] as $asset){
            if($asset->getPath()!==null&&!$asset->isRemote()){$assets[parse_url($asset->getSrc(),PHP_URL_PATH)]=realpath($asset->getPath());}
        }
        $reply(['html'=>$html,'assets'=>$assets,'updatePath'=>parse_url(app('livewire')->getUpdateUri(),PHP_URL_PATH)]);
    }elseif($message['type']==='update'){
        $response=$broker->temporarilyDisableExceptionHandlingAndMiddleware(fn($b)=>$b->addHeaders(['X-Livewire'=>true])->call('POST',app('livewire')->getUpdateUri(),$message['payload']));
        same(200,$response->status(),'Browser Livewire request succeeds');
        app('livewire')->flushState();$reply($response->json());
    }elseif($message['type']==='finish'){
        same(1,DB::table('jobs')->count(),'The browser queued exactly one job');
        // Exercise the live failure: completion notice and Activity, while prior facts remain intact.
        $observation->fault='transport';
        Artisan::call('queue:work',['--stop-when-empty'=>true,'--sleep'=>0,'--tries'=>1]);
        same(0,DB::table('jobs')->count(),'Native worker finishes');
        same(0,DB::table('failed_jobs')->count(),'No failed queue job');
        same("Couldn't read all records",SyncDomainRecords::result($d)['title'],'Completion result is saved');
        $reply(['finished'=>true]);
    }else{throw new RuntimeException('Unknown browser test message');}
}
$nativeSuccessful=true;
