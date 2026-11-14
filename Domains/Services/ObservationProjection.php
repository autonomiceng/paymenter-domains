<?php
// Enom444f31c refresh/follow-up semantics; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\{DomainSnapshot, NameserverEvidence, TransferStatus};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
/** Encrypted facts only. No anchor, operation state, correction closure or financial writer. */
final class ObservationProjection
{
    public static function acquired(Domain $d, string $key, ?NameserverEvidence $nameservers = null): array
    {
        $observation = self::recordNameservers($d, $nameservers);
        return [...$observation,'followup'=>['operation_key'=>$key,'generation'=>$d->authority_generation,'reads'=>0,'state'=>'pending']];
    }
    public static function recordNameservers(Domain $d, ?NameserverEvidence $nameservers): array
    {
        $observation = $d->observation ?? [];
        if ($nameservers) {
            $observation['nameservers'] = $nameservers->toArray() + [
                'name' => $d->domain, 'generation' => $d->authority_generation, 'account_fingerprint' => $d->account_fingerprint,
            ];
        }
        return $observation;
    }
    public static function matches(Domain $d, array $facts): bool
    {
        return ($facts['name'] ?? null) === $d->domain
            && ($facts['generation'] ?? null) === $d->authority_generation
            && ($facts['account_fingerprint'] ?? null) === $d->account_fingerprint;
    }
    public static function registrationNameservers(Domain $d): ?NameserverEvidence
    {
        $stored = $d->observation['nameservers'] ?? null;
        return is_array($stored) && self::matches($d, $stored)
            ? NameserverEvidence::fromArray(array_intersect_key($stored, array_flip(['nameservers', 'state', 'source', 'at']))) : null;
    }
    /** Confirmed management readback first, otherwise the identity-checked registration evidence. */
    public static function nameservers(Domain $d): ?NameserverEvidence
    {
        $section=$d->observation['sections']['nameservers']??null;
        if ($section!==null) {
            if (!is_array($section) || !self::matches($d,$section)) { return null; }
            return NameserverEvidence::fromArray([
                'nameservers'=>$section['facts']['nameservers']??null, 'state'=>'observed',
                'source'=>$d->registrar_key==='enom'?'enom_getdns':'namesilo_domain_info', 'at'=>$section['at']??null,
            ]);
        }
        return self::registrationNameservers($d);
    }
    public static function section(Domain $d, array $read): array
    {
        return ['name'=>$d->domain, 'generation'=>$d->authority_generation, 'account_fingerprint'=>$d->account_fingerprint,
            'operation_key'=>$read['operation_key'], 'at'=>$read['at'], 'facts'=>$read['facts']];
    }
    public static function save(Domain $d, ?DomainSnapshot $facts, TransferStatus $transfer, \DateTimeImmutable $now): void
    {
        $p=$d->observation??[];
        if ($facts) {
            $p['facts']=['name'=>$facts->name,'generation'=>$d->authority_generation,'account_fingerprint'=>$d->account_fingerprint,'owned'=>$facts->owned,'expiry'=>$facts->expiry?->value,
                'precision'=>$facts->expiry?->precision->value,'source'=>$facts->expiry?->source,'timezone'=>$facts->expiry?->timezone,
                'auto_renew'=>$facts->providerAutoRenew,'privacy'=>$facts->privacy,'locked'=>$facts->locked,'active'=>$facts->active,'at'=>$now->format(DATE_ATOM)];
            // Drift is diagnostic; confirmed expiry and the billing anchor never follow observations.
            $p['drift']=$facts->owned!==true||$facts->providerAutoRenew!==false
                || ($facts->expiry!==null&&$d->provider_expires_at!==null&&substr($facts->expiry->value,0,10)!==$d->provider_expires_at->format('Y-m-d'));
            if ($facts->owned===true&&!$p['drift']&&isset($p['followup'])&&$p['followup']['generation']===$d->authority_generation&&$p['followup']['reads']<3) {
                $p['followup']['reads']++; $p['followup']['state']=$p['followup']['reads']>=3?'observed':'pending';
            }
        }
        $p['transfer_status']=$transfer->value;
        $p['corrections']=self::corrections($d);
        $p['sections']=self::sections($d);
        if ($facts?->nameservers) { $p['sections']['nameservers']=self::section($d,['operation_key'=>'observation','at'=>$now->format(DATE_ATOM),'facts'=>['nameservers'=>$facts->nameservers->nameservers]])+['sequence'=>$p['management_sequence']??0]; }
        $d->update(['observation'=>$p,'last_synced_at'=>$now]);
    }
    public static function sections(Domain $d): array
    {
        $sections=[]; $glue=[]; $reads=[]; $inventory=ObservationRecords::current($d);
        $snapshot=$d->observation['sections']['nameservers']??null;
        $snapshot=is_array($snapshot)&&self::matches($d,$snapshot)&&($snapshot['operation_key']??null)==='observation'?$snapshot:null;
        if ($snapshot) { $sections['nameservers']=$snapshot; }
        if ($inventory) {
            foreach (['dns','srv','ds'] as $kind) {
                $f=$inventory['families'][$kind]??[];
                if (($f['state']??null)==='complete') { $sections[$kind]=['at'=>$inventory['at'],'facts'=>['records'=>$f['records']]]; }
            }
            $glue=$inventory['families']['glue']['records']??[];
        }
        $ops=$d->operations()->where('authority_generation',$d->authority_generation)->where('account_fingerprint',$d->account_fingerprint)->orderByDesc('id')->limit(101)->get();
        // Never treat a truncated history as a complete inventory. A fresh observation stands alone.
        $overflow=$ops->count()>100;
        $gap=$overflow && (!$inventory || $inventory['sequence']<($d->observation['management_sequence']??0));
        if ($gap) {
            $sections=[];
            foreach (['nameservers','lock','privacy'] as $kind) {
                $saved=$d->observation['sections'][$kind]??null;
                if (is_array($saved) && self::matches($d,$saved)) { $sections[$kind]=$saved; }
            }
            // Incomplete history cannot prove that acquisition nameservers are still current.
            $sections['nameservers']??=[];
        }
        foreach ($ops->take(100) as $op) {
            if ($overflow && !in_array($op->action,['glue_add','glue_update','glue_delete'],true)) { continue; }
            try {
                if ($op->authority_generation!==$d->authority_generation||!isset($op->policy['management'])||!ManagementEvidence::resolved($op,$d)) { continue; }
                $confirmedReads=ManagementReadRecovery::reads($op);
            } catch (\Illuminate\Contracts\Encryption\DecryptException) {
                // Unreadable history supplies no evidence; ManagementProgress shows its own warning.
                continue;
            }
            foreach ($confirmedReads as $read) {
                if (($read['confirmed']??false)!==true || ($inventory && in_array($op->action,['dns','srv','ds_add','ds_delete','glue_add','glue_update','glue_delete'],true) && ($read['sequence']??0)<=$inventory['sequence'])) { continue; }
                $reads[]=['op'=>$op,'read'=>$read];
            }
        }
        foreach ($d->observation['record_reads']??[] as $read) {
            if (($read['identity']??null)===ObservationRecords::identity($d) && is_string($read['at']??null) && (!$inventory || ($read['at']>=$inventory['at'] && ($read['sequence']??0)>$inventory['sequence']))) { $reads[]=['op'=>(object)['action'=>$read['action'],'id'=>PHP_INT_MAX],'read'=>$read]; }
        }
        $reads=array_values(array_filter($reads,static fn($item)=>is_string($item['read']['at']??null)&&is_array($item['read']['facts']??null)&&is_string($item['read']['operation_key']??null)));
        usort($reads,static fn($a,$b)=>[($a['read']['sequence']??0),$a['read']['at'],$a['op']->id]<=>[($b['read']['sequence']??0),$b['read']['at'],$b['op']->id]);
        foreach ($reads as $item) {
                $op=$item['op']; $read=$item['read'];
                $kind=match($op->action) {'privacy_purchase','privacy_renew','privacy_enable','privacy_disable'=>'privacy',
                    'ds_add','ds_delete'=>'ds','glue_add','glue_update','glue_delete'=>'glue',default=>$op->action};
                if ($kind==='nameservers' && $snapshot && ($read['sequence']??0)<=($snapshot['sequence']??$d->observation['management_sequence']??0)) { continue; }
                if ($kind==='glue') {
                    $sections['glue_at']=$read['at'];
                    $host=$read['facts']['host']??null; if (!$host) { continue; }
                    if (($read['facts']['ip']??null)===null) { unset($glue[$host]); } else { $glue[$host]=$read['facts']['ip']; }
                } else { $sections[$kind]=self::section($d, $read); }
        }
        if ($gap || count($glue)>GlueDiscoveryLimit::HOSTS) { unset($sections['dns'],$sections['srv'],$sections['ds']); }
        $sections['known_glue']=$glue; return $sections;
    }
    /** UI evidence DTO: registrar reads only; public DNS cannot advance these states or times. */
    public static function recordStatus(Domain $d, \DateTimeImmutable $now): array
    {
        $r=ObservationRecords::current($d,$now);$failed=($d->observation['record_failure']['identity']??null)===ObservationRecords::identity($d);
        $read=HoldingReadRetry::status($d,$now);
        $held=ObservationRecords::held($d);
        $read['records_paused']=$read['records_paused']&&!$failed&&!$held;
        // Checking only while the read is due or running; a later scheduled read is a time, not a state.
        $read['read_checking']=$read['next_read']!==null&&$read['next_read']<=$now->getTimestamp()&&!$read['read_code']&&!$read['records_paused']&&!$read['read_overdue']&&!$failed&&!$held;
        $read['read_cause']??=($failed||$held)?'The registrar did not return a complete supported record family.':($read['read_overdue']?'The scheduled registrar read is overdue.':null);
        $out=[...$read,'at'=>$r['at']??null,'last_complete_at'=>$r['last_complete_at']??null,
            'stale'=>$failed||!$r||new \DateTimeImmutable($r['at'])<=$now->modify('-1 day'),
            'resume'=>ObservationRecords::held($d)&&$read['next_read']===null,'failed_family'=>ObservationRecords::held($d)?($d->observation['record_hold']['family']??null):null,'families'=>[],'settings'=>[]];
        foreach (['families'=>RecordFormat::FAMILIES,'settings'=>['nameservers','contacts','flags']] as $group=>$kinds) {
            foreach ($kinds as $kind) {
                $f=$r[$group][$kind]??[];$out[$group][$kind]=[
                    // A legacy snapshot has no settings group: that read never covered them.
                    'expectation'=>$group==='families'?($r['expected_families'][$kind]??'unknown'):(isset($r['settings'])?'supported':'unknown'),
                    'state'=>$failed?'unknown':($f['state']??'unknown'),'at'=>$f['at']??$r['at']??null,
                    'last_good_at'=>$f['last_good']['at']??null,
                    ...array_intersect_key($f,array_flip(['records','facts','last_good','candidates','read_failed']))];
            }
        }
        return $out;
    }
    private static function corrections(Domain $d): array
    {
        $progress=[]; $ops=$d->operations()->where('authority_generation',$d->authority_generation)->orderByDesc('id')->limit(100)->get();
        foreach ($ops as $op) {
            $link=$op->policy['management']['correction_link']??null;
            if (!$link || count($progress)>=100) { continue; }
            $original=$ops->firstWhere('operation_key',$link['operation_key']??null);
            if (!$original || !CorrectionProgress::matches($op,$original,$d)) { continue; }
            $progress[]=['original_key'=>$original->operation_key,'section'=>$link['section'],'operation_key'=>$op->operation_key,
                'state'=>$op->state==='succeeded'&&ManagementEvidence::resolved($op,$d)?'readback_confirmed_awaiting_review':'unresolved'];
        }
        return $progress;
    }
}
