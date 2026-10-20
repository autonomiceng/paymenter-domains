<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Service;
use Illuminate\Support\Facades\{DB,Event,Log};
/** Public capability checks only; native billing and unrelated work always continue. */
final class NativePaidHooks
{
    public static function boot(): void
    {
        if(class_exists(\App\Events\Invoice\Paid::class)){
            Event::listen(\App\Events\Invoice\Paid::class,[self::class,'paid']);
        }
    }
    public function paid(object $event): void
    {
        DB::afterCommit(static function()use($event):void {
            try{app(RuntimeWork::class)->invoice((int)$event->invoice->id);}
            catch(\Throwable $e){Log::warning('Domains paid wake deferred to scheduler.',['exception_class'=>$e::class]);}
        });
    }
    public static function serviceMissing(): array
    {
        if(!class_exists(Service::class)||!is_callable([Service::class,'getEventDispatcher'])){return ['Service model events'];}
        $dispatcher=Service::getEventDispatcher();$events=(new Service)->getObservableEvents();$missing=[];
        foreach(['saving','updating','updated']as$event){
            if(!in_array($event,$events,true)||!$dispatcher?->hasListeners('eloquent.'.$event.': '.Service::class)){$missing[]='Service '.$event.' model event';}
        }
        return $missing;
    }
    public static function notices(): array
    {
        $missing=self::serviceMissing();
        $dispatcher=Event::getFacadeRoot();
        $paid=class_exists(\App\Events\Invoice\Paid::class)&&is_callable([$dispatcher,'getRawListeners'])
            &&in_array([self::class,'paid'],$dispatcher->getRawListeners()[\App\Events\Invoice\Paid::class]??[],true);
        return ['Start after payment'=>$paid?'Available':'Paused · the invoice-paid listener is missing. Scheduled checks still pick up paid orders.',
            'Paymenter payment events'=>$missing?'Acquisition processing paused · missing '.implode(', ',$missing):'Available'];
    }
}
