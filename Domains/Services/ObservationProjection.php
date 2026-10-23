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
    public static function save(Domain $d, ?DomainSnapshot $facts, TransferStatus $transfer, \DateTimeImmutable $now): void
    {
        $p=$d->observation??[];
        if ($facts) {
            $p['facts']=['name'=>$facts->name,'owned'=>$facts->owned,'expiry'=>$facts->expiry?->value,
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
        $d->update(['observation'=>$p,'last_synced_at'=>$now]);
    }
    public static function sections(Domain $d): array
    {
        $sections=[]; $glue=[]; $reads=[]; $inventory=ObservationRecords::current($d);
        if ($inventory) {
            foreach (['dns','srv','ds'] as $kind) {
                $f=$inventory['families'][$kind]??[];
                if (($f['state']??null)==='complete') { $sections[$kind]=['at'=>$inventory['at'],'facts'=>['records'=>$f['records']]]; }
            }
            $glue=$inventory['families']['glue']['records']??[];
        }
        $ops=$d->operations()->where('authority_generation',$d->authority_generation)->where('account_fingerprint',$d->account_fingerprint)->orderByDesc('id')->limit(101)->get();
        // Never treat a truncated history as a complete inventory. A fresh observation stands alone.
        if ($ops->count()>100) {
            return ['known_glue'=>$glue];
        }
        foreach ($ops as $op) {
            if ($op->authority_generation!==$d->authority_generation||!isset($op->policy['management'])||!ManagementEvidence::resolved($op,$d)) { continue; }
            foreach ($op->attempt['management_reads']??[] as $read) {
                if (($read['confirmed']??false)!==true || ($inventory && in_array($op->action,['dns','srv','ds_add','ds_delete','glue_add','glue_update','glue_delete'],true) && ($read['sequence']??0)<=$inventory['sequence'])) { continue; }
                $reads[]=['op'=>$op,'read'=>$read];
            }
        }
        usort($reads,static fn($a,$b)=>[($a['read']['sequence']??0),$a['read']['at'],$a['op']->id]<=>[($b['read']['sequence']??0),$b['read']['at'],$b['op']->id]);
        foreach ($reads as $item) {
                $op=$item['op']; $read=$item['read'];
                $kind=match($op->action) {'privacy_purchase','privacy_renew','privacy_enable','privacy_disable'=>'privacy',
                    'ds_add','ds_delete'=>'ds','glue_add','glue_update','glue_delete'=>'glue',default=>$op->action};
                if ($kind==='glue') {
                    $host=$read['facts']['host']??null; if (!$host) { continue; }
                    if (($read['facts']['ip']??null)===null) { unset($glue[$host]); } else { $glue[$host]=$read['facts']['ip']; }
                } else { $sections[$kind]=['operation_key'=>$op->operation_key,'at'=>$read['at'],'facts'=>$read['facts']]; }
        }
        if (count($glue)>50) { unset($sections['dns'],$sections['srv'],$sections['ds']); }
        $sections['known_glue']=$glue; return $sections;
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
