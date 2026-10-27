<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\Product;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld;
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\{HookRegistration,NativeIdentity,StorefrontCatalogueRead};
/** Persistent consequences before and after edits, including Paymenter's native plan repeater. */
final class PricingEditorNotice
{
    public static function boot(): void
    {
        if(($missing=self::missing())!==null){logger()->warning('Domain pricing notice paused: '.$missing);return;}
        if(!HookRegistration::claim(FilamentView::getFacadeRoot(),self::class)){return;}
        $scopes=[ViewTld::class,RegistrarAccount::class,\App\Admin\Resources\ProductResource\Pages\EditProduct::class];
        FilamentView::registerRenderHook(PanelsRenderHook::PAGE_START,static function():string {
            $page=\Livewire\Livewire::current();
            if($page instanceof RegistrarAccount){return self::render(null);}
            if($page instanceof ViewTld||$page instanceof \App\Admin\Resources\ProductResource\Pages\EditProduct){return self::render($page->getRecord());}
            return '';
        },scopes:$scopes);
    }
    public static function missing(): ?string
    {
        foreach([\Filament\Support\View\ViewManager::class=>'registerRenderHook',\Livewire\LivewireManager::class=>'current',
            \App\Admin\Resources\ProductResource\Pages\EditProduct::class=>'getRecord',ViewTld::class=>'getRecord']as$class=>$method){
            if(!class_exists($class)||!method_exists($class,$method)){return $class.'::'.$method;}
        }
        return defined(PanelsRenderHook::class.'::PAGE_START')?null:PanelsRenderHook::class.'::PAGE_START';
    }
    public static function render(mixed $record): string
    {
        $native=$record instanceof Product;
        if($native&&!NativeIdentity::productRows(NativeIdentity::rows($record))){return '';}
        if(!$native&&$record!==null&&!$record instanceof Tld){return '';}
        $tld=$record instanceof Tld?$record->fresh():null;
        if($native){
            $id=$record->settings()->where('key','domains_tld_id')->value('value');
            $tld=Tld::where('server_id',$record->server_id)->find($id);
        }
        $status=[];
        if($tld){
            // Never use the 30-second customer display cache after a native price save.
            $read=app(StorefrontCatalogueRead::class);
            foreach(['register'=>'Registration','transfer'=>'Transfer'] as $action=>$label){
                $available=false;
                foreach($action==='register'?range(1,10):[1] as $term){
                    try{$read->offer($tld->tld,$action,$term);$available=true;break;}
                    catch(\Throwable){ /* Same fail-closed offer rules as the public catalogue. */ }
                }
                $status[$label]=$available;
            }
        }
        return view('domains::admin.pricing-editor-notice',compact('native','status'))->render();
    }
}
