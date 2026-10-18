<?php
// Adapted from autonomiceng/paymenter-enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;

use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ProtocolResult;

final class Contacts
{
    private const FIELDS = ['first_name' => 'firstname', 'last_name' => 'lastname', 'organization_name' => 'organizationname',
        'job_title' => 'jobtitle', 'address1' => 'address1', 'address2' => 'address2', 'city' => 'city',
        'state_province' => 'stateprovince', 'state_province_choice' => 'stateprovincechoice', 'postal_code' => 'postalcode',
        'country' => 'country', 'email_address' => 'emailaddress', 'phone' => 'phone', 'phone_ext' => 'phoneext', 'fax' => 'fax'];

    public static function read(ProtocolResult $reply): ?array
    {
        $info = $reply->value('getcontacts');
        if ($reply->status !== 'success' || !is_array($info) || !is_array($info['registrant'] ?? null)) { return null; }
        $contacts = []; $collected = [];
        foreach (['registrant', 'admin', 'tech', 'auxbilling'] as $role) {
            if (!array_key_exists($role, $info)) { $contacts[$role] = null; $collected[$role] = null; continue; }
            $contact = $info[$role];
            if (!is_array($contact) || ($contact && array_is_list($contact))) { return null; }
            $contacts[$role] = [];
            $match = $contact[$role.'contactmatch'] ?? null;
            $collected[$role] = is_string($match) && strcasecmp($match, 'NotCollected') === 0 ? false : true;
            foreach (self::FIELDS as $field => $suffix) {
                $values = [];
                foreach ([$role.$suffix, $suffix] as $key) {
                    if (!array_key_exists($key, $contact)) { continue; }
                    $value = $contact[$key];
                    if (is_array($value)) {
                        if (array_diff(array_keys($value), ['#text', '@attributes'])
                            || (isset($value['@attributes']) && (!is_array($value['@attributes'])
                                || count(array_filter($value['@attributes'], 'is_string')) !== count($value['@attributes'])))) { return null; }
                        $value = $value['#text'] ?? null;
                    }
                    if (!is_string($value)) { return null; }
                    $values[] = $value;
                }
                if (count($values) === 2 && $values[0] !== $values[1]) { return null; }
                if ($values) { $contacts[$role][$field] = $values[0]; }
            }
        }
        return ['contacts' => $contacts, 'collected' => $collected,
            'pending_verification' => Driver::flag($info['pendingverification'] ?? null),
            'transfer_locked' => Driver::flag($info['transferlock'] ?? null)];
    }
}
