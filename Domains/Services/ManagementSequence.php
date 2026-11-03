<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Contracts\ManagementProtocol;
use Paymenter\Extensions\Servers\Domains\Data\RequestOrigin;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** A frozen prerequisite and its request, in the existing ledger and worker. No extra queue or write grant. */
final class ManagementSequence
{
    public static function key(string $parent): string { return $parent.':prerequisite'; }
    /** Called under the holding lock, in the same transaction as the owner's request. */
    public static function create(array $data, ?array $step): Operation
    {
        if ($step && !Operation::where('operation_key',$step['key'])->exists()) {
            if ($step['key']!==self::key($data['operation_key']) || $step['action']===$data['action']) {
                throw new \DomainException('Invalid management prerequisite.');
            }
            $management=$data['policy']['management'];
            Operation::create([...$data, 'operation_key'=>$step['key'], 'action'=>$step['action'], 'payload'=>$step['input'],
                'policy'=>['management'=>[...$management, 'input'=>$step['input'], 'sequence_parent'=>$data['operation_key']]]]);
            $data['policy']['management']['prerequisite']=$step;
        }
        return Operation::create($data);
    }
    public static function started(Operation $op, $operations): bool
    {
        $key=$op->policy['management']['prerequisite']['key']??null;
        return is_string($key) && ($operations->firstWhere('operation_key',$key)?->attempt??[])!==[];
    }
    /** Retiring an untouched request also closes its untouched prerequisite, under the holding lock. */
    public static function retirePrerequisite(Operation $parent, $operations, int $actor, \DateTimeImmutable $at): void
    {
        $key=$parent->policy['management']['prerequisite']['key']??null;
        if (!is_string($key)) { return; }
        $step=$operations->firstWhere('operation_key',$key);
        if (!$step || ($step->attempt??[])!==[]) { throw new \DomainException('Management sequence already started.'); }
        self::pair($parent,$step);
        $step->update(['state'=>'rejected','safe_code'=>'management_retired','completed_at'=>$at,
            'attempt'=>['seal'=>'absent','send_state'=>'definitely_unsent','management_terminal'=>['actor_id'=>$actor,'at'=>$at->format(DATE_ATOM)]]]);
    }
    /** Enter the predecessor through the same engine. Unknown sealed work is only read, never sent again. */
    public static function prepare(int $id, DomainManagement $engine, ManagementProtocol $driver, RequestOrigin $origin, \DateTimeImmutable $now): bool
    {
        $parent=Operation::findOrFail($id);$key=$parent->policy['management']['prerequisite']['key']??null;
        if (!is_string($key) || !in_array($parent->state,['queued','held'],true)) { return true; }
        try {
            $step=Operation::where('operation_key',$key)->sole();self::pair($parent,$step);
            if (isset($parent->attempt['management_terminal'])) { return false; }
            if ($step->state!=='succeeded') {
                $step=DomainManagement::canReconcile($step,$now)
                    ? $engine->reconcile((int)$step->id,$driver) : $engine->run((int)$step->id,$driver,$origin);
            }
            return $step->state==='succeeded' && ManagementEvidence::resolved($step,$parent->domain);
        } catch (\Throwable) { return false; }
    }
    /** Every claim and consume rechecks the frozen link; retired/replaced parents cannot create contacts. */
    public static function requireCurrent(array $locked, Operation $op): void
    {
        $parentKey=$op->policy['management']['sequence_parent']??null;
        $stepKey=$op->policy['management']['prerequisite']['key']??null;
        if (!is_string($parentKey) && !is_string($stepKey)) { return; }
        $parent=$parentKey ? $locked['operations']->firstWhere('operation_key',$parentKey) : $op;
        $step=$parentKey ? $op : $locked['operations']->firstWhere('operation_key',$stepKey);
        if (!$parent || !$step) { throw new \DomainException('Management prerequisite missing.'); }
        self::pair($parent,$step);
        if (isset($parent->attempt['management_terminal']) || !in_array($parent->state,['queued','running'],true)
            || (!$parentKey && ($step->state!=='succeeded' || !ManagementEvidence::resolved($step,$locked['domain'])))) {
            throw new \DomainException('Management prerequisite unavailable.');
        }
    }
    private static function pair(Operation $parent, Operation $step): void
    {
        $p=$parent->policy['management']??[];$s=$step->policy['management']??[];
        foreach (['domain_id','service_id','server_id','registrar_key','account_fingerprint','authority_generation','authorized_by'] as $field) {
            if ($parent->$field!==$step->$field) { throw new \DomainException('Management sequence authority changed.'); }
        }
        if (($p['prerequisite']['key']??null)!==$step->operation_key || ($p['prerequisite']['action']??null)!==$step->action || ($s['sequence_parent']??null)!==$parent->operation_key
            || $step->operation_key!==self::key($parent->operation_key) || $parent->funding_basis!=='non_billable_management'
            || $step->funding_basis!==$parent->funding_basis || $parent->invoice_id!==null || $step->invoice_id!==null
            || ($p['correction']??null)!==($s['correction']??null)
            || ($p['prerequisite']['input']??null)!==($s['input']??null)) {
            throw new \DomainException('Management sequence input changed.');
        }
    }
}
