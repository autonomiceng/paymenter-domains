<?php
// Enom 444f31c reservation/provider-disposition safety; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureEnding = 'example.com';
require __DIR__.'/Support/operation-fixture.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
use Paymenter\Extensions\Servers\Domains\Services\{DomainReservations, OperationTerminalProof, Refunds};
// The synthetic catalogue explicitly supports the reserved compound ending example.com.
$op = $new(reservedFixtureName('unsent.example.com')); [$claimed] = $claims->claim($op->id); $held = $claims->finish($claimed);
same('held', $held->state, 'Completed unsealed worker held');
same('pre_send_interrupted', $held->safe_code, 'Completed unsealed worker retains the expected refusal');
$result = OperationTerminalProof::encode($claims, $op->id); same('rejected', $result->state, 'Normalized definitely-unsent terminal proof');
commerceReject(fn()=>DomainReservations::releaseTerminal($result->domain->domain));
expect((new Refunds($clock))->creditRejected($result->id)!==null,'Exact unsent account credit commits through engine');
DomainReservations::releaseTerminal($result->domain->domain); same(null, $result->domain->fresh()->reservation_name, 'Reservation service releases proven unsent only after credit');
foreach (['sealed', 'expired'] as $mode) {
    $op = $new(reservedFixtureName($mode.'.example.com'));
    if ($mode === 'sealed') { $op = $engine->execute($op->id); }
    else { [$claimed] = $claims->claim($op->id); $clock->instant = $clock->now()->modify('+361 seconds'); $op = $claims->finish($claimed); }
    same('uncertain', $op->state, $mode.' worker is uncertain before terminal proof');
    same($mode === 'sealed' ? 'reconciliation_required' : 'stale_claim', $op->safe_code, $mode.' worker retains its expected reason');
    commerceReject(fn () => OperationTerminalProof::encode($claims, $op->id));
    commerceReject(fn () => DomainReservations::releaseTerminal($op->domain->domain));
    same($op->domain->domain, $op->domain->fresh()->reservation_name, 'Uncertain/expired facts cannot release');
    $clock->instant = $now;
}
// Missing terminal send evidence must refuse quietly in both claim shapes.
foreach (['register','transfer'] as $action) {
    foreach ([false,true] as $claimed) {
        $key='synthetic-terminal-'.$action;
        $attempt=['test_call_refusals'=>[['phase'=>'preflight','refused_at'=>$now->format(DATE_ATOM),'dispatch_attempted_at'=>null,'authorized_cost'=>null]],
            'seal'=>'absent','send_state'=>'definitely_unsent','disposition'=>'rejected','rejection_basis'=>'definitely_unsent',
            'terminal'=>['operation_key'=>$key,'generation'=>1]];
        if($claimed){$attempt['claim_key']=str_repeat('a',32);}
        $proof=new \Paymenter\Extensions\Servers\Domains\Models\Operation(['action'=>$action,'registrar_key'=>'enom','state'=>'rejected',
            'safe_code'=>'enom_test_call_cap','authority_generation'=>1,'operation_key'=>$key,'attempt'=>$attempt,
            'funding'=>$claimed?['claim'=>[]]:[],'cost'=>[],'policy'=>[],
            'started_at'=>$claimed?$now:null,'request_fingerprint'=>$claimed?str_repeat('a',64):null]);
        expect(\Paymenter\Extensions\Servers\Domains\Services\OperationClaims::testCallRefusalUnsent($proof),'Complete terminal proof qualifies');
        foreach ([['seal'],['send_state'],['seal','send_state']] as $missing) {
            $broken=clone $proof;$facts=$attempt;foreach($missing as $field){unset($facts[$field]);}$broken->attempt=$facts;
            set_error_handler(static function(int $severity,string $message,string $file,int $line):never{throw new ErrorException($message,0,$severity,$file,$line);});
            try{same(false,\Paymenter\Extensions\Servers\Domains\Services\OperationClaims::testCallRefusalUnsent($broken),'Missing terminal fields quietly refuse');}
            finally{restore_error_handler();}
        }
    }
}
echo "Terminal proof retains paid reservations until exact unsent credit commits\n"; $nativeSuccessful = true;
