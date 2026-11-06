<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\{ObservationProjection,ObservationRecords};
/** No inferred completeness or edit grants. Last-good evidence remains historical after a failed read. */
final class RecordFamilies
{
    /** What separate public and registrar checks mean for staff. The value beside it already says "Separate checks". */
    public const EVIDENCE='Public DNS can’t confirm a registrar change or renewal.';
    public static function owner(OwnedDomain $page,array $facts): OwnedDomain
    {
        $management=$page->management;
        foreach($facts['rows'] as $family){$key=$family['kind'];
            $section=$management[$key]??['label'=>$family['label'],'actions'=>[],'url'=>null,'family_only'=>true];
            $section['family']=$family;
            // Missing inventory must not revoke controls already granted by the native management projection.
            if(!$facts['current_read']){$management[$key]=$section;continue;}
            if($family['state']!=='complete'){
                // Keep already authorized Enom per-host additions and edits; a partial list grants no replacement.
                $section['actions']=$key==='glue'&&($facts['registrar_key']??null)==='enom'&&$family['state']==='partial'?array_intersect_key($section['actions'],array_flip(['glue_add','glue_update','glue_delete'])):[];
                $section['family_only']=$section['actions']===[];
            }
            if($key==='dns'){$section['inventory']=$facts['families']['dns']['records']??[];$section['caa']=$facts['caa'];$section['caa_at']=$facts['caa_at'];
                $section['fixed']=[...array_values(array_filter($section['fixed']??[],fn($r)=>($r['type']??null)!=='CAA')),...array_map(fn($r)=>['name'=>$r['name'],'type'=>'CAA','address'=>$r['value'],'ttl'=>$r['ttl']],$facts['caa'])];}
            $management[$key]=$section;
        }
        return new OwnedDomain(...[...get_object_vars($page),'management'=>$management]);
    }
    public static function read(Domain $d,\DateTimeImmutable $now): array
    {
        $readable=true;
        try{$status=ObservationProjection::recordStatus($d,$now);$hold=$d->observation['record_hold']['reason']??null;
            $currentRead=$status['at']!==null&&($d->observation['record_failure']['identity']??null)!==ObservationRecords::identity($d);}
        catch(\Illuminate\Contracts\Encryption\DecryptException){$readable=false;$empty=clone $d;$empty->observation=[];$status=ObservationProjection::recordStatus($empty,$now);$hold=null;$currentRead=false;}
        $cancelled=false;
        if($readable&&$d->status==='cancelled'&&empty($d->observation['records'])&&$status['at']===null&&$d->provider_domain_id===null&&$d->provider_order_id===null&&$d->provider_expires_at===null){
            $ops=$d->operations;
            $cancelled=$ops->count()===1&&\Paymenter\Extensions\Servers\Domains\Services\UnpaidAcquisitionProof::terminal($ops->first(),$d);
        }
        // Reuse the editor's identity-checked, sequence-ordered readback projection.
        if($currentRead){$dns=ObservationProjection::sections($d)['dns']??null;
            if(isset($dns['facts']['records'],$dns['at'])&&$dns['at']>=($status['families']['dns']['at']??'')){
                $status['families']['dns']=[...$status['families']['dns'],'records'=>$dns['facts']['records'],'state'=>'complete','at'=>$dns['at']];
            }
        }
        $rows=[];$caa=[];$caaAt=null;
        foreach(['settings'=>['nameservers'=>'Nameservers','contacts'=>'Contacts','flags'=>'Domain settings'],'families'=>['dns'=>'DNS records','srv'=>'SRV records','ds'=>'DS records','glue'=>'Glue records']] as $group=>$labels){
            foreach($labels as $kind=>$label){$f=$status[$group][$kind];$state=$f['expectation']==='unsupported'?'unsupported':$f['state'];
                $count=count($f['records']??($f['facts']['nameservers']??$f['facts']['contacts']??$f['facts']??[]));
                $unit=match($kind){'nameservers'=>'nameservers','contacts'=>'contacts','flags'=>'settings',default=>'records'};if($count===1){$unit=rtrim($unit,'s');}
                $roles=$kind==='contacts'?array_values(array_intersect(['registrant','admin','tech','billing'],array_keys($f['facts']['contacts']??$f['facts']??[]))):[];
                $returned=$roles?implode(', ',array_map('ucfirst',$roles)).' returned; some contacts did not. ':$count.' '.$unit.' returned; some did not. ';
                $checked=$count.' '.$unit.' checked.';
                // A stopped read won't refresh anything; the page notice names Resume sync.
                $next=($status['read_stopped']??false)?'':($kind==='contacts'?'Refresh contacts before editing.':($kind==='nameservers'?'Refresh nameservers before editing.':'Sync this domain before editing.'));
                $rows[]=[...array_intersect_key($status,array_flip(['next_read','read_checking','read_cause','records_paused'])),'count'=>$count,'kind'=>$kind,'label'=>$label,'state'=>$state,'value'=>ucfirst($state),'at'=>$f['at'],'last_good_at'=>$f['last_good_at'],
                    'customer'=>match($state){'complete'=>$checked,'partial'=>$count.' '.$unit.' read. We’re checking the rest. Editing will return when the check finishes.','unsupported'=>'These records cannot be read here.',default=>'We’re checking these records.'},
                    'staff'=>match($state){'complete'=>$checked,'partial'=>$returned.$next.' Valid siblings are retained; whole-list editing stays blocked.','unsupported'=>'No supported read contract. This is not an empty record list.',default=>'No current complete read. Sync this domain.'}];
                $index=array_key_last($rows);
                if($cancelled){$rows[$index]['value']='Not applicable';$rows[$index]['staff']=$rows[$index]['customer']='Registration cancelled; no registrar records to check';}
                elseif($kind==='glue'&&$d->registrar_key==='enom'&&$state==='partial'){
                    $coverage=count($f['candidates']??[]).' known child hosts, '.$count.' records returned.';
                    $explanation=($f['read_failed']??false)?'Known glue check failed. Saved records are retained':"Known glue checked. This registrar can't list every child host";
                    $rows[$index]['staff']=$explanation.'. '.$coverage.' You can change one glue record at a time. Replacing the whole list stays blocked.';
                    $rows[$index]['customer']=$explanation.'. '.$coverage.' You can change one glue record at a time.';
                }
                if($kind==='dns'){
                    $records=$f['records']??[];
                    if($records&&$state==='unknown'){$caaAt=$f['at'];}
                    if(!$records&&$state!=='complete'){$records=$f['last_good']['records']??[];$caaAt=$f['last_good_at'];}
                    foreach($records as $record){
                        if(($record['type']??null)!=='CAA'){continue;}
                        $caa[]=['name'=>$record['name']??'@','type'=>'CAA','value'=>$record['address']??'','ttl'=>$record['ttl']??null];
                    }
                }
            }
        }
        if($cancelled){$status['stale']=false;}
        return [...$status,'registrar_key'=>$d->registrar_key,'cancelled_without_records'=>$cancelled,'current_read'=>$currentRead,'rows'=>$rows,'caa'=>$caa,'caa_at'=>$caaAt,'staff_hold'=>match($hold){'budget_exhausted','call_cap'=>'Account call limit reached.','caa_unsupported'=>'CAA records cannot be read from Enom here. Manage DNS at Enom.',default=>null}];
    }
}
