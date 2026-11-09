<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Contracts\ManagementProtocol;
use Paymenter\Extensions\Servers\Domains\Data\RequestOrigin;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Frozen management deltas in the existing ledger. The final delta carries the owner request. */
final class ManagementSequence
{
    public static function key(string $parent): string { return $parent.':prerequisite'; }
    /** Called under the holding lock, in the same transaction as the owner's request. */
    public static function create(array $data, ?array $step, ?array $plan = null): Operation
    {
        if ($plan!==null) { return self::createPlan($data,$plan); }
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
        if (isset($op->policy['management']['sequence'])) {
            foreach (self::members($op,$operations) as $child) {
                if (($child->attempt??[])!==[] && !ManagementEvidence::idle($child)
                    && !($child->state==='succeeded' && ManagementEvidence::resolved($child,$op->domain))) { return true; }
            } return false;
        }
        $key=$op->policy['management']['prerequisite']['key']??null;
        return is_string($key) && ($operations->firstWhere('operation_key',$key)?->attempt??[])!==[] && !self::unsent($op,$operations);
    }
    /** Only the retained unconsumed-grant proof permits replacement after sealing. An exhausted unsealed step was never sent. */
    public static function unsent(Operation $parent, $operations): bool
    {
        if (isset($parent->policy['management']['sequence'])) {
            return self::members($parent,$operations)->contains(fn($child)=>ManagementEvidence::idle($child)
                && ($child->safe_code==='management_definitely_unsent' && ($child->attempt['management_unsent']??false)===true
                    || $child->state==='held' && ($child->attempt['seal']??'absent')==='absent' || ManagementEvidence::exhausted($child)));
        }
        $step=$operations->firstWhere('operation_key',$parent->policy['management']['prerequisite']['key']??null);
        return $step && ManagementEvidence::idle($step) && ($step->safe_code==='management_definitely_unsent' && ($step->attempt['management_unsent']??false)===true || ManagementEvidence::exhausted($step));
    }
    /** Close an untouched or proven-unsent prerequisite under the holding lock, retaining its seal. */
    public static function retirePrerequisite(Operation $parent, $operations, int $actor, \DateTimeImmutable $at): void
    {
        if (isset($parent->policy['management']['sequence'])) {
            if (self::started($parent,$operations)) { throw new \DomainException('Management sequence already started.'); }
            foreach (self::members($parent,$operations) as $child) {
                self::pairPlan($parent,$child);
                if ($child->id===$parent->id || $child->state==='succeeded') { continue; }
                $child->update(['state'=>'rejected','safe_code'=>'management_retired','completed_at'=>$at,
                    'attempt'=>[...($child->attempt??[]),'seal'=>$child->attempt['seal']??'absent','send_state'=>$child->attempt['send_state']??'definitely_unsent',
                        'management_terminal'=>['actor_id'=>$actor,'at'=>$at->format(DATE_ATOM)]]]);
            } return;
        }
        $key=$parent->policy['management']['prerequisite']['key']??null;
        if (!is_string($key)) { return; }
        $step=$operations->firstWhere('operation_key',$key);
        if (!$step || self::started($parent,$operations)) { throw new \DomainException('Management sequence already started.'); }
        self::pair($parent,$step);
        $step->update(['state'=>'rejected','safe_code'=>'management_retired','completed_at'=>$at,
            'attempt'=>[...($step->attempt??[]),'seal'=>$step->attempt['seal']??'absent','send_state'=>$step->attempt['send_state']??'definitely_unsent','management_terminal'=>['actor_id'=>$actor,'at'=>$at->format(DATE_ATOM)]]]);
    }
    /** Enter the predecessor through the same engine. Unknown sealed work is only read, never sent again. */
    public static function prepare(int $id, DomainManagement $engine, ManagementProtocol $driver, RequestOrigin $origin, \DateTimeImmutable $now, int $interactiveSeconds): bool
    {
        $parent=Operation::findOrFail($id);
        if (isset($parent->policy['management']['sequence']) && !isset($parent->policy['management']['sequence_parent'])) {
            if (!in_array($parent->state,['queued','held'],true) || isset($parent->attempt['management_terminal'])) { return true; }
            try {
                foreach (self::members($parent,self::retained($parent,$parent->domain)) as $child) {
                    self::pairPlan($parent,$child);
                    if ($child->id===$parent->id) { return true; }
                    if ($child->state==='succeeded' && ManagementEvidence::resolved($child,$parent->domain)) { continue; }
                    if (DomainManagement::canReconcile($child,$now)) { $engine->reconcile((int)$child->id,$driver); }
                    else { $engine->run((int)$child->id,$driver,$origin,$interactiveSeconds); }
                    return false;
                }
            } catch (\Throwable) {} return false;
        }
        $key=$parent->policy['management']['prerequisite']['key']??null;
        if (!is_string($key) || !in_array($parent->state,['queued','held'],true)) { return true; }
        try {
            $step=Operation::where('operation_key',$key)->sole();self::pair($parent,$step);
            if (isset($parent->attempt['management_terminal'])) { return false; }
            if ($step->state!=='succeeded') {
                $step=DomainManagement::canReconcile($step,$now)
                    ? $engine->reconcile((int)$step->id,$driver) : $engine->run((int)$step->id,$driver,$origin,$interactiveSeconds);
                return false;
            }
            return $step->state==='succeeded' && ManagementEvidence::resolved($step,$parent->domain);
        } catch (\Throwable) { return false; }
    }
    /** Every claim and consume rechecks the frozen link; retired/replaced parents cannot create contacts. */
    public static function requireCurrent(array $locked, Operation $op): void
    {
        if (isset($op->policy['management']['sequence'])) {
            self::currentPlan($locked,$op); return;
        }
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
    /** Stable local configuration, unlike the holding stamp which includes changing operation evidence. */
    public static function configuration(array $l): string
    {
        return hash('sha256',json_encode([$l['domain']->user_id,$l['domain']->authority_generation,
            $l['server']->getRawOriginal(),$l['account']->getRawOriginal(),
            $l['settings']->map(fn($s)=>$s->getRawOriginal())->all()],JSON_THROW_ON_ERROR));
    }
    public static function currentConfiguration(\Paymenter\Extensions\Servers\Domains\Models\Domain $d): string
    {
        return self::configuration(['domain'=>$d,'server'=>\App\Models\Server::findOrFail($d->server_id),
            'account'=>\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$d->server_id)->sole(),
            'settings'=>\App\Models\Server::findOrFail($d->server_id)->settings()->orderBy('id')->get()]);
    }
    private static function createPlan(array $data,array $plan): Operation
    {
        $steps=$plan['steps']??null;
        if (!is_array($steps) || !array_is_list($steps) || count($steps)<1 || count($steps)>50
            || !is_array($plan['preimage']??null) || !is_string($plan['configuration']??null)
            || $data['funding_basis']!=='non_billable_management') { throw new \DomainException('Invalid management sequence.'); }
        $last=count($steps)-1;$key=$data['operation_key'];$management=$data['policy']['management'];
        foreach ($steps as $i=>&$step) {
            if (!is_array($step) || array_keys($step)!==['action','input'] || !is_array($step['input'])
                || DomainManagement::capability($step['action'])!==DomainManagement::capability($data['action'])) { throw new \DomainException('Invalid management delta.'); }
            $step=['key'=>$i===$last?$key:$key.':step:'.$i,...$step];
        } unset($step);
        $sequence=['preimage'=>$plan['preimage'],'configuration'=>$plan['configuration'],'steps'=>$steps];$root=null;
        foreach ($steps as $i=>$step) {
            $root=Operation::create([...$data,'operation_key'=>$step['key'],'action'=>$step['action'],'payload'=>$step['input'],
                'policy'=>['management'=>[...$management,'intent'=>$management['input'],'input'=>$step['input'],'sequence'=>$sequence,'sequence_index'=>$i,
                    ...($i===$last?[]:['sequence_parent'=>$key])]]]);
        } return $root;
    }
    public static function retained(Operation $parent,\Paymenter\Extensions\Servers\Domains\Models\Domain $d)
    { return $d->operations()->whereIn('operation_key',array_column($parent->policy['management']['sequence']['steps'],'key'))->limit(50)->get(); }
    public static function members(Operation $parent,$operations)
    {
        $steps=$parent->policy['management']['sequence']['steps'];$rows=collect();
        foreach ($steps as $step) {
            $child=$operations->firstWhere('operation_key',$step['key']);
            if (!$child) { throw new \DomainException('Management sequence missing.'); }
            $rows->push($child);
        } return $rows;
    }
    private static function pairPlan(Operation $parent,Operation $child): void
    {
        $p=$parent->policy['management'];$s=$child->policy['management'];$i=$s['sequence_index']??null;
        foreach (['domain_id','service_id','server_id','registrar_key','account_fingerprint','authority_generation','authorized_by','funding_basis'] as $field) {
            if ($parent->$field!==$child->$field) { throw new \DomainException('Management sequence authority changed.'); }
        }
        $step=$p['sequence']['steps'][$i]??null;$last=count($p['sequence']['steps'])-1;
        if (!is_int($i) || !$step || $parent->operation_key!==$p['sequence']['steps'][$last]['key']
            || $parent->funding_basis!=='non_billable_management' || $parent->invoice_id!==null || $child->invoice_id!==null
            || ($s['sequence']??null)!==$p['sequence'] || ($s['intent']??null)!==$p['intent'] || ($s['correction']??null)!==($p['correction']??null)
            || $step!==['key'=>$child->operation_key,'action'=>$child->action,'input'=>$s['input']??null]
            || ($i===$last ? $child->id!==$parent->id : ($s['sequence_parent']??null)!==$parent->operation_key)) {
            throw new \DomainException('Management sequence input changed.');
        }
    }
    private static function currentPlan(array $l,Operation $op): void
    {
        $parentKey=$op->policy['management']['sequence_parent']??$op->operation_key;
        $parent=$l['operations']->firstWhere('operation_key',$parentKey);
        if (!$parent) { throw new \DomainException('Management sequence missing.'); }
        self::pairPlan($parent,$op);
        if (isset($parent->attempt['management_terminal']) || !in_array($parent->state,['queued','running'],true)
            || $parent->policy['management']['sequence']['configuration']!==self::configuration($l)) { throw new \DomainException('Management sequence configuration changed.'); }
        foreach (self::members($parent,$l['operations']) as $child) {
            self::pairPlan($parent,$child);
            if ($child->id===$op->id) { return; }
            if ($child->state!=='succeeded' || !ManagementEvidence::resolved($child,$l['domain'])) { throw new \DomainException('Previous management delta unconfirmed.'); }
        }
    }
    /**
     * The verified leading changes of the latest five identical sequences that are unsent or were replaced. Each comes
     * with the inventory read before and after it, so the new plan keeps the rows they added. An unreadable one is skipped.
     */
    public static function confirmed(array $l,string $action,array $intent): array
    {
        $parents=$l['operations']->filter(fn($peer)=>$peer->action===$action && isset($peer->policy['management']['sequence'])
            && !isset($peer->policy['management']['sequence_parent']) && ($peer->policy['management']['intent']??null)===$intent
            && $peer->authority_generation===$l['domain']->authority_generation && $peer->account_fingerprint===$l['domain']->account_fingerprint
            && ($peer->state==='rejected' && $peer->safe_code==='management_retired'
                || in_array($peer->state,['queued','held'],true) && !isset($peer->attempt['management_terminal'])
                    && self::unsent($peer,$l['operations']) && !self::started($peer,$l['operations'])))->sortByDesc('id')->take(5);
        $steps=[];
        foreach ($parents as $parent) {
            $before=$parent->policy['management']['sequence']['preimage'];$found=[];
            try {
                foreach (self::members($parent,$l['operations']) as $child) {
                    self::pairPlan($parent,$child);$after=ManagementReadRecovery::latest($child)['facts']['sequence_preimage']??null;
                    if ($child->state!=='succeeded' || !ManagementEvidence::resolved($child,$l['domain']) || !is_array($after)) { break; }
                    $found[]=['input'=>$child->policy['management']['input'],'before'=>$before,'after'=>$after];$before=$after;
                }
            } catch (\DomainException) { continue; }
            array_push($steps,...$found);
        } return $steps;
    }
    /** Context reconstructed solely from frozen policy and verified predecessor evidence. */
    public static function context(Operation $op,array $l): ?array
    {
        $p=$op->policy['management'];$sequence=$p['sequence']??null;
        if (!$sequence) { return null; }
        $preimage=$sequence['preimage'];$i=$p['sequence_index'];
        if ($i>0) {
            $previous=$l['operations']->firstWhere('operation_key',$sequence['steps'][$i-1]['key']);
            if (!$previous || $previous->state!=='succeeded' || !ManagementEvidence::resolved($previous,$l['domain'])) { throw new \DomainException('Previous management delta unconfirmed.'); }
            $preimage=ManagementReadRecovery::latest($previous)['facts']['sequence_preimage']??null;
            if (!is_array($preimage)) { throw new \DomainException('Management preimage missing.'); }
        }
        return ['preimage'=>$preimage,'ack'=>$op->attempt['management_ack']??[]];
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
