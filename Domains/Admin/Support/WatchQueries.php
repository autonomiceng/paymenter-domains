<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Domains\Models\WatchRegistryObservation;
use Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites as Writes;
final class WatchQueries
{
    /** Explicit historical filter over encrypted registry facts. Streaming decrypt, no provider reads. */
    public static function expiry(Builder $query,int $days): Builder
    {
        abort_unless(Staff::can(Writes::VIEW),403);if(!in_array($days,[7,30,90],true)){return $query;}$ids=[];$today=gmdate('Y-m-d');$end=gmdate('Y-m-d',time()+$days*86400);
        $watchIds=(clone $query)->select($query->getModel()->qualifyColumn('id'));
        $latest=WatchRegistryObservation::selectRaw('max(id)')->where('result','facts')->whereIn('watch_id',$watchIds)->groupBy('watch_id');
        foreach(WatchRegistryObservation::whereIn('id',$latest)->whereIn('watch_id',$watchIds)->orderBy('watch_id')->limit(1000)->cursor() as $record){try{$date=$record->facts['expiry']??null;if(is_string($date)&&substr($date,0,10)>=$today&&substr($date,0,10)<=$end){$ids[]=$record->watch_id;}}catch(\Throwable){}}
        return $query->whereIn('id',$ids);
    }
}
