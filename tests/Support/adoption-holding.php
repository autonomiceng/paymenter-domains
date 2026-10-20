<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic storage promotion, no confirmer.
declare(strict_types=1);
require_once __DIR__.'/adoption-preview.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Adoption;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingAdoptionSchema, HoldingIdentityLock};
function adoptionHoldingFixture(): array
{
    $f = adoptionFixture(); extract($f); HoldingAdoptionSchema::install();
    $d = adoptionPendingFixture($service, $binding, ['reservation_name' => 'example.com']);
    $preview = $reader->preview($target, $context);
    same('ready', $preview->code, 'Synthetic preview');
    $a = Adoption::findOrFail($preview->previewId);
    DB::transaction(fn () => $a->fill(['state' => 'committed', 'authority_generation' => 2, 'committed_at' => $clock->now()])->save());
    return [...$f, 'domain' => $d, 'adoption' => $a->fresh()];
}
function attachHolding(array $f): void
{
    DB::transaction(function () use ($f): void {
        $lock = HoldingIdentityLock::lockForAdoption($f['order']->id, $f['service']->id);
        $f['domain']->attachAdoption($lock, $f['adoption']->id);
    });
}
function holdingRefused(callable $action, string $label): void
{
    $refused = false;
    try { $action(); } catch (DomainException|LogicException|InvalidArgumentException $e) { $refused = true; }
    expect($refused, $label);
}
