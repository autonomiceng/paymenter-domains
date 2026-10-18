<?php
// Adapted from Enom 444f31c OperationPolicy; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Policies;
use App\Models\User;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
class OperationPolicy
{
    public function viewAny(User $user): bool { return Staff::allows($user); }
    public function view(User $user,Operation $record): bool { return Staff::allows($user); }
    public function create(User $user): bool { return false; }
    public function update(User $user,Operation $record): bool { return false; }
    public function delete(User $user,Operation $record): bool { return false; }
    public function deleteAny(User $user): bool { return false; }
    public function forceDelete(User $user,Operation $record): bool { return false; }
    public function forceDeleteAny(User $user): bool { return false; }
    public function restore(User $user,Operation $record): bool { return false; }
    public function restoreAny(User $user): bool { return false; }
    public function replicate(User $user,Operation $record): bool { return false; }
    public function reorder(User $user): bool { return false; }
}
