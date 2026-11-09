<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use App\Models\{Category,Product,Service};
use Paymenter\Extensions\Servers\Domains\Services\NativeActivationInformation;
$category=Category::create(['name'=>'Domains','slug'=>'domains']);
foreach(['register','transfer'] as $action){
    $product=Product::create(['category_id'=>$category->id,'name'=>'Domain '.$action,'slug'=>$action]);
    $product->settings()->create(['key'=>'domains_action','value'=>$action]);
    NativeActivationInformation::initialize($product);
    $service=(new Service)->setRelation('product',$product);
    $service->id=1;
    app('router')->get('/services/{service}',fn()=>'')->name('services.show');app('router')->getRoutes()->refreshNameLookups();
    $body=Illuminate\View\Compilers\BladeCompiler::render($product->email_template,compact('service'));
    if($action==='register'){expect(!str_contains($body,'transfer')&&!str_contains($body,'auth code'),'Registration confirmation has no transfer instructions');}
    else{expect(str_contains($body,'transfer')&&str_contains($body,'auth code')&&!str_contains($body,'Registrations'),'Transfer confirmation has only transfer instructions');}
}
$product->email_template=(new ReflectionClass(NativeActivationInformation::class))->getConstant('PREVIOUS');
NativeActivationInformation::initialize($product);
same(NativeActivationInformation::TEXT,$product->email_template,'An initialized product upgrades only the previous default');
$product->email_template='Custom registration instructions.';
NativeActivationInformation::initialize($product);
same('Custom registration instructions.',$product->email_template,'Staff instructions survive publication');
$nativeSuccessful=true;echo "Registration and transfer confirmation instructions passed.\n";
