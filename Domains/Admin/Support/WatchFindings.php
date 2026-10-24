<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels;
use Paymenter\Extensions\Servers\Domains\Models\WatchName;
use Paymenter\Extensions\Servers\Domains\Presentation\DomainSummary;
use Paymenter\Extensions\Servers\Domains\Services\{DomainSummaries,StaffWatchWrites as Writes};
/** Staff monitored domain status over the customer DomainSummary state keys, so staff and customer read the same evidence. No transport or writes. */
final class WatchFindings
{
    /** State keys staff pages read, in severity order; staff.* keys exist only on staff pages. */
    public const KEYS=['staff.unavailable','watch.redemption','watch.expired','watch.ns_changed','staff.expiring','watch.not_registered','watch.ns_pending','watch.stale','watch.partial','watch.not_checked','watch.no_coverage','staff.stopped'];
    /** Keys that make a monitored domain "Problem found", with the reason line; null reads the days to expiry. */
    public const PROBLEMS=['staff.unavailable'=>"Can't read saved data",'watch.redemption'=>'Redemption','watch.expired'=>'Expired','watch.ns_changed'=>'Nameservers changed','staff.expiring'=>null,'watch.not_registered'=>'Not registered'];
    /** Status filter options; Monitoring and Problem found read the first CAP active names. */
    public const STATUSES=['monitoring'=>'Monitoring','problem'=>'Problem found','paused'=>'Paused','stopped'=>'Stopped'];
    public const CAP=1000;
    /** @return array<int,list<string>> keyed by watch id. */
    public static function batch(iterable $watches,?int $now=null): array{return array_map(fn($r)=>$r['keys'],self::summaries($watches,$now));}
    /** @return array<int,array{keys:list<string>,summary:?DomainSummary}> keyed by watch id. Staff-batched across owners; each bounded chunk uses the same local projection as the customer portal. */
    public static function summaries(iterable $watches,?int $now=null): array
    {
        abort_unless(Staff::can(Writes::VIEW),403);$now??=time();$out=[];$active=[];
        foreach($watches as $w){if($w->state==='active'){$active[]=(int)$w->id;$out[(int)$w->id]=['keys'=>['staff.unavailable'],'summary'=>null];}else{$out[(int)$w->id]=['keys'=>['staff.stopped'],'summary'=>null];}}
        foreach(array_chunk($active,250) as $chunk){foreach((new DomainSummaries())->staffWatches($chunk,$now) as $key=>$summary){$out[(int)substr($key,6)]=['keys'=>self::keys($summary,$now),'summary'=>$summary];}}
        return $out;
    }
    public static function keys(DomainSummary $s,?int $now=null): array
    {
        $keys=array_intersect($s->stateKeys,self::KEYS);
        // The summary only carries a current public expiry; this matches the 30-day customer notice window.
        if($s->expiry && !in_array('watch.expired',$keys,true) && substr($s->expiry,0,10)<=gmdate('Y-m-d',($now??time())+30*86400)){$keys[]='staff.expiring';}
        return array_values(array_intersect(self::KEYS,$keys));
    }
    /** The monitored domain status set: label, tone and reason line. */
    public static function status(WatchName $w,array $keys,?string $expiry=null,?int $now=null): array
    {
        if($w->state==='paused'){return ['label'=>'Paused','tone'=>'gray','reason'=>null];}
        if($w->state!=='active'){return ['label'=>'Stopped','tone'=>'gray','reason'=>$w->state==='linked'?(DnsSignalLabels::company() !== '' ? 'Registered at '.self::company() : 'Registered with us'):null];}
        foreach(self::PROBLEMS as $key=>$reason){if(in_array($key,$keys,true)){return ['label'=>'Problem found','tone'=>'warning','reason'=>$reason??self::expires($expiry,$now)];}}
        return ['label'=>'Monitoring','tone'=>'success','reason'=>null];
    }
    public static function expires(?string $expiry,?int $now=null): ?string
    {
        if(!$expiry){return null;}$days=intdiv(strtotime(substr($expiry,0,10).' 00:00:00 UTC')-strtotime(gmdate('Y-m-d',$now??time()).' 00:00:00 UTC'),86400);
        $n=abs($days).' '.(abs($days)===1?'day':'days');return match(true){$days<0=>'Expired '.$n.' ago',$days===0=>'Expires today',default=>'Expires in '.$n};
    }
    /** The configured company name, as the customer pages show it. */
    public static function company(): string{return DnsSignalLabels::company() ?: 'Us';}
    /** Company / "Other" for a saved platform / external value; null stays null so the page shows Missing. */
    public static function who(?string $value): ?string{return match($value){'platform'=>self::company(),'external'=>'Other',default=>null};}
    /** Status filter. Paused and Stopped are saved states; Monitoring and Problem found use the ids the list page memoizes per request. */
    public static function filter(Builder $query,?string $status,mixed $livewire=null): Builder
    {
        return match($status){'paused'=>$query->where('state','paused'),'stopped'=>$query->whereIn('state',['retired','linked']),
            'monitoring','problem'=>$query->whereIn('id',is_callable([$livewire,'statusIds'])?$livewire->statusIds($status):self::statusIds($status)),default=>$query};
    }
    /** @return list<int> Active names with this status among the first CAP; four bounded batches limit decryption and response work. */
    public static function statusIds(string $status): array
    {
        abort_unless(Staff::can(Writes::VIEW),403);
        $ids=[];foreach(self::batch(WatchName::where('state','active')->orderBy('id')->limit(self::CAP)->get(['id','user_id','state'])) as $id=>$keys){if(($status==='problem')===(bool)array_intersect($keys,array_keys(self::PROBLEMS))){$ids[]=$id;}}
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
    /** Customer filter: an id always; name or email only for roles that may read user details. */
    public static function customer(Builder $query,?string $term): Builder
    {
        $term=trim((string)$term);if($term===''){return $query;}if(ctype_digit($term)){return $query->where('user_id',(int)$term);}
        if(!Staff::can('admin.users.view')){return $query->whereRaw('1=0');}$like='%'.addcslashes($term,'%_\\').'%';
        return $query->whereIn('user_id',User::where(fn($q)=>$q->where('email','like',$like)->orWhere('first_name','like',$like)->orWhere('last_name','like',$like))->select('id'));
    }
}
