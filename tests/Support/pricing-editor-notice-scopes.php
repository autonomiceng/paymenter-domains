<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic missing upstream APIs.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support {
    function method_exists(object|string $object,string $method):bool
    { $class=is_object($object)?$object::class:$object;return ($GLOBALS['pricingNoticeMissing']??null)===$class.'::'.$method?false:\method_exists($object,$method); }
    function class_exists(string $class,bool $autoload=true):bool
    { return ($GLOBALS['pricingNoticeMissing']??null)===$class?false:\class_exists($class,$autoload); }
    function defined(string $constant):bool
    { return ($GLOBALS['pricingNoticeMissing']??null)===$constant?false:\defined($constant); }
}
namespace {
    return static function():void {
        $notice=\Paymenter\Extensions\Servers\Domains\Admin\Support\PricingEditorNotice::class;
        $tld=\Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld::class;
        $product=\App\Admin\Resources\ProductResource\Pages\EditProduct::class;
        $all=[$tld,$product];
        $root=\Filament\Support\Facades\FilamentView::getFacadeRoot();
        $logger=\Illuminate\Support\Facades\Log::getFacadeRoot();
        try{
            foreach([
                [$product.'::getRecord',[$tld]],
                [$tld.'::getRecord',[$product]],
                [\Filament\Support\View\ViewManager::class.'::registerRenderHook',[]],
                [\Livewire\LivewireManager::class.'::current',[]],
                [\Filament\View\PanelsRenderHook::class.'::PAGE_HEADER_WIDGETS_BEFORE',[]],
            ]as[$missing,$available]){
                $GLOBALS['pricingNoticeMissing']=$missing;
                \Filament\Support\Facades\FilamentView::swap(new \Filament\Support\View\ViewManager);
                \Illuminate\Support\Facades\Log::swap($logger);
                \Illuminate\Support\Facades\Log::spy();
                $notice::boot();
                same($missing,$notice::missing(),'Missing page APIs remain visible to staff diagnostics');
                $warning=collect(\Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::system()['failing'])->firstWhere('label','Pricing change warning');
                expect($warning!==null&&str_contains($warning['help'],$missing),'System checks identify the exact missing pricing notice API');
                foreach($all as$scope){
                    same(in_array($scope,$available,true),\Filament\Support\Facades\FilamentView::hasRenderHook(\Filament\View\PanelsRenderHook::PAGE_HEADER_WIDGETS_BEFORE,$scope),'Only available pricing notice scopes register: '.$missing);
                    if(!in_array($scope,$available,true)){
                        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->with('Domain pricing notice paused: '.$scope.' (missing '.$missing.')')->once();
                    }
                }
            }
        }finally{
            unset($GLOBALS['pricingNoticeMissing']);
            \Filament\Support\Facades\FilamentView::swap($root);
            \Illuminate\Support\Facades\Log::swap($logger);
        }
        echo "Pricing notice scopes isolate missing page APIs; shared prerequisites pause every scope.\n";
    };
}
