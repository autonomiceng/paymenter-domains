<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,ManagedPublicObservation,PublicDnsObservation,WatchDnsObservation,WatchName};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Value;
/** Read-only evidence: public answers never certify registrar mutations or billing. */
final class MonitoringEvidence
{
    private const ADDRESS_TYPES = ['A'=>FILTER_FLAG_IPV4, 'AAAA'=>FILTER_FLAG_IPV6];

    public static function domain(WatchName $w): ?Domain
    {
        return $w->managed_domain_id===null?null:Domain::whereKey($w->managed_domain_id)->where('user_id',$w->user_id)->where('domain',$w->canonical_name)->first();
    }
    /** An inconclusive family keeps its last definitive observation for the attempted target. */
    public static function forWatch(WatchName $watch): array
    {
        $attempt = $watch->points;
        $name = $watch->canonical_name;
        $evidence = self::read(self::domain($watch), $attempt, $name);
        $families = $evidence['public_families'];
        if (!$attempt?->id || count($families) === count(self::ADDRESS_TYPES)) { return $evidence; }
        try { $stamp = $attempt->facts['presentation_stamp'] ?? null; }
        catch (\Illuminate\Contracts\Encryption\DecryptException) { return $evidence; }
        // Compare with the attempted read. A later pause does not erase the history of that target.
        $history = WatchDnsObservation::where('watch_id',$watch->id)->where('user_id',$watch->user_id)
            ->where('watch_revision',$attempt->watch_revision)->where('profile_id',$attempt->profile_id)
            ->where('profile_revision',$attempt->profile_revision)->where('psl_revision',$attempt->psl_revision)
            ->where('check_kind','points')->where('id','<',$attempt->id);
        foreach ($history->lazyByIdDesc(32) as $prior) {
            try { $facts = $prior->facts; }
            catch (\Illuminate\Contracts\Encryption\DecryptException) { continue; }
            $priorStamp = $facts['presentation_stamp'] ?? null;
            // The presentation stamp covers rotating sources. Legacy rows can still match the worker's source digest.
            $sameTarget = $stamp && $priorStamp
                ? $stamp === $priorStamp
                : $attempt->source_digest && $attempt->source_digest === $prior->source_digest;
            if (!$sameTarget) { continue; }
            $families += self::addressFamilies($facts['queries'] ?? [], $name, $prior->started_at, retained:true);
            if (count($families) === count(self::ADDRESS_TYPES)) { break; }
        }
        return [...$evidence, ...self::publicEvidence($families, $attempt->started_at, $evidence['public_stale'])];
    }

    /** An empty answer list means a confirmed absence. An omitted family has no definitive result. */
    private static function addressFamilies(array $queries, ?string $name, ?int $at, bool $retained = false): array
    {
        $families = [];
        $nameAbsent = false;
        foreach ($queries as $query) {
            if (($query['name'] ?? null) !== $name) { continue; }
            $type = $query['type'] ?? null;
            $state = $query['state'] ?? null;
            $nameAbsent = $nameAbsent || ($state === 'negative' && ($query['code'] ?? null) === 'nxdomain');
            if (!isset(self::ADDRESS_TYPES[$type])) { continue; }
            if ($state === 'negative') { $families[$type] ??= []; }
            // Older stored answer lists may lack a state field.
            if ($state !== null && $state !== 'rrset') { continue; }
            foreach ($query['records'] ?? [] as $record) {
                $value = $record['value'] ?? null;
                if (is_string($value) && filter_var($value, FILTER_VALIDATE_IP, self::ADDRESS_TYPES[$type])) {
                    $families[$type][] = $type.' @ → '.$value;
                }
            }
        }
        if ($nameAbsent) { $families += array_fill_keys(array_keys(self::ADDRESS_TYPES), []); }
        return array_map(static fn (array $answers): array => ['answers'=>$answers, 'at'=>$at, 'retained'=>$retained], $families);
    }

    private static function publicEvidence(array $families, ?int $attemptAt, bool $stale): array
    {
        ksort($families);
        $answers = array_merge(...array_column($families, 'answers'));
        $read = match (true) {
            $answers !== [] => 'answered',
            count($families) === count(self::ADDRESS_TYPES) => 'negative',
            $attemptAt !== null => 'unknown',
            default => null,
        };
        $shown = match ($read) {
            'answered' => array_filter($families, static fn (array $family): bool => $family['answers'] !== []),
            'negative' => $families,
            default => [],
        };
        $times = array_values(array_unique(array_column($shown, 'at')));
        $retained = in_array(true, array_column($shown, 'retained'), true);
        // A single date applies only when all displayed evidence comes from that time.
        $at = match (count($times)) { 0 => $attemptAt, 1 => $times[0], default => null };
        $families = array_map(static fn (array $family): array => [...$family, 'stale'=>$stale || $family['retained']], $families);
        return ['public_stale'=>$stale || $retained,'public'=>$answers,'public_at'=>$at,'public_read'=>$read,
            'public_retained'=>$retained,'public_families'=>$families];
    }

    public static function read(?Domain $d,?PublicDnsObservation $public=null,?string $name=null): array
    {
        $name??=$d?->domain;
        if($d&&$public===null&&\Illuminate\Support\Facades\Schema::hasTable('domains_managed_public_observations')){$public=ManagedPublicObservation::where('domain_id',$d->id)->where('user_id',$d->user_id)->where('generation',$d->authority_generation)->where('account_fingerprint',$d->account_fingerprint)->where('domain_revision',$d->confirmed_revision)->where('check_kind','points')->orderByDesc('id')->first();}
        $families=$d?RecordFamilies::read($d,app(Clock::class)->now()):null;
        $states=array_column(array_filter($families['rows']??[],fn($r)=>$r['state']!=='unsupported'),'state');
        $state=!$states?'Unknown':(count(array_unique($states))===1&&$states[0]==='complete'?'Complete':(array_intersect($states,['complete','partial'])?'Partial':'Unknown'));
        $registrar=[];
        foreach($families['families']['dns']['records']??[] as $r){if(in_array($r['type']??null,['A','AAAA'],true)){$registrar[]=$r['type'].' '.($r['name']?:'@').' → '.$r['address'];}}
        try { $queries = $public?->facts['queries'] ?? []; }
        catch (\Illuminate\Contracts\Encryption\DecryptException) { $queries = []; }
        $familiesByType = self::addressFamilies($queries, $name, $public?->started_at);
        $registrarAt=$families['families']['dns']['at']??null;$failedAt=$families['read_failed_at']??null;
        if($failedAt!==null&&($registrarAt===null||strtotime($failedAt)>strtotime($registrarAt))){$registrarAt=$failedAt;}
        // Retained family values do not turn a failed registrar attempt into a complete read.
        $absent=false;$failed=false;
        try { $absent=$d&&\Paymenter\Extensions\Servers\Domains\Services\HoldingReadRetry::absent($d); $failed=$d&&($d->observation['record_failure']['identity']??null)===\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::identity($d); }
        catch (\Illuminate\Contracts\Encryption\DecryptException) {}
        return ['state'=>$state,...self::publicEvidence($familiesByType, $public?->started_at, self::publicStale($d,$public,$name)),
            'registrar'=>$registrar,'registrar_absent'=>$absent,'registrar_at'=>$registrarAt,'registrar_read'=>$failed?'unknown':($families['families']['dns']['state']??null),'at'=>$families['at']??null,'families'=>$families,
            'separation'=>RecordFamilies::EVIDENCE];
    }
    /** Use the established public-signal freshness and structural target checks; retained answers are historical. */
    private static function publicStale(?Domain $d,?PublicDnsObservation $public,?string $name): bool
    {
        $now=app(Clock::class)->now()->getTimestamp();
        if(!$public||$public->check_kind!=='points'||$public->result!=='complete'||$public->partial||$public->valid_until===null||$now<$public->started_at||$now>=$public->valid_until){return true;}
        if($public instanceof ManagedPublicObservation){
            if(!$d||$d->domain!==$name||(int)$public->domain_id!==(int)$d->id||(int)$public->user_id!==(int)$d->user_id||$public->account_fingerprint!==$d->account_fingerprint||(int)$public->generation!==(int)$d->authority_generation||(int)$public->domain_revision!==(int)$d->confirmed_revision){return true;}
            $target=\Paymenter\Extensions\Servers\Domains\Dns\PublicSignalsTarget::managed((int)$d->id,(int)$d->user_id,$d->account_fingerprint,(int)$d->authority_generation,(int)$d->confirmed_revision);
        }else{
            $watch=WatchName::whereKey($public->watch_id)->where('user_id',$public->user_id)->where('canonical_name',$name)->first();
            if(!$watch||(int)$public->watch_revision!==(int)$watch->revision){return true;}
            $target=\Paymenter\Extensions\Servers\Domains\Dns\PublicSignalsTarget::watch((int)$watch->id,(int)$watch->user_id,(int)$watch->revision);
        }
        try{$fresh=(new \Paymenter\Extensions\Servers\Domains\Services\PublicSignalsProjection)->read($target,$now);}
        catch(\Illuminate\Contracts\Encryption\DecryptException){return true;}
        return !collect(['website','email'])->contains(fn($axis)=>!($fresh[$axis]['stale']??true)&&($fresh[$axis]['checked_at']??null)===$public->started_at);
    }
    /** The Monitoring rows, label => value: what public DNS answered and what the registrar holds, each with when it was
     * checked. None follows a confirmed absence of the public name or both address families, or a complete registrar
     * DNS read. A read that failed or stopped part way says Couldn't read with
     * when it was tried; registrar addresses from such a read stay listed over the same words. Saved public answers past
     * their freshness, empty or not, say they are not current. Never checked says Not read yet, once. A failed first
     * registrar read has no record time, so it takes the failure time. $withRecords: the page already shows the
     * registrar records and their last read, so the refresh row is left out.
     * @return array<string,Value> */
    public static function rows(array $evidence, bool $withRecords = false): array
    {
        $time = static function ($at): ?Fact {
            $t = is_int($at) ? $at : (is_string($at) && $at !== '' ? strtotime($at) : false);
            return $t === false ? null : Fact::date($t, Reader::Staff);
        };
        $seen = static function (array $answers, $at, bool $confirmed, string $checked, string $failed, bool $stale = false, bool $unfinished = false) use ($time): Value {
            if (($when = $time($at)) === null) { return $answers === [] ? Value::state(State::NotReadYet, Reader::Staff) : Value::fact(Fact::lines($answers)); }
            $label = $unfinished ? $failed : ($stale ? 'Not current. Last checked' : $checked);
            if ($answers !== []) { return Value::fact(Fact::lines($answers), $label, $when); }
            return $confirmed ? Value::plainAt(Plain::None, $label, $when) : Value::stateAt(State::CouldntRead, Reader::Staff, $stale ? 'Not current. '.$failed : $failed, $when);
        };
        $refresh = ($at = $time($evidence['at'] ?? null)) === null ? Value::state(State::NotReadYet, Reader::Staff) : Value::fact(Fact::entered($evidence['state']), 'Read', $at);
        return [...($withRecords ? [] : ['Registrar refresh' => $refresh]),
            'Public DNS' => $seen($evidence['public'] ?? [], $evidence['public_at'] ?? null, ($evidence['public_read'] ?? null) === 'negative',
                'Checked', 'The check got no clear answer. Last tried', (bool) ($evidence['public_stale'] ?? false)),
            'Registrar records' => ($evidence['registrar_absent']??false) ? Value::state(State::UnderReview,Reader::Staff,'Not in our registrar account.')->detail(...(($evidence['registrar']??[])?[Fact::lines($evidence['registrar'])]:[]),...(($evidence['registrar_at']??null)?[$time($evidence['registrar_at'])]:[])) : $seen($evidence['registrar'] ?? [], $evidence['registrar_at'] ?? null, ($evidence['registrar_read'] ?? null) === 'complete',
                'Read', 'The last read did not finish. Last tried', false, ($evidence['registrar_read'] ?? null) !== 'complete')];
    }
    /** Only timestamps actually retained by the registrar inventory are represented as read events. */
    public static function history(WatchName $w): array
    {
        $d=self::domain($w);if(!$d){return [];}$e=self::read($d);$f=$e['families'];$rows=[];
        if($f['last_complete_at']&&$f['last_complete_at']!==$f['at']){$rows[]=['time'=>$f['last_complete_at'],'event'=>'Registrar read complete','note'=>'All supported record families were checked.','by'=>'System'];}
        if($f['at']){$rows[]=['time'=>$f['at'],'event'=>'Registrar read '.strtolower($e['state']),'note'=>implode('; ',array_map(fn($r)=>$r['label'].': '.$r['value'],$f['rows'])).'. Public DNS is separate.','by'=>'System'];}
        return $rows;
    }
}
