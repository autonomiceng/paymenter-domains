<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\Service;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\{HookRegistration,NativeIdentity};
/** Public, page-scoped render hook. Native controls and routes are untouched. */
final class NativeEditorNotice
{
    public static function boot(): void
    {
        if(($missing=self::missing())!==null){logger()->warning('Domain editor notice paused: '.$missing);return;}
        if(!HookRegistration::claim(FilamentView::getFacadeRoot(),self::class)){return;}
        $scopes=[\App\Admin\Resources\ServiceResource\Pages\EditService::class];
        $view='App\\Admin\\Resources\\ServiceResource\\Pages\\ViewService';if(class_exists($view)){$scopes[]=$view;}
        FilamentView::registerRenderHook(PanelsRenderHook::PAGE_START,static function()use($scopes):string {
            $page=\Livewire\Livewire::current();
            if(!$page||!array_filter($scopes,fn($class)=>$page instanceof $class)){return '';}
            return self::render($page->getRecord());
        },scopes:$scopes);
    }
    public static function missing(): ?string
    {
        foreach([\Filament\Support\View\ViewManager::class=>'registerRenderHook',\Livewire\LivewireManager::class=>'current',
            \App\Admin\Resources\ServiceResource\Pages\EditService::class=>'getRecord']as$class=>$method){
            if(!class_exists($class)||!method_exists($class,$method)){return $class.'::'.$method;}
        }
        return defined(PanelsRenderHook::class.'::PAGE_START')?null:PanelsRenderHook::class.'::PAGE_START';
    }
    public static function render(mixed $record): string
    {
        if(!$record instanceof Service||!NativeIdentity::routed($record)){return '';}
        $d=Domain::where('service_id',$record->getRawOriginal('id')??$record->id)->first();
        $url=$d&&Staff::can()?DomainResource::getUrl('view',['record'=>$d->id]):DomainResource::getUrl('index');
        return view('domains::admin.native-editor-notice',['url'=>$url])->render();
    }
}
