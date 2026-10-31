<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\Product;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld;
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\{HookRegistration,NativeIdentity,StorefrontCatalogueRead};
/** Persistent consequences before and after edits, including Paymenter's native plan repeater. */
final class PricingEditorNotice
{
    private const SCOPES=[ViewTld::class=>'getRecord',\App\Admin\Resources\ProductResource\Pages\EditProduct::class=>'getRecord'];
    public static function boot(): void
    {
        $paused=self::missingScopes();$scopes=[];
        foreach(self::SCOPES as$scope=>$method){
            $missing=$paused[$scope]??null;
            if($missing!==null){logger()->warning('Domain pricing notice paused: '.$scope.' (missing '.$missing.')');continue;}
            $scopes[]=$scope;
        }
        if($scopes===[]||!HookRegistration::claim(FilamentView::getFacadeRoot(),self::class)){return;}
        FilamentView::registerRenderHook(PanelsRenderHook::PAGE_HEADER_WIDGETS_BEFORE,static function():string {
            $page=\Livewire\Livewire::current();
            if($page instanceof ViewTld||$page instanceof \App\Admin\Resources\ProductResource\Pages\EditProduct){return self::render($page->getRecord());}
            return '';
        },scopes:$scopes);
    }
    public static function missing(): ?string
    {
        $missing=array_unique(array_values(self::missingScopes()));
        return $missing===[]?null:implode(', ',$missing);
    }
    /** @return array<class-string,string> */
    private static function missingScopes(): array
    {
        $shared=self::sharedMissing();$paused=[];
        foreach(self::SCOPES as$scope=>$method){
            $missing=$shared??(!class_exists($scope)?$scope:($method!==null&&!method_exists($scope,$method)?$scope.'::'.$method:null));
            if($missing!==null){$paused[$scope]=$missing;}
        }
        return $paused;
    }
    private static function sharedMissing(): ?string
    {
        foreach([\Filament\Support\View\ViewManager::class=>'registerRenderHook',\Livewire\LivewireManager::class=>'current']as$class=>$method){
            if(!class_exists($class)||!method_exists($class,$method)){return $class.'::'.$method;}
        }
        return defined(PanelsRenderHook::class.'::PAGE_HEADER_WIDGETS_BEFORE')?null:PanelsRenderHook::class.'::PAGE_HEADER_WIDGETS_BEFORE';
    }
    public static function render(mixed $record): string
    {
        $native=$record instanceof Product;
        if($native&&!NativeIdentity::productRows(NativeIdentity::rows($record))){return '';}
        if(!$native&&!$record instanceof Tld){return '';}
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
        return $native||$status?view('domains::admin.pricing-editor-notice',compact('native','status'))->render():'';
    }
}
