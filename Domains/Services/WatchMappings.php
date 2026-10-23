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
        $this->authorize($actor);
        return DB::transaction(function()use($actor,$id,$revision,$reason){
            $peek=WatchProductMap::findOrFail($id);Product::whereKey($peek->product_id)->lockForUpdate()->firstOrFail();
            $row=WatchProductMap::whereKey($id)->lockForUpdate()->firstOrFail();$this->authorize($actor);
            if($row->revision!==$revision || $row->enabled || strlen(trim($reason))<5){throw new \DomainException('Review the saved disabled mapping before enabling it.');}
            if($row->expected_ns_profile_id && !\Paymenter\Extensions\Servers\Domains\Models\WatchProfile::whereKey($row->expected_ns_profile_id)->where('enabled',true)->lockForUpdate()->exists()){throw new \DomainException('Choose an enabled routing profile first.');}
            self::$enabling=true;try{$row->fill(['enabled'=>true,'revision'=>$revision+1,'edited_by'=>$actor->id,'reason'=>trim($reason)])->save();}finally{self::$enabling=false;}
            WatchAudit::create(['subject_type'=>'product_map','subject_id'=>$id,'actor_id'=>$actor->id,'event'=>'mapping_enabled','reason'=>trim($reason),'before_revision'=>(string)$revision,'after_revision'=>(string)$row->revision,'data'=>[],'created_at'=>now()]);return $row;
        });
    }
    public function save(User $actor, int $productId, string $key, int $expectedRevision, string $reason, ?int $profileId = null): WatchProductMap
    {
        $this->authorize($actor);
        $withProfile=func_num_args()>=6;
        return DB::transaction(function () use ($actor, $productId, $key, $expectedRevision, $reason, $profileId, $withProfile): WatchProductMap {
            Product::whereKey($productId)->lockForUpdate()->firstOrFail(); $this->authorize($actor);
            $map = WatchProductMap::where('product_id', $productId)->lockForUpdate()->first();
            if (($map?->revision ?? 0) !== $expectedRevision) { throw new \DomainException('The saved mapping changed.'); }
            $before = $map?->revision ?? 0;
            $map ??= new WatchProductMap(['product_id' => $productId]);
            $map->fill(['property_key' => $key, 'expected_ns_profile_id' => $withProfile?$profileId:$map->expected_ns_profile_id, 'enabled' => false, 'revision' => $before + 1, 'edited_by' => $actor->id, 'reason' => trim($reason)])->save();
            WatchAudit::create(['subject_type' => 'product_map', 'subject_id' => $map->id, 'actor_id' => $actor->id, 'event' => 'mapping_saved', 'reason' => trim($reason),
                'before_revision' => (string) $before, 'after_revision' => (string) $map->revision, 'data' => ['property_key' => $key, 'expected_ns_profile_id' => $profileId, 'enabled' => false], 'created_at' => now()]);
            return $map;
        });
    }
    private function authorize(User $actor): void
    {
        if (!Staff::allows($actor, 'admin.domains.watch.view') || !Staff::allows($actor, 'admin.domains.watch.manage')) { abort(403); }
    }
}
