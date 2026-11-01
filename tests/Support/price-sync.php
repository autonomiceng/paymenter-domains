<?php
// Offline queue boundary: the web action sends nothing; the worker records its result in the native inbox.
use Paymenter\Extensions\Servers\Domains\Jobs\SyncPrices;
function queuedPriceSync(callable $submit,string $title,?string $body=null)
{
    $bus=Illuminate\Support\Facades\Bus::getFacadeRoot();
    Illuminate\Support\Facades\Bus::fake([SyncPrices::class]);
    config(['queue.default'=>'database','queue.connections.database.driver'=>'database','queue.connections.database.retry_after'=>360]);
    $reads=count($GLOBALS['catalogueCalls']??[]);
    try{
        $page=$submit()->assertHasNoActionErrors()->assertNotified('Price sync queued');
        same($reads,count($GLOBALS['catalogueCalls']??[]),'Web sync queues without reading the registrar');
        $jobs=Illuminate\Support\Facades\Bus::dispatched(SyncPrices::class);same(1,$jobs->count(),'One queued price job');$job=$jobs->first();
        $job->handle();app(Illuminate\Bus\UniqueLock::class)->release($job);
        $notice=App\Models\Notification::where('user_id',$job->actor)->latest('id')->firstOrFail();
        same($title,$notice->title,'Worker reports the sync result');if($body!==null){same($body,$notice->body,'Worker reports the cause');}
        return $page->call('$refresh');
    }finally{Illuminate\Support\Facades\Bus::swap($bus);}
}
