<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Domains\Models\WatchName;
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainSummary,DomainSummaryTones};
use Paymenter\Extensions\Servers\Domains\Services\{DomainSummaries,StaffWatchWrites as Writes};
/** Staff wording over the customer DomainSummary state keys, so staff and customer read the same evidence. No transport or writes. */
final class WatchFindings
{
    /** Severity order. Colours follow the customer tones; staff.* keys exist only on staff pages. */
    public const LABELS=['staff.unavailable'=>'Summary unavailable','watch.redemption'=>'Registry: redemption or pending delete','watch.expired'=>'Registry expiry reached','watch.ns_changed'=>'Nameservers changed',
        'staff.expiring'=>'Registry expiry within 30 days','watch.not_registered'=>'Registry returned no registration','watch.ns_pending'=>'Nameserver change unconfirmed',
        'watch.stale'=>'Checks out of date','watch.partial'=>'Checks partial','watch.not_checked'=>'Not checked yet','watch.no_coverage'=>'No public registry expiry','staff.stopped'=>'Not evaluated · checks stopped'];
    /** Shown for context only; never counted as needing attention. */
    public const QUIET=['watch.no_coverage','staff.stopped'];
    /** @return array<int,list<string>> keyed by watch id. Staff-batched across owners; each bounded chunk uses the same local projection as the customer portal. */
    public static function batch(iterable $watches,?int $now=null): array
    {
        abort_unless(Staff::can(Writes::VIEW),403);$now??=time();$out=[];$active=[];
        foreach($watches as $w){if($w->state==='active'){$active[]=(int)$w->id;$out[(int)$w->id]=['staff.unavailable'];}else{$out[(int)$w->id]=['staff.stopped'];}}
        foreach(array_chunk($active,250) as $chunk){foreach((new DomainSummaries())->staffWatches($chunk,$now) as $key=>$summary){$out[(int)substr($key,6)]=self::keys($summary,$now);}}
        return $out;
    }
    public static function keys(DomainSummary $s,?int $now=null): array
    {
        $keys=array_intersect($s->stateKeys,array_keys(self::LABELS));
        // The summary only carries a current public expiry; this matches the 30-day customer notice window.
        if($s->expiry && !in_array('watch.expired',$keys,true) && substr($s->expiry,0,10)<=gmdate('Y-m-d',($now??time())+30*86400)){$keys[]='staff.expiring';}
        return array_values(array_intersect(array_keys(self::LABELS),$keys));
    }
    public static function attention(array $keys): bool{return (bool)array_diff($keys,self::QUIET);}
    public static function labels(array $keys): array{return array_map(fn($k)=>self::LABELS[$k],$keys);}
    public static function color(string $label): string
    {
        $key=array_search($label,self::LABELS,true)?:'';
        return match(str_starts_with($key,'staff.')?($key==='staff.expiring'?'warn':'muted'):DomainSummaryTones::for($key)){'ok'=>'success','info'=>'info','warn'=>'warning','bad'=>'danger',default=>'gray'};
    }
    /** Exact attention filter over the first 1,000 active watches; four bounded batches limit decryption and response work. */
    public static function filter(Builder $query,mixed $livewire=null): Builder
    {
        // Filament applies the filter for records and counts alike; the list page memoizes the ids per request.
        return $query->whereIn('id',is_callable([$livewire,'attentionIds'])?$livewire->attentionIds():self::attentionIds());
    }
    /** @return list<int> */
    public static function attentionIds(): array
    {
        abort_unless(Staff::can(Writes::VIEW),403);
        $ids=[];foreach(self::batch(WatchName::where('state','active')->orderBy('id')->limit(1000)->get(['id','user_id','state'])) as $id=>$keys){if(self::attention($keys)){$ids[]=$id;}}
        return $ids;
    }
    /** Native user details only with the native permissions, checked fresh; otherwise the neutral identifier. */
    public static function customers(array $ids): array
    {
        $out=[];foreach($ids as $id){$out[(int)$id]=['label'=>'Customer #'.$id,'email'=>null,'url'=>null];}
        if(!$out || !Staff::can('admin.users.view')){return $out;}$edit=Staff::can('admin.users.update');
        foreach(User::whereIn('id',array_keys($out))->get(['id','first_name','last_name','email']) as $u){
            $name=trim($u->first_name.' '.$u->last_name);$out[(int)$u->id]=['label'=>$name?:$u->email,'email'=>$name?$u->email:null,'url'=>$edit?\App\Admin\Resources\UserResource::getUrl('edit',['record'=>$u->id]):null];
        }
        return $out;
    }
}
