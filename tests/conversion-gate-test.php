<?php
// A conversion journal left by the bridge 0.1.0 converter must be complete before Domains runs.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Services\{ConversionGate,Runtime};

$refuses=static function(Closure $call,string $label):void{
    try{$call();}catch(DomainException $e){expect(str_contains($e->getMessage(),'Incomplete conversion'),$label);return;}
    throw new LogicException('Missing refusal: '.$label);
};
ConversionGate::requireComplete(); // No journal: an installation that never converted boots, and nothing is cached.
Schema::create('domains_conversion_runs',function(Blueprint $t){$t->unsignedBigInteger('id')->primary();$t->string('state');});
$refuses(fn()=>ConversionGate::requireComplete(),'A journal without its map refuses');
Schema::create('domains_conversion_map',function(Blueprint $t){$t->id();});
$refuses(fn()=>ConversionGate::requireComplete(),'An empty journal refuses');
DB::table('domains_conversion_runs')->insert(['id'=>1,'state'=>'staging']);
$refuses(fn()=>ConversionGate::requireComplete(),'A staged conversion refuses');
$refuses(fn()=>Runtime::register(),'A staged conversion refuses registration');
$refuses(fn()=>(new Runtime)->boot(),'A staged conversion refuses boot');
DB::table('domains_conversion_runs')->where('id',1)->update(['state'=>'complete']);
DB::table('domains_conversion_runs')->insert(['id'=>2,'state'=>'complete']);
$refuses(fn()=>ConversionGate::requireComplete(),'A second journal run refuses');
DB::table('domains_conversion_runs')->where('id',2)->delete();
ConversionGate::requireComplete();
DB::enableQueryLog();DB::flushQueryLog();
for($i=0;$i<3;$i++){ConversionGate::requireComplete();}
same([],DB::getQueryLog(),'A completed gate makes no further schema or row queries');DB::disableQueryLog();
$nativeSuccessful=true;echo "Conversion gate verified.\n";
