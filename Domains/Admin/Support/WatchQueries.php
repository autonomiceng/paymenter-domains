<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Domains\Models\WatchRegistryObservation;
use Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites as Writes;
final class WatchQueries
{
    /** Explicit historical filter over encrypted registry facts. Bounded decrypt, no provider reads. */
    public static function expiry(Builder $query,int $days,?callable $scanned=null): Builder
    {
        abort_unless(Staff::can(Writes::VIEW),403);if(!in_array($days,[7,30,90],true)){return $query;}
        $scan=self::expiryScan($query,$days);
        if($scanned!==null){$scanned($scan['capped']);}
        return $query->whereIn('id',$scan['ids']);
    }
    /** @return array{ids:list<int>,capped:bool} Matching IDs and overflow from the same bounded scan. */
    public static function expiryScan(Builder $query,int $days): array
    {
        abort_unless(Staff::can(Writes::VIEW),403);
        if(!in_array($days,[7,30,90],true)){throw new \InvalidArgumentException('Invalid expiry interval.');}
        $ids=[];$today=gmdate('Y-m-d');$end=gmdate('Y-m-d',time()+$days*86400);
        $watchIds=(clone $query)->select($query->getModel()->qualifyColumn('id'));
        $latest=WatchRegistryObservation::selectRaw('max(id)')->where('result','facts')->whereIn('watch_id',$watchIds)->groupBy('watch_id');
        $records=WatchRegistryObservation::whereIn('id',$latest)->whereIn('watch_id',$watchIds)->orderBy('watch_id')->limit(WatchFindings::CAP+1)->get();
        foreach($records->take(WatchFindings::CAP) as $record){try{$date=$record->facts['expiry']??null;if(is_string($date)&&substr($date,0,10)>=$today&&substr($date,0,10)<=$end){$ids[]=$record->watch_id;}}catch(\Throwable){}}
        return ['ids'=>$ids,'capped'=>$records->count()>WatchFindings::CAP];
    }
}
