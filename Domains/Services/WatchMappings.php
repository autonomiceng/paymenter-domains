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
    public function save(User $actor, int $productId, string $key, int $expectedRevision, string $reason): WatchProductMap
    {
        $this->authorize($actor);
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
