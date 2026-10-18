<?php
// Adapted from Enom 444f31c Policies/DomainPolicy; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Policies;
use App\Models\User;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
/** Gate39 binds this policy; the dormant resource also checks it explicitly. No writes in35. */
class DomainPolicy
{
    public function viewAny(User $user): bool { return Staff::allows($user); }
    public function view(User $user, Domain $record): bool { return Staff::allows($user); }
    public function viewCosts(User $user, Domain $record): bool { return Staff::allows($user, Staff::COSTS); }
    public function viewSecretsMetadata(User $user, Domain $record): bool { return Staff::allows($user, Staff::METADATA); }
    public function managePolicy(User $user, Domain $record): bool { return Staff::allows($user, Staff::POLICY); }
    public function create(User $user): bool { return false; }
    public function update(User $user, Domain $record): bool { return false; }
    public function delete(User $user, Domain $record): bool { return false; }
    public function deleteAny(User $user): bool { return false; }
    public function forceDelete(User $user, Domain $record): bool { return false; }
    public function forceDeleteAny(User $user): bool { return false; }
    public function restore(User $user, Domain $record): bool { return false; }
    public function restoreAny(User $user): bool { return false; }
    public function replicate(User $user, Domain $record): bool { return false; }
    public function reorder(User $user): bool { return false; }
}
