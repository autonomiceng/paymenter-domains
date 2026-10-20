<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Models\Service;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\{Cancel,Halt};
use Livewire\Livewire;

/** UI-only adapter at an already-refused write; other writers keep their original exception. */
final class NativeServiceRefusal
{
    public static function notify(Service $record,string $operation,string $reason='identity'): void
    {
        if(self::missing()!==null||!(($page=Livewire::current()) instanceof EditService)||!$page->getRecord()->is($record)){return;}
        $action=$page->getMountedAction();
        if(($operation==='delete'&&$action?->getName()!=='delete')||($operation==='save'&&$action!==null)
            ||!in_array($operation,['save','delete'],true)){return;}
        logger()->warning('Managed domain native service change refused.',['service_id'=>$record->getRawOriginal('id'),'operation'=>$operation,'reason'=>$reason]);
        [$title,$message]=match(true){
            $operation==='delete'=>['Service could not be deleted','Managed domain services are retained. To stop renewal or retire an unpaid order, use the Domains page.'],
            $reason==='price'=>['Price change refused','Domain prices cannot be changed here. For a confirmed active domain, use Update renewal price on the Domains page. This service was not saved.'],
            default=>['Service change refused','Product, plan, user, quantity, currency and coupon are fixed for managed domains. This service was not saved.'],
        };
        Notification::make()->danger()->title($title)->body($message)->send();
        if($operation==='delete'){throw (new Cancel)->rollBackDatabaseTransaction();}
        throw new Halt;
    }
    public static function missing(): ?string
    {
        foreach([\Livewire\LivewireManager::class=>'current',EditService::class=>'getMountedAction',
            Notification::class=>'make'] as $class=>$method){if(!class_exists($class)||!method_exists($class,$method)){return $class.'::'.$method;}}
        foreach([Halt::class,Cancel::class] as $class){if(!class_exists($class)){return $class;}}
        return null;
    }
}
