<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native owner preview and mutation lock boundary.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
require_once __DIR__.'/Support/native-financial-snapshot.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling,HoldingLocks};
[$d,$driver,$original]=$holding(placeholderComFixtureName('renew-preview-lock-placeholder.com'));
$product->server->settings()->updateOrCreate(['key'=>'domains_manual_renewals_enabled'],['value'=>true,'type'=>'boolean']);
$billing=new DomainBilling($bindings,$guard,$clock,catalogue:$caps);
$before=nativeFinancialSnapshot($original);
$connection=DB::connection();$grammar=$connection->getQueryGrammar();
$strict=new class($connection) extends Illuminate\Database\Query\Grammars\SQLiteGrammar {
    public int $locks=0;
    protected function compileLock(Illuminate\Database\Query\Builder $query,$value){$this->locks++;throw new RuntimeException('Unexpected row lock');}
};
$connection->setQueryGrammar($strict);
try {
    $preview=$billing->requestOptions($d);
    same(['terms'=>[1],'order_by'=>'2027-10-17'],$preview,'Owner renewal preview retains exact terms and deadline');
    same(0,$strict->locks,'Owner renewal preview attempts no row lock');
    try{DB::transaction(static fn()=>HoldingLocks::take($d));expect(false,'Mutation prefix must still lock');}
    catch(RuntimeException $error){same('Unexpected row lock',$error->getMessage(),'Mutation prefix keeps the ordered locking boundary');}
    same(1,$strict->locks,'Only the mutation prefix attempts a row lock');
}finally{$connection->setQueryGrammar($grammar);}
same($before,nativeFinancialSnapshot($original),'Preview and refused mutation change no native or financial state');
$nativeSuccessful=true;echo "Owner renewal preview reads without locks; mutation prefix still locks\n";
