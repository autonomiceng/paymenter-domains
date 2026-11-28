<?php
// Enom 444f31c reservation/provider-disposition safety; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureEnding = 'example.com';
require __DIR__.'/Support/operation-fixture.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionClosureKind, OperationAttempts};
use Paymenter\Extensions\Servers\Domains\Services\{DomainReservations, OperationTerminalProof, Refunds};
// The synthetic catalogue explicitly supports the reserved compound ending example.com.
$op = $new(reservedFixtureName('unsent.example.com')); [$claimed] = $claims->claim($op->id); $held = $claims->finish($claimed);
same('held', $held->state, 'Completed unsealed worker held');
same('pre_send_interrupted', $held->safe_code, 'Completed unsealed worker retains the expected refusal');
$result = OperationTerminalProof::encode($claims, $op->id); same('rejected', $result->state, 'Normalized definitely-unsent terminal proof');
$terminal = \Paymenter\Extensions\Servers\Domains\Operations\Authority\OperationAttempts::evidence((int)$result->id, null, 'terminal');
same('definitely_unsent', $terminal['outcome'] ?? null, 'Terminal proof belongs to the operation ledger');
same($result->operation_key, $terminal['evidence']['operation_key'], 'Terminal proof identifies the retained operation');
same($held->acquisitionAttemptFacts()->token->attemptId, $terminal['evidence']['attempt_id'], 'Terminal proof identifies the exact closed worker');
same(AcquisitionClosureKind::Unsent, OperationAttempts::purchaseProof($result->fresh(), $clock->now())->closure,
    'The typed closure reads back through the released predecessor');
commerceReject(fn()=>DomainReservations::releaseTerminal($result->domain->domain));
expect((new Refunds($clock))->creditRejected($result->id)!==null,'Exact unsent account credit commits through engine');
DomainReservations::releaseTerminal($result->domain->domain); same(null, $result->domain->fresh()->reservation_name, 'Reservation service releases proven unsent only after credit');
foreach (['sealed', 'expired'] as $mode) {
    $op = $new(reservedFixtureName($mode.'.example.com'));
    if ($mode === 'sealed') { $op = $engine->execute($op->id); }
    else { [$claimed] = $claims->claim($op->id); $clock->instant = $clock->now()->modify('+361 seconds'); $op = $claims->finish($claimed); }
    $expected = $mode === 'sealed' ? ['uncertain', 'reconciliation_required', 'sealed'] : ['running', null, 'absent'];
    same($expected, [$op->state, $op->safe_code, $op->attempt['seal']], $mode.' worker is still awaiting the next job');
    commerceReject(fn () => OperationTerminalProof::encode($claims, $op->id));
    commerceReject(fn () => DomainReservations::releaseTerminal($op->domain->domain));
    same($op->domain->domain, $op->domain->fresh()->reservation_name, 'An unresolved or expired worker cannot release the reservation');
    $clock->instant = $now;
}
// The pre-apply drain must resolve a legacy inline claim on the old build. New writers cannot legitimize it.
$legacy = $new(reservedFixtureName('legacy-claimed.example.com'));
Illuminate\Support\Facades\DB::table('domains_operations')->where('id', $legacy->id)->update([
    'state' => 'held', 'safe_code' => 'name_unavailable', 'started_at' => $now,
    'request_fingerprint' => str_repeat('a', 64),
    'attempt' => Illuminate\Support\Facades\Crypt::encryptString(json_encode([
        'claim_key' => str_repeat('b', 32), 'seal' => 'absent', 'send_state' => 'definitely_unsent',
    ], JSON_THROW_ON_ERROR)),
    'funding' => Illuminate\Support\Facades\Crypt::encryptString(json_encode(['claim' => []], JSON_THROW_ON_ERROR)),
]);
commerceReject(fn () => OperationTerminalProof::encode($claims, $legacy->id));
same(null, OperationAttempts::evidence((int) $legacy->id, null, 'terminal'), 'An inline claim cannot acquire a new typed terminal');
same('held', $legacy->fresh()->state, 'A drained-only legacy shape is left intact');

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
        expect(OperationAttempts::purchaseProof($proof,$now)->closure === AcquisitionClosureKind::Unsent,'Complete terminal proof qualifies');
        if ($action === 'register' && $claimed) {
            $domain = $result->domain;
            $proof->fill($domain->only(['server_id', 'service_id', 'account_fingerprint']));
            $proof->domain_id = $domain->id;
            expect(OperationTerminalProof::safe($proof, $domain), 'Registration terminal proof accepts earlier cap history');
            $foreign = clone $proof;
            $foreign->attempt = [...$attempt, 'terminal' => ['operation_key' => $key, 'generation' => 2]];
            expect(!OperationTerminalProof::safe($foreign, $domain), 'Earlier cap history cannot validate a foreign terminal decision');
        }
        foreach ([['seal'],['send_state'],['seal','send_state']] as $missing) {
            $broken=clone $proof;$facts=$attempt;foreach($missing as $field){unset($facts[$field]);}$broken->attempt=$facts;
            set_error_handler(static function(int $severity,string $message,string $file,int $line):never{throw new ErrorException($message,0,$severity,$file,$line);});
            try{same(null,OperationAttempts::purchaseProof($broken,$now)->closure,'Missing terminal fields quietly refuse');}
            finally{restore_error_handler();}
        }
    }
}
echo "Terminal proof retains paid reservations until exact unsent credit commits\n"; $nativeSuccessful = true;
