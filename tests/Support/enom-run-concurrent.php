<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require dirname(__DIR__).'/native-app.php';
require __DIR__.'/enom-run-budget.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestCallLimit,EnomTestCallSchema,EnomTestRunBudget as Budget,EnomTestRunBudgetSchema as Tables};
$path=getenv('RUN_BUDGET_DB');
expect(is_string($path)&&str_starts_with($path,dirname(__DIR__,2).'/.cache/')&&is_file($path),'Private synthetic SQLite database');
DB::disconnect();config(['database.connections.sqlite.database'=>$path,'database.connections.sqlite.busy_timeout'=>15000]);DB::purge();
// Workers start together, so synthetic monotonic observations may be identical.
$fp=hash('sha256','synthetic');$p=runPolicy('concurrent',2,2,$fp);config(['domains.enom_test_run_policy'=>$p]);
$mode=$argv[1];
try{
    if($mode==='setup'){EnomTestCallSchema::install();Tables::install();}
    elseif($mode==='admit'){
        Budget::prepare($p,1);Budget::allocate($p,1,'shared-envelope');Budget::activate($p);
        same(2,Budget::status()['remaining'],'Concurrent resume/allocation is idempotent');
    }elseif($mode==='different'){
        $other=runPolicy('conflict',2,2,$fp);Budget::prepare($other,1);
    }elseif($mode==='reserve'){echo 'PERMIT='.Budget::reserve($fp)."\n";}
    elseif($mode==='consume'){Budget::consume($argv[2],$fp);}
    elseif($mode==='status'){echo 'STATUS='.json_encode(Budget::status(),JSON_THROW_ON_ERROR)."\n";}
    elseif($mode==='fault'){
        DB::listen(static function($q):void{
            if(str_starts_with(strtolower($q->sql),'update')&&str_contains($q->sql,Tables::RUNS)&&str_contains($q->sql,'"used"')){throw new RuntimeException('Synthetic failed commit');}
        });
        Budget::consume($argv[2],$fp);
    }else{throw new LogicException('Unknown fixture mode');}
    echo "OK\n";
}catch(EnomTestCallLimit){echo "REFUSED\n";}
$nativeSuccessful=true;
