<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\{Server, User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Models\{Account, ContactProfile};

/** Internal evidence sink, not a provider verifier. No customer-supplied IDs or account inventory adoption. */
final class ContactProfiles
{
    /** Slice 20/30 must prove fresh contactAdd + non-default readback BEFORE calling this sink. */
    public static function recordCreated(AccountBinding $binding, int $ownerId, #[\SensitiveParameter] string $providerId,
        #[\SensitiveParameter] array $contact, bool $defaultProfile, ?array $registrationProof = null): ContactProfile
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Profile recording owns its transaction.'); }
        if ($binding->registrarKey !== 'namesilo' || $defaultProfile || !preg_match('/^[1-9][0-9]{0,19}$/D', $providerId)) {
            throw new \DomainException('Contact profile evidence unavailable.');
        }
        $clean = ContactDetails::validate('namesilo', array_fill_keys(ContactDetails::ROLES, $contact))['registrant'];
        try {
            return DB::transaction(static function () use ($binding, $ownerId, $providerId, $clean, $registrationProof): ContactProfile {
                User::whereKey($ownerId)->lockForUpdate()->firstOrFail();
                $server = Server::whereKey($binding->serverId)->lockForUpdate()->firstOrFail();
                $account = Account::where('server_id', $server->id)->lockForUpdate()->firstOrFail();
                $binding->requireMatch(CatalogueAcceptances::binding($account));
                if ($server->extension !== 'Domains' || !$server->enabled) { throw new \DomainException('Contact account unavailable.'); }
                // Retained first row anchors a random index key, independent of APP_KEY rotation.
                // Missing decryption keys or corrupt anchors refuse recording rather than alias ownership.
                $anchor = ContactProfile::where('server_id', $server->id)->orderBy('id')->lockForUpdate()->first();
                $indexKey = $anchor ? ($anchor->details['index_key'] ?? null) : bin2hex(random_bytes(32));
                if (!is_string($indexKey) || !preg_match('/^[a-f0-9]{64}$/D', $indexKey)
                    || ($anchor && !hash_equals($anchor->profile_fingerprint,
                        hash_hmac('sha256', $anchor->details['provider_id'], $indexKey)))) {
                    throw new \DomainException('Contact profile index unavailable.');
                }
                $details = ['provider_id' => $providerId, 'contact' => $clean, 'index_key' => $indexKey];
                if ($registrationProof !== null) { $details['registration_proof'] = $registrationProof; }
                $hash = hash_hmac('sha256', $providerId, $indexKey);
                $existing = ContactProfile::where(['server_id' => $server->id, 'profile_fingerprint' => $hash])->lockForUpdate()->first();
                if ($existing) {
                    if ((int) $existing->user_id !== $ownerId || $existing->account_fingerprint !== $binding->fingerprint
                        || $existing->details !== $details) { throw new \DomainException('Contact profile is already assigned.'); }
                    return $existing;
                }
                return ContactProfile::recording(static fn () => ContactProfile::create(['user_id' => $ownerId,
                    'server_id' => $server->id, 'account_fingerprint' => $binding->fingerprint, 'profile_fingerprint' => $hash,
                    'details' => $details]));
            });
        } catch (\Throwable) { throw new \DomainException('Contact profile could not be recorded.'); }
    }

    /** Server-only wire IDs; resolve each role by LOCAL profile ID plus current owner/account. */
    public static function roles(AccountBinding $binding, int $ownerId, array $localIds): array
    {
        if ($binding->registrarKey !== 'namesilo' || array_diff(array_keys($localIds), ContactDetails::ROLES)
            || array_diff(ContactDetails::ROLES, array_keys($localIds))) { throw new \DomainException('Contact roles unavailable.'); }
        $result = [];
        foreach (['registrant' => 'registrant', 'admin' => 'administrative', 'tech' => 'technical', 'auxbilling' => 'billing'] as $role => $wire) {
            if (!is_int($localIds[$role]) || $localIds[$role] < 1) { throw new \DomainException('Invalid local contact profile.'); }
            $profile = ContactProfile::whereKey($localIds[$role])->where('server_id', $binding->serverId)
                ->where('account_fingerprint', $binding->fingerprint)->where('user_id', $ownerId)->firstOrFail();
            $result[$wire] = $profile->details['provider_id'];
        }
        return $result;
    }
}
