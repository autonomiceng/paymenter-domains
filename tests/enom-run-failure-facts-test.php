<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
// Synthetic retained-marker failures; no registrar requests.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Support\RegistrarFacts;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{Installation,EnomTestRunBudgetSchema as Tables};
(new Installation)->install();
$account=new Account(['registrar_key'=>'enom','environment'=>'test']);
$errors=[];
$check=static function(string $failure)use($account,&$errors):void{
    try{
        $message=(new ReflectionMethod(RegistrarAccount::class,'testRefusal'))->invoke(null);
        same('Enom TEST run budget unavailable; requests are refused.',$message,'Refusal remains explainable');
        echo "Refusal message survives $failure.\n";
    }catch(Throwable $e){$errors[]="Refusal message ($failure): ".$e::class;}
    try{
        $fact=RegistrarFacts::calls($account);
        same(null,$fact['value'],'Unavailable budget has no fabricated count');
        same('danger',$fact['tone'],'Unavailable budget is visibly refused');
        same('Enom TEST run budget unavailable; requests are refused.',$fact['sub'],'Budget failure has a safe cause');
        echo "Account fact survives $failure.\n";
    }catch(Throwable $e){$errors[]="Account fact ($failure): ".$e::class;}
};
config(['domains.enom_test_run_policy'=>null]);
DB::table(Tables::LOCK)->update(['version'=>99]);$check('invalid marker');
DB::table(Tables::LOCK)->delete();$check('missing marker');
Schema::drop(Tables::LOCK);Schema::create(Tables::LOCK,static fn($t)=>$t->integer('wrong_column'));
$check('failed marker lookup');
expect($errors===[],implode("\n",$errors));
$nativeSuccessful=true;
