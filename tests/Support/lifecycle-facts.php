<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Services\LifecyclePolicies;
function lifecycleFixture(\DateTimeImmutable $at): array
{
    return ['grace_days'=>30,'restore_days'=>30,'grace_supported'=>true,'restore_supported'=>true,'auto_renew_only'=>false,'conflicting'=>false,
        'verified_at'=>$at->format(DATE_ATOM),'source'=>'Synthetic verified post-expiry policy',
        'grace_extra'=>'0.0000','grace_currency'=>'USD','grace_verified_at'=>$at->format(DATE_ATOM),'grace_source'=>'Synthetic verified account grace cost',
        'restore_fee'=>'80.0000','restore_currency'=>'USD','restore_verified_at'=>$at->format(DATE_ATOM),'restore_source'=>'Synthetic verified account restore fee'];
}
function storeLifecycleFixture(AccountBinding $b,array $facts,string $tld='com'): void
{
    App\Models\Server::findOrFail($b->serverId)->settings()->updateOrCreate(['key'=>LifecyclePolicies::key($tld)],
        ['encrypted'=>true,'type'=>'string','value'=>json_encode(['binding'=>$b->fingerprint,'tld'=>$tld,'facts'=>$facts],JSON_THROW_ON_ERROR)]);
}
