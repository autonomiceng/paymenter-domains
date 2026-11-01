<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\User;
use Paymenter\Extensions\Servers\Domains\Models\Tld;

/** Request-local input from the exact native Cart checkout call. Never stored in cart properties. */
final class OrderContact
{
    private const ATTRIBUTE = 'domains.order_contact';

    public static function begin(User $owner, #[\SensitiveParameter] mixed $input): void
    {
        self::clear();
        if (!is_array($input) || array_diff(array_keys($input), ['contact', 'save_account'])
            || !is_array($input['contact'] ?? null) || !is_bool($input['save_account'] ?? false)) {
            throw new ContactValidation(['contact']);
        }
        request()->attributes->set(self::ATTRIBUTE, ['owner' => (int) $owner->id, ...$input]);
    }

    public static function clear(): void { request()->attributes->remove(self::ATTRIBUTE); }

    public static function capture(User $owner, Tld $tld): array
    {
        $input = request()->attributes->get(self::ATTRIBUTE);
        if ($input !== null && ($input['owner'] ?? null) !== (int) $owner->id) { throw new \DomainException('Contact owner changed.'); }
        $contacts = $input === null ? ContactDetails::collect($owner) : self::roles($input['contact']);
        return ContactSetup::forOrder($tld, $contacts);
    }

    public static function roles(#[\SensitiveParameter] array $contact): array
    { return array_fill_keys(ContactDetails::ROLES, $contact); }

    /** Address/phone only: account email changes retain Paymenter's verification flow. Caller owns user lock/transaction. */
    public static function saveAccount(User $owner, #[\SensitiveParameter] array $contact): void
    {
        $map = ['organization_name'=>'company_name', 'address1'=>'address', 'address2'=>'address2', 'city'=>'city',
            'state_province'=>'state', 'postal_code'=>'zip', 'country'=>'country', 'phone'=>'phone'];
        foreach ($map as $field => $key) {
            $owner->properties()->updateOrCreate(['key'=>$key], ['name'=>$key, 'value'=>$contact[$field]]);
        }
    }

    public static function saveRequested(User $owner, Tld $tld): void
    {
        if (request()->attributes->get(self::ATTRIBUTE)['save_account'] ?? false) {
            self::saveAccount($owner, self::capture($owner, $tld)['contacts']['registrant']);
        }
    }
}
