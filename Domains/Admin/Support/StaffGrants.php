<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
/** Server-only render facts. Capture afresh for each table build; never retain on a component. */
final readonly class StaffGrants
{
    private function __construct(public bool $costs, public bool $metadata) {}
    public static function current(): self
    {
        $user=Auth::user();
        $current=$user instanceof User ? User::with('role')->find($user->getKey()) : null;
        abort_unless($current!==null && $current->hasPermission(Staff::VIEW),403);
        return new self($current->hasPermission(Staff::COSTS),$current->hasPermission(Staff::METADATA));
    }
}
