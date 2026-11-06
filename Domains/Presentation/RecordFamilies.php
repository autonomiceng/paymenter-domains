<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\{ObservationProjection,ObservationRecords};
/** No inferred completeness or edit grants. Last-good evidence remains historical after a failed read. */
final class RecordFamilies
{
    public const EVIDENCE='Public DNS checks and registrar records are separate observations. Public DNS cannot confirm a registrar change or a renewal.';
    public static function owner(OwnedDomain $page,array $facts): OwnedDomain
    {
        $management=$page->management;
        foreach($facts['rows'] as $family){$key=$family['kind'];
            $section=$management[$key]??['label'=>$family['label'],'actions'=>[],'url'=>null,'family_only'=>true];
            $section['family']=$family;
            // Missing inventory must not revoke controls already granted by the native management projection.
            if(!$facts['current_read']){$management[$key]=$section;continue;}
            if($family['state']!=='complete'){$section['actions']=$key==='glue'?array_intersect_key($section['actions'],['glue_add'=>true]):[];$section['family_only']=$section['actions']===[];}
            if($key==='dns'){$section['inventory']=$facts['families']['dns']['records']??[];$section['caa']=$facts['caa'];$section['caa_at']=$facts['caa_at'];
                $section['fixed']=[...array_values(array_filter($section['fixed']??[],fn($r)=>($r['type']??null)!=='CAA')),...array_map(fn($r)=>['name'=>$r['name'],'type'=>'CAA','address'=>$r['value'],'ttl'=>$r['ttl']],$facts['caa'])];}
            $management[$key]=$section;
        }
        return new OwnedDomain(...[...get_object_vars($page),'management'=>$management]);
    }
    public static function read(Domain $d,\DateTimeImmutable $now): array
    {
        try{$status=ObservationProjection::recordStatus($d,$now);$hold=$d->observation['record_hold']['reason']??null;
            $currentRead=$status['at']!==null&&($d->observation['record_failure']['identity']??null)!==ObservationRecords::identity($d);}
        catch(\Illuminate\Contracts\Encryption\DecryptException){$empty=clone $d;$empty->observation=[];$status=ObservationProjection::recordStatus($empty,$now);$hold=null;$currentRead=false;}
        $rows=[];$caa=[];$caaAt=null;
        foreach(['settings'=>['nameservers'=>'Nameservers','contacts'=>'Contacts','flags'=>'Domain settings'],'families'=>['dns'=>'DNS records','srv'=>'SRV records','ds'=>'DS records','glue'=>'Glue records']] as $group=>$labels){
            foreach($labels as $kind=>$label){$f=$status[$group][$kind];$state=$f['expectation']==='unsupported'?'unsupported':$f['state'];
                $count=count($f['records']??($f['facts']['nameservers']??$f['facts']['contacts']??$f['facts']??[]));
                $unit=match($kind){'nameservers'=>'nameservers','contacts'=>'contacts','flags'=>'settings',default=>'records'};
                $roles=$kind==='contacts'?array_values(array_intersect(['registrant','admin','tech','billing'],array_keys($f['facts']['contacts']??$f['facts']??[]))):[];
                $returned=$roles?implode(', ',array_map('ucfirst',$roles)).' returned; some contacts did not. ':$count.' '.$unit.' returned; some did not. ';
                $checked=$count.' '.$unit.' checked.';
                $next=$kind==='contacts'?'Refresh contacts before editing.':($kind==='nameservers'?'Refresh nameservers before editing.':'Sync this domain before editing.');
                $rows[]=['count'=>$count,'kind'=>$kind,'label'=>$label,'state'=>$state,'value'=>ucfirst($state),'at'=>$f['at'],'last_good_at'=>$f['last_good_at'],
                    'customer'=>match($state){'complete'=>$checked,'partial'=>$count.' '.$unit.' read. We’re checking the rest. Editing will return when the check finishes.','unsupported'=>'These records cannot be read here.',default=>'We’re checking these records.'},
                    'staff'=>match($state){'complete'=>$checked,'partial'=>$returned.$next.' Valid siblings are retained; whole-list editing stays blocked.','unsupported'=>'No supported read contract. This is not an empty record list.',default=>'No current complete read. Sync this domain.'}];
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
        return [...$status,'current_read'=>$currentRead,'rows'=>$rows,'caa'=>$caa,'caa_at'=>$caaAt,'staff_hold'=>match($hold){'budget_exhausted','call_cap'=>'Account call limit reached.','caa_unsupported'=>'CAA records cannot be read from Enom here. Manage DNS at Enom.',default=>null}];
    }
}
