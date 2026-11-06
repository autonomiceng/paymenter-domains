<?php
// Preserve the real changed-price snapshot while rendering a supplied native theme for local screenshots.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
expect(is_dir($argv[1]),'Artifact directory must exist');
$theme=($argv[2]??(getenv('DOMAINS_RENDER_THEME_ROOT')?:'')).'/theme';expect(is_file($theme.'/views/cart.blade.php'),'Supply a Paymenter theme directory parent');
$themeRoot=dirname($theme);
$loader->addPsr4('Paymenter\\Extensions\\Others\\BecoAdmin\\',$themeRoot.'/extensions/BecoAdmin/',true);
(new Paymenter\Extensions\Others\BecoAdmin\BecoAdmin)->boot();
app('router')->getRoutes()->refreshNameLookups();
require_once $fixture.'/app/Classes/helpers.php';
app()->instance(Paymenter\Extensions\Servers\Domains\Services\CartAdmission::class,$admission);
app()->instance(Paymenter\Extensions\Servers\Domains\Services\Storefront::class,$storefront);
app('view')->prependLocation($theme.'/views');
Illuminate\Support\Facades\Blade::anonymousComponentPath($theme.'/views/components');
app('translator')->getLoader()->addPath($fixture.'/lang');
$component=new App\Livewire\Cart;
$state=json_decode($x->query('//*[@*[name()="wire:snapshot"]]')->item(0)->getAttribute('wire:snapshot'),true);
Paymenter\Extensions\Servers\Domains\Services\CartNotice::restore($component,$state['memo']['domains_notice']??null);
$amount=new App\Classes\Price(['price'=>App\Classes\Cart::items()->sum(fn($item)=>$item->price->total*$item->quantity),'currency'=>App\Classes\Cart::get()->currency]);
file_put_contents($argv[1].'/cart-changed.html',view()->file($theme.'/views/cart.blade.php',['__livewire'=>$component,'total'=>$amount,'coupon'=>null])->render());
