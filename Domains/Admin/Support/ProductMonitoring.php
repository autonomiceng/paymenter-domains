<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Notifications\Notification;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\{Component,Livewire};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource;
use Paymenter\Extensions\Servers\Domains\Models\WatchProductMap;
use Paymenter\Extensions\Servers\Domains\Services\{HookRegistration,StaffWatchWrites,WatchMappings,WatchProducts,WatchWork};
/** The native product editor's Domains section: a Monitor domains switch, the same setting as Choose products on
 * Monitoring settings. Only hosting products whose orders carry a domain get it. */
final class ProductMonitoring extends Component
{
    public const NAME='domains-product-monitoring';
    #[Locked] public int $product;
    public static function boot(): void
    {
        if(!class_exists(\App\Admin\Resources\ProductResource\Pages\EditProduct::class)||!HookRegistration::claim(FilamentView::getFacadeRoot(),self::class)){return;}
        Livewire::component(self::NAME,self::class);
        FilamentView::registerRenderHook(PanelsRenderHook::PAGE_FOOTER_WIDGETS_BEFORE,static function():string {
            $page=Livewire::current();
            if(!$page instanceof \App\Admin\Resources\ProductResource\Pages\EditProduct||!Staff::can(StaffWatchWrites::VIEW)||!app(WatchWork::class)->active()){return '';}
            $id=(int)$page->getRecord()->id;
            return WatchProducts::inScope()->whereKey($id)->exists()?Livewire::mount(self::NAME,['product'=>$id],self::NAME.'-'.$id):'';
        },scopes:[\App\Admin\Resources\ProductResource\Pages\EditProduct::class]);
    }
    private static function manager(): bool { return Staff::can(StaffWatchWrites::VIEW)&&Staff::can(StaffWatchWrites::MANAGE); }
    public function toggle(): void
    {
        abort_unless(self::manager(),403);
        try{(new WatchMappings())->monitor(Auth::user(),[$this->product=>!$this->on()]);}
        catch(\DomainException){Notification::make()->warning()->title('This product changed. Reload the page and try again.')->send();return;}
        Notification::make()->success()->title($this->on()?'Monitoring turned on':'Monitoring turned off')->send();
    }
    private function on(): bool { return WatchProductMap::where('product_id',$this->product)->value('enabled')!==false; }
    public function render(): View
    {
        $on=$this->on();
        try{$active=WatchProducts::active([$this->product])[$this->product]??0;}catch(\Throwable){$active=null;}
        return view('domains::admin.product-monitoring',['on'=>$on,'active'=>$active,'can'=>self::manager(),'settings'=>WatchResource::getUrl('settings')]);
    }
}
