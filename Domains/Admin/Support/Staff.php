<?php
// Adapted from Enom 444f31c Admin/Support/Staff; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
/** Dormant permission catalogue. Gate39 registers these with Paymenter's permissions event. */
final class Staff
{
    public const ADOPT_PREVIEW = 'admin.domains.adopt.preview';
    public const VIEW = 'admin.domains.view';
    public const COSTS = 'admin.domains.costs';
    public const METADATA = 'admin.domains.secrets-metadata';
    public const POLICY = 'admin.domains.manage-policy';
    public static function permissions(): array
    {
        $permissions = [self::ADOPT_PREVIEW => 'Preview domain adoption', self::VIEW => 'View domains', self::COSTS => 'View domain costs',
            self::METADATA => 'View domain secret metadata', self::POLICY => 'Manage domain policy',
            'admin.domains.watch.view' => 'View domain watch and public suffix list',
            'admin.domains.watch.manage' => 'Manage domain watch and public suffix list',
            'admin.domains.manage' => 'Manage domain operations', 'admin.domains.fund' => 'Fund domain renewals'];
        foreach(\Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::ACTIONS as $action=>$label){
            $permissions[\Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::permission($action)]=$label;
        }
        foreach(\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::ACTIONS as $action=>$label){
            $permissions[\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::permission($action)]=$label;
        }
        foreach(\Paymenter\Extensions\Servers\Domains\Services\StaffHoldingReview::ACTIONS as $action=>$label){
            $permissions[\Paymenter\Extensions\Servers\Domains\Services\StaffHoldingReview::permission($action)]=$label;
        }
        return $permissions;
    }
    public static function allows(?User $user, string $permission = self::VIEW): bool
    {
        // Reload the role: long-lived components must not retain revoked rights.
        $current = $user ? User::with('role')->find($user->getKey()) : null;
        return $current !== null && $current->hasPermission(self::VIEW) && $current->hasPermission($permission);
    }
    public static function can(string $permission = self::VIEW): bool
    {
        $user = Auth::user();
        return self::allows($user instanceof User ? $user : null, $permission);
    }
    public static function requireView(): void { abort_unless(self::can(), 403); }
}
