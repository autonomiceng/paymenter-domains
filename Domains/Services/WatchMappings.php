<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Product,User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\{WatchProductMap,WatchAudit};
/** Saving configuration never runs discovery or reads service properties. */
final class WatchMappings
{
    private static bool $enabling=false;
    public static function enabling(): bool { return self::$enabling; }
    public function enable(User $actor,int $id,int $revision,string $reason): WatchProductMap
    {
        $this->authorize($actor);StaffReason::require(trim($reason));
        return DB::transaction(function()use($actor,$id,$revision,$reason){
            $peek=WatchProductMap::findOrFail($id);Product::whereKey($peek->product_id)->lockForUpdate()->firstOrFail();
            $row=WatchProductMap::whereKey($id)->lockForUpdate()->firstOrFail();$this->authorize($actor);
            if($row->revision!==$revision || $row->enabled){throw new \DomainException('Review the saved disabled mapping before enabling it.');}
            self::$enabling=true;try{$row->fill(['enabled'=>true,'revision'=>$revision+1,'edited_by'=>$actor->id,'reason'=>trim($reason)])->save();}finally{self::$enabling=false;}
            WatchAudit::create(['subject_type'=>'product_map','subject_id'=>$id,'actor_id'=>$actor->id,'event'=>'mapping_enabled','reason'=>trim($reason),'before_revision'=>(string)$revision,'after_revision'=>(string)$row->revision,'data'=>[],'created_at'=>now()]);return $row;
        });
    }
    /** Maps a product the tick found, monitored, or corrects a map on another field to the convention; on or off stays as it was. edited_by 0 marks the system. */
    public function adopt(int $productId,string $key): void
    {
        $reason='Domain field found';
        DB::transaction(function()use($productId,$key,$reason):void {
            Product::whereKey($productId)->lockForUpdate()->firstOrFail();
            $map=WatchProductMap::where('product_id',$productId)->lockForUpdate()->first();$before=(int)$map?->revision;
            if($map?->property_key===$key){return;}
            self::$enabling=true;try{$map?$map->fill(['property_key'=>$key,'revision'=>$before+1])->save():$map=WatchProductMap::create(['product_id'=>$productId,'property_key'=>$key,'enabled'=>true,'edited_by'=>0,'reason'=>$reason]);}finally{self::$enabling=false;}
            WatchAudit::create(['subject_type'=>'product_map','subject_id'=>$map->id,'event'=>$before?'mapping_corrected':'mapping_found','reason'=>$reason,'before_revision'=>(string)$before,'after_revision'=>(string)$map->revision,'data'=>['property_key'=>$key],'created_at'=>now()]);
        });
    }
    /** Staff turn hosting products on or off ([product id => on]). Products out of scope are left alone. With $loaded, the
     * off list the page showed, a page that is out of date changes nothing. */
    public function monitor(User $actor,array $changes,?array $loaded=null): void
    {
        $this->authorize($actor);
        DB::transaction(function()use($actor,$changes,$loaded):void {
            $ids=WatchProducts::inScope()->orderBy('id')->lockForUpdate()->pluck('id')->all();$this->authorize($actor);
            $maps=WatchProductMap::whereIn('product_id',$ids)->lockForUpdate()->get()->keyBy('product_id');
            $sorted=fn(array $list)=>collect($list)->map(fn($id)=>(int)$id)->intersect($ids)->unique()->sort()->values()->all();
            if($loaded!==null&&$sorted($maps->where('enabled',false)->keys()->all())!==$sorted($loaded)){throw new \DomainException('The saved mapping changed.');}
            foreach(array_intersect_key($changes,array_flip($ids)) as $id=>$on){
                $map=$maps[$id]??null;$on=(bool)$on;$key=WatchProducts::key((int)$id);
                if($map?(bool)$map->enabled===$on&&$map->property_key===$key:$on){continue;}
                $before=(int)$map?->revision;$reason=$on?'Monitoring turned on':'Monitoring turned off';$map??=new WatchProductMap(['product_id'=>(int)$id]);
                self::$enabling=true;try{$map->fill(['property_key'=>$key,'enabled'=>$on,'revision'=>$before+1,'edited_by'=>$actor->id,'reason'=>$reason])->save();}finally{self::$enabling=false;}
                WatchAudit::create(['subject_type'=>'product_map','subject_id'=>$map->id,'actor_id'=>$actor->id,'event'=>$on?'mapping_enabled':'mapping_disabled','reason'=>$reason,'before_revision'=>(string)$before,'after_revision'=>(string)$map->revision,'data'=>['property_key'=>$key],'created_at'=>now()]);
            }
        });
    }
    public function save(User $actor, int $productId, string $key, int $expectedRevision, string $reason): WatchProductMap
    {
        $this->authorize($actor);StaffReason::require(trim($reason));
        return DB::transaction(function () use ($actor, $productId, $key, $expectedRevision, $reason): WatchProductMap {
            Product::whereKey($productId)->lockForUpdate()->firstOrFail(); $this->authorize($actor);
            $map = WatchProductMap::where('product_id', $productId)->lockForUpdate()->first();
            if (($map?->revision ?? 0) !== $expectedRevision) { throw new \DomainException('The saved mapping changed.'); }
            $before = $map?->revision ?? 0;
            $map ??= new WatchProductMap(['product_id' => $productId]);
            $map->fill(['property_key' => $key, 'enabled' => false, 'revision' => $before + 1, 'edited_by' => $actor->id, 'reason' => trim($reason)])->save();
            WatchAudit::create(['subject_type' => 'product_map', 'subject_id' => $map->id, 'actor_id' => $actor->id, 'event' => 'mapping_saved', 'reason' => trim($reason),
                'before_revision' => (string) $before, 'after_revision' => (string) $map->revision, 'data' => ['property_key' => $key, 'enabled' => false], 'created_at' => now()]);
            return $map;
        });
    }
    private function authorize(User $actor): void
    {
        if (!Staff::allows($actor, 'admin.domains.watch.view') || !Staff::allows($actor, 'admin.domains.watch.manage')) { abort(403); }
    }
}
