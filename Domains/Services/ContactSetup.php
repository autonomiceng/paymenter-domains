<?php
// Adapted from Enom 444f31c Services/RegistrationSetup.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\User;
use Paymenter\Extensions\Servers\Domains\Models\{Operation, Tld};

final class ContactSetup
{
    private static bool $recovering = false;

    /** Called synchronously at invoice binding using the locked checkout owner and TLD. */
    public static function capture(User $owner, Tld $tld): array
    {
        $contacts = ContactDetails::collect($owner); $fields = [];
        try { $contacts = ContactDetails::validate($tld->registrar_key, $contacts); }
        catch (ContactValidation $error) { $fields = $error->fields; }
        $registry = RegistryInputs::supported($tld->registrar_key, $tld->tld, $tld->registry_fields);
        return ['contacts' => $contacts, 'registry' => [], 'registry_schema' => $tld->registry_fields,
            'setup_required' => $fields !== [] || !$registry, 'setup_fields' => $fields, 'registry_supported' => $registry];
    }

    /** Revalidate the frozen desired state, never silently refresh from a native account. */
    public static function validate(Operation $operation): void
    {
        if (!in_array($operation->action, ['register', 'transfer', 'contacts'], true)) { throw new \DomainException('Contact action unavailable.'); }
        ContactDetails::validate($operation->registrar_key, $operation->payload['contacts'] ?? null);
        RegistryInputs::validate($operation->registrar_key, $operation->domain->tld,
            $operation->payload['registry_schema'] ?? null, $operation->payload['registry'] ?? null);
    }

    /** Mandatory acquisition claim gate in 19/20, under its current ordered locks. Queued is not ready. */
    public static function requireReady(Operation $operation): void
    {
        if (($operation->payload['setup_required'] ?? null) !== false || ($operation->payload['registry_supported'] ?? null) !== true) {
            throw new \DomainException('Contact setup is incomplete.');
        }
        self::validate($operation);
    }

    public static function guardSnapshot(#[\SensitiveParameter] Operation $operation): void
    {
        if (!self::$recovering && $operation->getOriginal('safe_code') === 'contact_details_required'
            && $operation->isDirty(['state', 'safe_code'])) {
            throw new \DomainException('Only contact recovery can release the contact hold.');
        }
        $old = $operation->getOriginal('payload');
        if (!is_array($old) || (!array_key_exists('contacts', $old) && !array_key_exists('setup_required', $old)) || self::$recovering) { return; }
        foreach (['contacts', 'registry', 'registry_schema', 'setup_required', 'setup_fields', 'registry_supported'] as $field) {
            if (($operation->payload[$field] ?? null) !== ($old[$field] ?? null)) { throw new \DomainException('Contact setup snapshot is frozen.'); }
        }
    }

    /** Internal synchronous write scope; not an authorization boundary or a request input. */
    public static function recoveryWrite(\Closure $write): mixed
    {
        $previous = self::$recovering; self::$recovering = true;
        try { return $write(); } finally { self::$recovering = $previous; }
    }
}
