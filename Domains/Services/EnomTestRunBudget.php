<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Models\Account;

/** Admitted credits become retained attempts immediately before the physical send. */
final class EnomTestRunBudget
{
    public const CONTRACT = 2;
    // Lock version 3 retains the requirement for a migration receipt across later runs.
    private const MIGRATED = 3;
    private static ?string $pending = null;
    private const FIELDS = ['version','run_id','generation','cap','rolling_cap','policy_hash',
        'ledger_dev','ledger_ino','ledger_uid','ledger_gid','account_fingerprint','configuration_intent'];

    /** The durable marker prevents a missing deployment setting from restoring daily TEST sends. */
    public static function enabled(): bool
    {
        if (config('domains.enom_test_run_policy') !== null) { return true; }
        try {
            if(!Schema::hasTable(EnomTestRunBudgetSchema::LOCK)){
                if(Schema::hasTable(EnomTestLegacyBudgetSchema::TABLE) && DB::table(EnomTestLegacyBudgetSchema::TABLE)->exists()){throw new EnomTestCallLimit;}
                if(Schema::hasTable(EnomTestRunBudgetSchema::RUNS) && DB::table(EnomTestRunBudgetSchema::RUNS)->exists()){throw new EnomTestCallLimit;}
                return false;
            }
            $version=DB::table(EnomTestRunBudgetSchema::LOCK)->where('id',1)->value('version');
            if($version===null || !in_array((int)$version,[0,self::CONTRACT,self::MIGRATED],true)){throw new EnomTestCallLimit;}
            return in_array((int)$version,[self::CONTRACT,self::MIGRATED],true);
        } catch (\Throwable) { EnomTestCalls::refuse(); }
    }

    private static function policy(array $policy): array
    {
        $keys=array_keys($policy); sort($keys); $expected=self::FIELDS; sort($expected);
        if ($keys!==$expected || $policy['version']!==self::CONTRACT
            || !is_string($policy['run_id']) || !preg_match('/^[A-Za-z0-9_-]{1,48}$/D',$policy['run_id'])
            || !is_string($policy['generation']) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$policy['generation'])
            || !is_int($policy['cap']) || $policy['cap']<1
            || !is_int($policy['rolling_cap']) || $policy['rolling_cap']<1
            || !is_string($policy['configuration_intent']) || trim($policy['configuration_intent'])==='' || strlen($policy['configuration_intent'])>1024
            || !is_string($policy['policy_hash']) || !preg_match('/^[a-f0-9]{64}$/D',$policy['policy_hash'])) {
            throw new EnomTestCallLimit;
        }
        foreach (['ledger_dev','ledger_ino','ledger_uid','ledger_gid'] as $field) {
            if (!is_int($policy[$field]) || $policy[$field]<0) { throw new EnomTestCallLimit; }
        }
        self::fingerprint($policy['account_fingerprint']);
        ksort($policy);
        $hashed=$policy; unset($hashed['policy_hash']);
        if (!hash_equals($policy['policy_hash'],hash('sha256',self::json($hashed)))) { throw new EnomTestCallLimit; }
        return $policy;
    }

    private static function json(array $value): string
    { return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES); }
    private static function fingerprint(?string $fingerprint): void
    { if ($fingerprint!==null && !preg_match('/^[a-f0-9]{64}$/D',$fingerprint)) { throw new EnomTestCallLimit; } }
    private static function configured(): array
    {
        $value=config('domains.enom_test_run_policy');
        if (!is_array($value)) { throw new EnomTestCallLimit; }
        return self::policy($value);
    }

    /** Writers take SQLite's write lock first; read-only observations never advance the durable clock. */
    private static function serialized(\Closure $work,bool $readOnly=false): mixed
    {
        OperationClaims::outside();
        try {
            return DB::transaction(static function () use ($work,$readOnly): mixed {
                if (!$readOnly && DB::table(EnomTestRunBudgetSchema::LOCK)->where('id',1)->increment('revision')!==1) { throw new EnomTestCallLimit; }
                $lock=DB::table(EnomTestRunBudgetSchema::LOCK)->where('id',1)->lockForUpdate()->sole();
                $clock=app(Clock::class)->now()->setTimezone(new \DateTimeZone('UTC'));
                $now=(int)$clock->format('U')*1000000+(int)$clock->format('u');
                // Persist the boot/worker clock relationship. A reboot or a different clock source
                // refuses rather than silently aging retained attempts out of the rolling window.
                $boot=file_get_contents('/proc/sys/kernel/random/boot_id');
                if (!is_string($boot) || !preg_match('/^[a-f0-9-]{36}\\s*$/D',$boot)) { throw new EnomTestCallLimit; }
                $clockId=hash('sha256',php_uname('n').'|'.trim($boot));
                $monotonic=intdiv(hrtime(true),1000);
                if ($now<1 || $now<(int)$lock->last_time || $monotonic<1
                    || ($lock->clock_id!==null && ($clockId!==$lock->clock_id || $monotonic<(int)$lock->monotonic_time
                        || abs(($now-(int)$lock->last_time)-($monotonic-(int)$lock->monotonic_time))>5000000))) { throw new EnomTestCallLimit; }
                if(!$readOnly){DB::table(EnomTestRunBudgetSchema::LOCK)->where('id',1)->update(['last_time'=>$now,'clock_id'=>$clockId,'monotonic_time'=>$monotonic]);}
                $legacy=DB::table(EnomTestLegacyBudgetSchema::TABLE)->where('id',1)->first();
                if(!$legacy && (int)$lock->version===self::MIGRATED){throw new EnomTestCallLimit;}
                if($legacy){
                    if(!hash_equals($legacy->proof_hash,hash('sha256',$legacy->proof))){throw new EnomTestCallLimit;}
                    $retained=[];
                    foreach(DB::table(EnomTestCallSchema::TABLE)->orderBy('day')->get() as $row){$retained[]=['day'=>$row->day,'used'=>(int)$row->used];}
                    if($retained!==json_decode($legacy->proof,true,32,JSON_THROW_ON_ERROR)['native']){throw new EnomTestCallLimit;}
                    if(!$readOnly){DB::table(EnomTestRunBudgetSchema::LOCK)->where('id',1)->update(['version'=>self::MIGRATED]);$lock->version=self::MIGRATED;}
                }
                return $work($lock,$now);
            });
        } catch (\Throwable) { EnomTestCalls::refuse(); }
    }

    private static function run(array $policy): object
    {
        $row=DB::table(EnomTestRunBudgetSchema::RUNS)->where('run_id',$policy['run_id'])->first();
        if (!$row || $row->generation!==$policy['generation'] || $row->policy!==self::json($policy)) { throw new EnomTestCallLimit; }
        return $row;
    }
    private static function active(object $lock,array $policy): object
    {
        $row=self::run($policy);
        if (!in_array((int)$lock->version,[self::CONTRACT,self::MIGRATED],true) || $lock->run_id!==$row->run_id || $row->phase!=='active') { throw new EnomTestCallLimit; }
        return $row;
    }
    private static function rolling(int $now): int
    { return (int)DB::table(EnomTestRunBudgetSchema::PERMITS)->where('attempt_time','>',$now-86400000000)->count()
        +self::legacyDebt($now); }
    private static function legacyDebt(?int $now=null): int
    {
        $receipt=DB::table(EnomTestLegacyBudgetSchema::TABLE)->where('id',1)->first();
        if(!$receipt){return 0;}
        $history=json_decode($receipt->proof,true,32,JSON_THROW_ON_ERROR);$dated=[];
        foreach($history['attempts'] as $time){$day=gmdate('Y-m-d',intdiv($time,1000000));$dated[$day]=($dated[$day]??0)+1;}
        $debt=$history['undated_debt'];
        foreach($history['native'] as $row){
            // A date is an interval, not a physical-send timestamp. Expire only after its upper bound +24h.
            $exclusive=(new \DateTimeImmutable($row['day'].'T00:00:00Z'))->modify('+2 days')->getTimestamp()*1000000;
            if($now===null || $now<$exclusive){$debt+=max(0,$row['used']-($dated[$row['day']]??0));}
        }
        return $debt;
    }
    private static function held(object $row): int
    { return (int)DB::table(EnomTestRunBudgetSchema::PERMITS)->where('run_id',$row->run_id)->whereNull('attempt_time')->count(); }
    private static function conservation(object $row,array $policy,int $now,int $additional=0): void
    {
        $used=(int)$row->used; $remaining=(int)$row->remaining;
        $outstanding=(int)DB::table(EnomTestRunBudgetSchema::RUNS)->sum('remaining');
        $rolling=self::rolling($now);
        if ($additional<0 || $remaining<self::held($row) || $used<0 || $remaining<0
            || $additional>$policy['cap'] || $remaining>$policy['cap']-$additional || $used>$policy['cap']-$additional-$remaining
            || $additional>$policy['rolling_cap'] || $outstanding>$policy['rolling_cap']-$additional
            || $rolling>$policy['rolling_cap']-$additional-$outstanding) { throw new EnomTestCallLimit; }
    }
    private static function facts(object $row,array $policy,int $now): array
    {
        return ['version'=>self::CONTRACT,'run_id'=>$row->run_id,'generation'=>$row->generation,'phase'=>$row->phase,
            'used'=>(int)$row->used,'remaining'=>(int)$row->remaining,'rolling_used'=>self::rolling($now),
            'cap'=>$policy['cap'],'rolling_cap'=>$policy['rolling_cap'],
            'legacy_debt'=>self::legacyDebt($now)];
    }

    public static function prepare(array $policy,int $credits): array
    {
        return self::serialized(static function (object $lock,int $now) use ($policy,$credits): array {
            $policy=self::policy($policy);
            if ($credits<0) { throw new EnomTestCallLimit; }
            $existing=DB::table(EnomTestRunBudgetSchema::RUNS)->where('run_id',$policy['run_id'])->first();
            if ($existing) {
                $row=self::run($policy);
                if ((int)$row->initial_credits!==$credits) { throw new EnomTestCallLimit; }
                return self::facts($row,$policy,$now);
            }
            if(DB::table(EnomTestLegacyBudgetSchema::TABLE)->where('id',1)->where('run_id',$policy['run_id'])->exists()){throw new EnomTestCallLimit;}
            if (DB::table(EnomTestRunBudgetSchema::RUNS)->whereIn('phase',['preparing','active','disabled'])->exists()
                || (DB::table(EnomTestCallSchema::TABLE)->where('used','>',0)->exists()
                    && !DB::table(EnomTestLegacyBudgetSchema::TABLE)->where('id',1)->exists())) { throw new EnomTestCallLimit; }
            // Date-only legacy reservations have no provable 24-hour exclusion boundary.
            $row=(object)['run_id'=>$policy['run_id'],'used'=>0,'remaining'=>0];
            self::conservation($row,$policy,$now,$credits);
            DB::table(EnomTestRunBudgetSchema::RUNS)->insert(['run_id'=>$policy['run_id'],'generation'=>$policy['generation'],
                'policy'=>self::json($policy),'phase'=>'preparing','account_fingerprint'=>$policy['account_fingerprint'],
                'used'=>0,'remaining'=>$credits,'initial_credits'=>$credits]);
            DB::table(EnomTestRunBudgetSchema::LOCK)->where('id',1)->update(['version'=>(int)$lock->version===self::MIGRATED?self::MIGRATED:self::CONTRACT,'run_id'=>$policy['run_id']]);
            return self::facts(self::run($policy),$policy,$now);
        });
    }

    /** Trusted quiesced coordinator only. Daily source rows remain byte-for-byte retained.
     * Exact timestamps and UTC-date residuals age conservatively; undated debt never expires.
     * A source hash binds the full host ledger/journals/deployment proof, never an operator count.
     */
    public static function migrateLegacy(array $policy,array $history,int $credits): array
    {
        return self::serialized(static function(object $lock,int $now)use($policy,$history,$credits):array {
            $policy=self::policy($policy);
            $inventory=self::legacyInventory($policy,$history,$credits,$now);
            $used=$inventory['native_used'];$rolling=$inventory['rolling_used'];
            $proof=self::json($history);$hash=hash('sha256',$proof);
            $receipt=DB::table(EnomTestLegacyBudgetSchema::TABLE)->where('id',1)->first();
            if($receipt){
                if($receipt->run_id!==$policy['run_id'] || $receipt->proof_hash!==$hash
                    || $receipt->proof!==$proof || (int)$receipt->credits!==$credits){throw new EnomTestCallLimit;}
                return self::facts(self::run($policy),$policy,$now);
            }
            if((int)$lock->version!==0 || DB::table(EnomTestRunBudgetSchema::RUNS)->exists()
                || DB::table(EnomTestRunBudgetSchema::PERMITS)->exists()) {throw new EnomTestCallLimit;}
            if($credits>$policy['cap'] || $used>$policy['cap']-$credits
                || $credits>$policy['rolling_cap'] || $rolling>$policy['rolling_cap']-$credits){throw new EnomTestCallLimit;}
            DB::table(EnomTestLegacyBudgetSchema::TABLE)->insert(['id'=>1,'run_id'=>$policy['run_id'],
                'proof_hash'=>$hash,'proof'=>$proof,'debt'=>$history['undated_debt'],'credits'=>$credits]);
            DB::table(EnomTestRunBudgetSchema::RUNS)->insert(['run_id'=>$policy['run_id'],'generation'=>$policy['generation'],
                'policy'=>self::json($policy),'phase'=>'preparing','account_fingerprint'=>$policy['account_fingerprint'],
                'used'=>$used,'remaining'=>$credits,'initial_credits'=>$credits]);
            foreach($history['attempts'] as $index=>$time){
                DB::table(EnomTestRunBudgetSchema::PERMITS)->insert(['permit_id'=>hash('sha256',$hash.':'.$index),
                    'run_id'=>$policy['run_id'],'account_fingerprint'=>$policy['account_fingerprint'],
                    'action'=>'legacy','operation_id'=>null,'attempt_time'=>$time]);
            }
            DB::table(EnomTestRunBudgetSchema::LOCK)->where('id',1)->update(['version'=>self::MIGRATED,'run_id'=>$policy['run_id']]);
            return self::facts(self::run($policy),$policy,$now);
        });
    }

    /** Read-only preflight under the native deployment lock; never creates permits or credits. */
    public static function previewLegacy(array $policy,array $history,int $credits): array
    {
        return self::serialized(static function(object $lock,int $now)use($policy,$history,$credits):array {
            $policy=self::policy($policy);
            if((int)$lock->version!==0 || DB::table(EnomTestRunBudgetSchema::RUNS)->exists()
                || DB::table(EnomTestRunBudgetSchema::PERMITS)->exists()
                || DB::table(EnomTestLegacyBudgetSchema::TABLE)->exists()){throw new EnomTestCallLimit;}
            return self::legacyInventory($policy,$history,$credits,$now);
        },true);
    }

    private static function legacyInventory(array $policy,array $history,int $credits,int $now): array
    {
        $keys=array_keys($history);sort($keys);
        if($keys!==['attempts','native','source_hash','undated_debt'] || $credits<0
            || !is_array($history['native']) || !array_is_list($history['native'])
            || !is_array($history['attempts']) || !array_is_list($history['attempts'])
            || !is_int($history['undated_debt']) || $history['undated_debt']<0
            || !is_string($history['source_hash']) || !preg_match('/^[a-f0-9]{64}$/D',$history['source_hash'])
            || $policy['account_fingerprint']===null) {throw new EnomTestCallLimit;}
        $accounts=Account::where('registrar_key','enom')->where('environment','test')->get();
        if($accounts->count()!==1 || $accounts->sole()->account_fingerprint!==$policy['account_fingerprint']){throw new EnomTestCallLimit;}
        $native=[];
        foreach(DB::table(EnomTestCallSchema::TABLE)->orderBy('day')->get() as $row){
            if(!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$row->day)
                || (new \DateTimeImmutable($row->day.'T00:00:00Z'))->format('Y-m-d')!==$row->day
                || $row->day>gmdate('Y-m-d',intdiv($now,1000000)) || (int)$row->used<0){throw new EnomTestCallLimit;}
            $native[]=['day'=>$row->day,'used'=>(int)$row->used];
        }
        if($history['native']!==$native){throw new EnomTestCallLimit;}
        foreach($history['attempts'] as $time){if(!is_int($time)||$time<1||$time>$now){throw new EnomTestCallLimit;}}
        $count=count($history['attempts']);$dated=[];
        foreach($history['attempts'] as $time){$day=gmdate('Y-m-d',intdiv($time,1000000));$dated[$day]=($dated[$day]??0)+1;}
        $debt=$history['undated_debt'];$rollingDebt=$debt;
        foreach($native as $row){
            $residual=max(0,$row['used']-($dated[$row['day']]??0));$debt+=$residual;
            $exclusive=(new \DateTimeImmutable($row['day'].'T00:00:00Z'))->modify('+2 days')->getTimestamp()*1000000;
            if($now<$exclusive){$rollingDebt+=$residual;}
        }
        $used=$count+$debt;
        $rolling=count(array_filter($history['attempts'],static fn($t)=>$t>$now-86400000000))+$rollingDebt;
        $outstanding=(int)DB::table(EnomTestRunBudgetSchema::RUNS)->sum('remaining');
        return ['native_used'=>$used,'rolling_used'=>$rolling,'legacy_debt'=>$debt,
            'physical_used'=>$count,'outstanding_credits'=>$outstanding,'required_credits'=>$credits,
            'native_required'=>$used+$credits,'rolling_required'=>$rolling+$outstanding+$credits,
            'native_short'=>max(0,$used+$credits-$policy['cap']),
            'rolling_short'=>max(0,$rolling+$outstanding+$credits-$policy['rolling_cap'])];
    }

    public static function legacySourceHash(array $policy): ?string
    {
        return self::serialized(static function(object $lock,int $now)use($policy):?string {
            $policy=self::policy($policy);self::run($policy);
            $row=DB::table(EnomTestLegacyBudgetSchema::TABLE)->where('id',1)->first();
            return $row ? json_decode($row->proof,true,32,JSON_THROW_ON_ERROR)['source_hash'] : null;
        },true);
    }

    public static function allocate(array $policy,int $credits,string $allocationId): void
    {
        self::serialized(static function (object $lock,int $now) use ($policy,$credits,$allocationId): void {
            $policy=self::policy($policy); $row=self::run($policy);
            if ($lock->run_id!==$row->run_id || !in_array($row->phase,['preparing','active'],true)
                || $credits<1 || trim($allocationId)==='' || strlen($allocationId)>255) { throw new EnomTestCallLimit; }
            $key=hash('sha256',self::json([$row->run_id,$allocationId]));
            $existing=DB::table(EnomTestRunBudgetSchema::ALLOCATIONS)->where('allocation_key',$key)->first();
            if ($existing) {
                if ($existing->run_id!==$row->run_id || $existing->allocation_id!==$allocationId || (int)$existing->credits!==$credits) { throw new EnomTestCallLimit; }
                return;
            }
            self::conservation($row,$policy,$now,$credits);
            DB::table(EnomTestRunBudgetSchema::ALLOCATIONS)->insert(['allocation_key'=>$key,'run_id'=>$row->run_id,'allocation_id'=>$allocationId,'credits'=>$credits]);
            DB::table(EnomTestRunBudgetSchema::RUNS)->where('run_id',$row->run_id)->increment('remaining',$credits);
        });
    }

    public static function activate(array $policy): void { self::phase($policy,'active',['preparing','active']); }
    public static function disable(array $policy): void { self::phase($policy,'disabled',['preparing','active','disabled']); }
    private static function phase(array $policy,string $phase,array $from): void
    {
        self::serialized(static function (object $lock,int $now) use ($policy,$phase,$from): void {
            $policy=self::policy($policy); $row=self::run($policy);
            if ($lock->run_id!==$row->run_id || !in_array($row->phase,$from,true)) { throw new EnomTestCallLimit; }
            self::conservation($row,$policy,$now);
            DB::table(EnomTestRunBudgetSchema::RUNS)->where('run_id',$row->run_id)->update(['phase'=>$phase]);
        });
    }
    /** Caller must disable and drain senders before closing. Attempts and permit identities are retained. */
    public static function close(array $policy): void
    {
        self::serialized(static function (object $lock,int $now) use ($policy): void {
            $policy=self::policy($policy); $row=self::run($policy);
            if ($row->phase==='closed') { return; }
            if ($lock->run_id!==$row->run_id || $row->phase!=='disabled') { throw new EnomTestCallLimit; }
            DB::table(EnomTestRunBudgetSchema::RUNS)->where('run_id',$row->run_id)->update(['remaining'=>0,'phase'=>'closed']);
        });
    }
    public static function bindAccount(array $policy,string $fingerprint): void
    {
        self::serialized(static function (object $lock,int $now) use ($policy,$fingerprint): void {
            $policy=self::policy($policy); self::fingerprint($fingerprint); $row=self::run($policy);
            if ($lock->run_id!==$row->run_id || !in_array($row->phase,['preparing','active'],true)
                || ($row->account_fingerprint!==null && $row->account_fingerprint!==$fingerprint)) { throw new EnomTestCallLimit; }
            if($row->account_fingerprint===null
                && !DB::table(EnomTestRunBudgetSchema::PERMITS)->where('run_id',$row->run_id)->whereNull('account_fingerprint')->whereNotNull('attempt_time')->exists()) { throw new EnomTestCallLimit; }
            if($row->account_fingerprint===null
                && !Account::where('registrar_key','enom')->where('environment','test')->where('account_fingerprint',$fingerprint)->exists()) { throw new EnomTestCallLimit; }
            DB::table(EnomTestRunBudgetSchema::RUNS)->where('run_id',$row->run_id)->update(['account_fingerprint'=>$fingerprint]);
        });
    }
    public static function status(?array $policy=null,bool $readOnly=false): array
    {
        return self::serialized(static function (object $lock,int $now) use ($policy): array {
            if ($policy!==null) { $policy=self::policy($policy); $row=self::run($policy); }
            else {
                $row=DB::table(EnomTestRunBudgetSchema::RUNS)->where('run_id',$lock->run_id)->first();
                if (!$row) { throw new EnomTestCallLimit; }
                $policy=self::policy(json_decode($row->policy,true,32,JSON_THROW_ON_ERROR));
            }
            return self::facts($row,$policy,$now);
        },$readOnly);
    }
    public static function assertCapacity(int $required): void
    {
        self::serialized(static function (object $lock,int $now) use ($required): void {
            $policy=self::configured(); $row=self::active($lock,$policy); self::conservation($row,$policy,$now);
            if ($required<0 || $required>(int)$row->remaining-self::held($row)) { throw new EnomTestCallLimit; }
        });
    }
    public static function reserve(?string $fingerprint=null,string $action='read',?int $operationId=null): string
    {
        $permit=self::serialized(static function (object $lock,int $now) use ($fingerprint,$action,$operationId): string {
            $policy=self::configured(); $row=self::active($lock,$policy); self::conservation($row,$policy,$now);
            self::fingerprint($fingerprint);
            if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$action) || ($operationId!==null && $operationId<1)
                || ($row->account_fingerprint!==$fingerprint)
                || ($fingerprint===null && ($action!=='read' || $operationId!==null
                    || DB::table(EnomTestRunBudgetSchema::PERMITS)->where('run_id',$row->run_id)->exists()))
                || (int)$row->remaining<=self::held($row)) { throw new EnomTestCallLimit; }
            $permit=bin2hex(random_bytes(32));
            DB::table(EnomTestRunBudgetSchema::PERMITS)->insert(['permit_id'=>$permit,'run_id'=>$row->run_id,
                'account_fingerprint'=>$fingerprint,'action'=>$action,'operation_id'=>$operationId,'attempt_time'=>null]);
            return $permit;
        });
        self::$pending=$permit;
        return $permit;
    }

    /** Direct setup readers have a UID rather than a server binding. Existing accounts must match. */
    public static function reserveRead(string $uid,string $command='GetBalance'): string
    {
        $fingerprint=null;
        $accounts=Account::where('registrar_key','enom')->where('environment','test')->get();
        foreach ($accounts as $account) {
            if (strtolower($account->stable_identity)===strtolower($uid)) {
                if ($fingerprint!==null) { EnomTestCalls::refuse(); }
                $fingerprint=(new AccountBinding('enom',(int)$account->server_id,\Paymenter\Extensions\Servers\Domains\Data\Environment::Test,$uid))->fingerprint;
            }
        }
        if($fingerprint===null && ($accounts->isNotEmpty() || $command!=='GetBalance')){EnomTestCalls::refuse();}
        return self::reserve($fingerprint);
    }
    private static function permit(object $row,string $permit,?string $fingerprint,?string $action,?int $operationId): object
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$permit)) { throw new EnomTestCallLimit; }
        $record=DB::table(EnomTestRunBudgetSchema::PERMITS)->where('permit_id',$permit)->first();
        if (!$record || $record->run_id!==$row->run_id || $record->attempt_time!==null
            || ($fingerprint!==null && $record->account_fingerprint!==$fingerprint)
            || ($action!==null && $record->action!==$action)
            || ($operationId!==null && (int)$record->operation_id!==$operationId)
            || ($record->account_fingerprint!==$row->account_fingerprint)) { throw new EnomTestCallLimit; }
        return $record;
    }
    public static function assertPermit(string $permit,?string $fingerprint=null,?string $action=null,?int $operationId=null): void
    {
        self::serialized(static function (object $lock,int $now) use ($permit,$fingerprint,$action,$operationId): void {
            $policy=self::configured(); $row=self::active($lock,$policy); self::conservation($row,$policy,$now);
            self::permit($row,$permit,$fingerprint,$action,$operationId);
        });
        self::$pending=$permit;
    }
    public static function consume(string $permit,?string $fingerprint=null,?string $action=null,?int $operationId=null): void
    {
        self::serialized(static function (object $lock,int $now) use ($permit,$fingerprint,$action,$operationId): void {
            $policy=self::configured(); $row=self::active($lock,$policy); self::conservation($row,$policy,$now);
            self::permit($row,$permit,$fingerprint,$action,$operationId);
            if ((int)$row->remaining<1) { throw new EnomTestCallLimit; }
            if (DB::table(EnomTestRunBudgetSchema::PERMITS)->where('permit_id',$permit)->whereNull('attempt_time')->update(['attempt_time'=>$now])!==1) { throw new EnomTestCallLimit; }
            DB::table(EnomTestRunBudgetSchema::RUNS)->where('run_id',$row->run_id)->update(['used'=>(int)$row->used+1,'remaining'=>(int)$row->remaining-1]);
        });
        if (self::$pending===$permit) { self::$pending=null; }
    }
    public static function pendingPermit(): ?string { return self::$pending; }
    public static function clearPending(): void { self::$pending=null; }
}
