<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\User;
use Illuminate\Support\Facades\{Crypt,DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
/** One-use, actor/action/domain-bound review. All mutation checks run under the writer's locks. */
final class StaffOperationReview
{
    /** Check status covers both a fresh read (recheck) and restarting reads after the read limit (resume_reads). */
    public const ACTIONS=['recheck'=>'Check status','resume_reads'=>'Check status','resume_observation'=>'Resume sync',
        'hold'=>'Put on hold','release'=>'Release hold','disposition'=>'Close request',
        'refund'=>'Cancel and refund','credit'=>'Retry refund','replacement'=>'Reissue renewal invoice'];
    public const CHANGED='This request changed. Reload the page and try again.';
    private array $before=[];
    private array $verifiedTicket=[];
    public function __construct(public readonly int $actorId, public readonly int $domainId, public readonly int $operationId,
        public readonly string $action, private readonly string $ticket, public readonly string $reason, private readonly Clock $clock) {}
    public static function permission(string $action): string
    { if(!isset(self::ACTIONS[$action])){throw new StaffReviewRefused('Unknown review action.');} return 'admin.domains.operations.'.$action; }
    public static function stamp(Operation $op,Domain $d): string
    { return hash('sha256',json_encode([$op->getRawOriginal(),$d->getRawOriginal()],JSON_THROW_ON_ERROR)); }
    public static function ticket(int $actor,int $domain,int $op,string $action,string $stamp,Clock $clock): string
    {
        return Crypt::encryptString(json_encode(['actor'=>$actor,'domain'=>$domain,'operation'=>$op,'action'=>$action,'stamp'=>$stamp,
            'key'=>bin2hex(random_bytes(16)),'until'=>$clock->now()->getTimestamp()+900],JSON_THROW_ON_ERROR));
    }
    public function check(array $l): void
    {
        if(DB::transactionLevel()!==1){throw new \LogicException('Staff review requires the outer writer transaction.');}
        $staff=$l['staff']??null; $op=$l['operations']->firstWhere('id',$this->operationId); $d=$l['domain'];
        if(!$staff instanceof User || (int)$staff->id!==$this->actorId || !$staff->hasPermission('admin.domains.view')
            || !$staff->hasPermission(self::permission($this->action))
            || (!in_array($this->action,['recheck','resume_reads','resume_observation'],true)&&!$staff->hasPermission('admin.domains.manage'))
            || (in_array($this->action,['refund','credit','replacement'],true)&&!$staff->hasPermission('admin.invoices.update'))
            || ($this->action==='release'&&$op?->funding_basis==='complimentary'&&!$staff->hasPermission('admin.domains.fund'))) {
            throw new StaffReviewRefused("You don't have permission to do this.");
        }
        if($op?->state==='succeeded' && ($this->action!=='resume_observation' || !ObservationRecords::stopped($d))){
            throw new StaffReviewRefused($this->action==='resume_observation' ? 'Sync is already running.' : 'This request is already completed.');
        }
        StaffReason::require($this->reason);
        if($op){$this->requireIdle($op);}
        $t=$this->decode();
        if(!$op || (int)$d->id!==$this->domainId || $op->domain_id!==$d->id || $op->service_id!==$d->service_id
            || $op->server_id!==$d->server_id || $op->registrar_key!==$d->registrar_key || $op->account_fingerprint!==$d->account_fingerprint
            || $op->authority_generation!==$d->authority_generation || $op->authority_generation<1
            || !hash_equals($t['stamp'],self::stamp($op,$d))) { throw new StaffReviewRefused(self::CHANGED); }
        $history=$op->policy['staff_actions']??[];
        $limit=in_array($this->action,['recheck','resume_reads','resume_observation'],true)?($this->action==='recheck'?88:89):99;
        if(!is_array($history)||!array_is_list($history)||count($history)>$limit
            || array_filter($history,fn($a)=>($a['key']??null)===$t['key'])) { throw new StaffReviewRefused(self::CHANGED); }
        $this->verifiedTicket=$t; $this->before=self::state($op,$d);
    }
    public static function liveLease(Operation $op,Clock $clock): bool
    { return $op->state==='running' && $op->lease_expires_at!==null && $op->lease_expires_at>$clock->now(); }
    private function requireIdle(Operation $op): void
    {
        if(self::liveLease($op,$this->clock)){
            throw new StaffReviewRefused('This request is being sent. Try again in a few minutes.');
        }
    }
    private function decode(): array
    {
        try { $t=json_decode(Crypt::decryptString($this->ticket),true,8,JSON_THROW_ON_ERROR); }
        catch(\Throwable){throw new StaffReviewRefused(self::CHANGED);}
        if(($t['actor']??null)!==$this->actorId||($t['domain']??null)!==$this->domainId||($t['operation']??null)!==$this->operationId
            ||($t['action']??null)!==$this->action||!is_int($t['until']??null)||$t['until']<=$this->clock->now()->getTimestamp()
            ||!is_string($t['stamp']??null)||!is_string($t['key']??null)){throw new StaffReviewRefused(self::CHANGED);}
        return $t;
    }
    public function record(array $l,string $phase='completed',?\Throwable $failure=null): void
    {
        if(!$this->before||DB::transactionLevel()!==1){throw new \LogicException('Missing locked review.');}
        $op=$l['operations']->firstWhere('id',$this->operationId)->fresh(); $d=$l['domain']->fresh(); $t=$this->verifiedTicket;
        $this->requireIdle($op);
        $history=$op->policy['staff_actions']??[];
        $history[]=['key'=>$t['key'],'action'=>$this->action,'phase'=>$phase,'actor_id'=>$this->actorId,'reason'=>$this->reason,
            'at'=>$this->clock->now()->format(DATE_ATOM),'operation_key'=>$op->operation_key,'generation'=>$op->authority_generation,
            'before'=>$this->before,'after'=>self::state($op,$d),...($failure?['safe_code'=>OperationFailure::code($failure)]:[])];
        $op->update(['policy'=>[...($op->policy??[]),'staff_actions'=>$history]]);
    }
    public function local(\Closure $write): mixed
    {
        OperationClaims::outside(); $seed=Domain::findOrFail($this->domainId);
        return DB::transaction(function()use($seed,$write){$l=HoldingLocks::take($seed,$this->actorId);$this->check($l);
            $result=$write($l['operations']->firstWhere('id',$this->operationId),$l);$this->record($l,$this->action==='recheck'?'read_requested':'completed');return $result;});
    }
    public function read(\Closure $read): mixed
    {
        $this->local(fn()=>null); // Durable request audit and key consumption before provider I/O; no locks over reads.
        $failure=null;
        try { return $read(); }
        catch(\Throwable $error){$failure=$error;throw $error;}
        finally {
            DB::transaction(function()use($failure){ $d=Domain::whereKey($this->domainId)->lockForUpdate()->firstOrFail();
                $l=['domain'=>$d,'operations'=>$d->operations()->orderBy('id')->lockForUpdate()->get()];$this->record($l,$failure?'read_failed':'read_finished',$failure); });
        }
    }
    private static function state(Operation $op,Domain $d): array
    { return ['state'=>$op->state,'reason'=>$op->safe_code,'domain_status'=>$d->status,'credit_marker'=>array_key_exists('refund',$op->funding??[]),
        'polls'=>$op->policy['reconciliation']['polls']??null,'observation'=>hash('sha256',json_encode($d->observation,JSON_THROW_ON_ERROR))]; }
}
