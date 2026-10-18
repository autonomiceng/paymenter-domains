<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace OteContract;

/** Closed predicates only. Never return contact values, provider IDs or dynamic keys. */
final class ProfileDiagnostics
{
    public const FIELDS = ['fn' => 'first_name', 'ln' => 'last_name', 'cp' => 'company', 'ad' => 'address', 'ad2' => 'address2',
        'cy' => 'city', 'st' => 'state', 'zp' => 'zip', 'ct' => 'country', 'em' => 'email', 'ph' => 'phone'];
    public static function inspect(#[\SensitiveParameter] array $reply, #[\SensitiveParameter] array $parameters,
        #[\SensitiveParameter] ?string $expectedId): array
    {
        $rows = $reply['contact'] ?? null;
        $shape = !array_key_exists('contact', $reply) ? 'missing' : (!is_array($rows) ? (is_null($rows) ? 'null' : 'scalar')
            : (array_is_list($rows) ? 'list' : 'object'));
        if (is_array($rows) && !array_is_list($rows)) { $rows = [$rows]; }
        $row = is_array($rows) && count($rows) === 1 && is_array($rows[0]) ? $rows[0] : [];
        $id = $row['contact_id'] ?? null;
        $idType = is_int($id) || is_string($id);
        $validId = $idType && preg_match('/^[1-9][0-9]{0,19}$/D', (string) $id) === 1;
        $flag = $row['default_profile'] ?? null;
        $d = ['shape' => $shape, 'single_row' => is_array($rows) && count($rows) === 1 && is_array($rows[0]),
            'id_present' => array_key_exists('contact_id', $row), 'id_type_ok' => $idType, 'id_valid' => $validId,
            'id_comparable' => $expectedId !== null, 'id_match' => $expectedId !== null && is_scalar($id) && (string) $id === $expectedId,
            'default_present' => array_key_exists('default_profile', $row), 'default_type_ok' => is_int($flag) || is_string($flag),
            'default_nondefault' => in_array($flag, [0, '0'], true),
            'default_category' => match (true) { !array_key_exists('default_profile', $row) => 'missing', $flag === null => 'null',
                $flag === 0 => 'zero-int', $flag === '0' => 'zero-string', $flag === 1 => 'one-int', $flag === '1' => 'one-string',
                $flag === '' => 'empty-string', $flag === 'No' => 'no-string', $flag === 'Yes' => 'yes-string',
                is_string($flag) => 'other-string', default => 'other-type' }, 'fields' => []];
        foreach (self::FIELDS as $wire => $field) {
            $value = $row[$field] ?? null; $exact = array_key_exists($wire, $parameters) && $value === $parameters[$wire];
            $match = $exact || ($field === 'phone' && is_string($parameters[$wire] ?? null)
                && \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\ContactReadback::phoneMatches($value, $parameters[$wire], $parameters['ct'] ?? ''));
            $d['fields'][$field] = ['present' => array_key_exists($field, $row), 'type_ok' => is_string($value),
                'expected_empty' => ($parameters[$wire] ?? null) === '', 'exact_match' => $exact, 'match' => $match,
                'category' => match (true) { !array_key_exists($field, $row) => 'missing', $value === null => 'null',
                    $value === '' => 'empty-string', !is_string($value) => 'other-type', $match => 'string-match', default => 'string-mismatch' }];
        }
        $d['identity_match'] = !in_array(false, array_column($d['fields'], 'match'), true);
        $d['held_by'] = !$d['id_match'] ? 'contact_id' : (!$d['default_nondefault'] ? 'default_profile' : 'none');
        foreach ($d['fields'] as $field => $facts) {
            if ($d['held_by'] === 'none' && !$facts['match']) { $d['held_by'] = 'field.'.$field; }
        }
        return $d;
    }
    public static function inventory(#[\SensitiveParameter] array $reply, #[\SensitiveParameter] array $parameters): array
    {
        $rows = $reply['contact'] ?? null;
        if (is_array($rows) && !array_is_list($rows)) { $rows = [$rows]; }
        $result = [];
        foreach (is_array($rows) ? $rows : [null] as $row) {
            $d = self::inspect(['contact' => $row], $parameters, null);
            // Inventory cannot prove correspondence to a contactAdd acknowledgement.
            $d['id_comparable'] = false; $d['id_match'] = false;
            $d['held_by'] = 'inventory_not_creation_proof';
            $result[] = $d;
        }
        return ['shape' => is_array($reply['contact'] ?? null) ? (array_is_list($reply['contact']) ? 'list' : 'object') : 'unknown',
            'rows' => $result, 'production_proof' => false];
    }
}
