<?php
// Adapted from Enom 444f31c RegistrationSetup/DomainManager; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\{CustomProperty, User};

/** Local validation only. Provider acceptance and contact readback remain separate gates. */
final class ContactDetails
{
    public const ROLES = ['registrant', 'admin', 'tech', 'auxbilling'];
    public const REQUIRED_PROPERTIES = ['address', 'city', 'state', 'zip', 'country', 'phone'];
    private const FIELDS = ['first_name', 'last_name', 'organization_name', 'address1', 'address2', 'city',
        'state_province', 'postal_code', 'country', 'email_address', 'phone'];
    private const NS_LIMITS = [32, 32, 64, 128, 128, 64, 64, 16, 4, 128, 32];

    public static function limits(string $registrar): array
    { return array_combine(self::FIELDS, $registrar === 'namesilo' ? self::NS_LIMITS : array_fill(0, count(self::FIELDS), 255)); }

    public static function collect(User $owner): array
    {
        $properties = $owner->properties()->whereIn('key', [...self::REQUIRED_PROPERTIES, 'company_name', 'address2'])->pluck('value', 'key');
        $get = static fn (string $key): string => is_string($properties[$key] ?? null) ? $properties[$key] : '';
        $country = self::country($get('country')); $phone = $get('phone');
        $contact = array_combine(self::FIELDS, [(string) $owner->first_name, (string) $owner->last_name, $get('company_name'),
            $get('address'), $get('address2'), $get('city'), $get('state'), $get('zip'), $country, (string) $owner->email, $phone]);
        return array_fill_keys(self::ROLES, $contact);
    }

    public static function missingRequiredProperties(): array
    {
        $defined = CustomProperty::where('model', User::class)->whereIn('key', self::REQUIRED_PROPERTIES)->pluck('key')->all();
        return array_values(array_diff(self::REQUIRED_PROPERTIES, $defined));
    }

    public static function validate(string $registrar, #[\SensitiveParameter] mixed $contacts): array
    {
        if (!in_array($registrar, ['enom', 'namesilo'], true)) { throw new \DomainException('Contact schema unavailable.'); }
        $errors = []; $clean = [];
        if (!is_array($contacts) || array_diff(array_keys($contacts), self::ROLES)) { $errors[] = 'contacts'; }
        foreach (self::ROLES as $role) {
            $input = is_array($contacts) && is_array($contacts[$role] ?? null) ? $contacts[$role] : [];
            $row = [];
            if (array_diff(array_keys($input), self::FIELDS)) { $errors[] = 'contacts.'.$role; }
            foreach (self::FIELDS as $i => $field) {
                $value = $input[$field] ?? ''; $limit = self::limits($registrar)[$field];
                if (!is_string($value) || strlen($value) > $limit || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                    $errors[] = 'contacts.'.$role.'.'.$field; $value = '';
                }
                $row[$field] = trim($value);
                if ($row[$field] === '' && !in_array($field, ['organization_name', 'address2'], true)
                    && ($field !== 'state_province' || $registrar === 'namesilo')) { $errors[] = 'contacts.'.$role.'.'.$field; }
            }
            $country = strtoupper($row['country']); $row['country'] = $country;
            if (!preg_match('/^[A-Z]{2}$/D', $country) || !array_key_exists($country, config('app.countries', []))) { $errors[] = 'contacts.'.$role.'.country'; }
            if (!filter_var($row['email_address'], FILTER_VALIDATE_EMAIL) || preg_match('/\.(?:invalid|test|example|localhost|local)$/i', $row['email_address'])) { $errors[] = 'contacts.'.$role.'.email_address'; }
            if (in_array($country, ['US', 'CA', 'AU'], true) && $row['state_province'] === '') { $errors[] = 'contacts.'.$role.'.state_province'; }
            $phone = self::phone($row['phone'], $country);
            if ($phone === null) { $errors[] = 'contacts.'.$role.'.phone'; }
            else { $row['phone'] = $phone; }
            if ($registrar === 'namesilo' && in_array($country, ['US', 'CA'], true)) {
                $states = $country === 'US' ? 'AL AK AZ AR CA CO CT DE DC FL GA HI ID IL IN IA KS KY LA ME MD MA MI MN MS MO MT NE NV NH NJ NM NY NC ND OH OK OR PA RI SC SD TN TX UT VT VA WA WV WI WY AS GU MP PR VI' : 'AB BC MB NB NL NS NT NU ON PE QC SK YT';
                if (!in_array($row['state_province'], explode(' ', $states), true)) { $errors[] = 'contacts.'.$role.'.state_province'; }
                if ($phone === null || !preg_match('/^\+1\.([2-9][0-9]{2}[2-9][0-9]{6})$/D', $phone, $matches)) { $errors[] = 'contacts.'.$role.'.phone'; }
                else { $row['phone'] = $matches[1]; }
            }
            $clean[$role] = $row;
        }
        if ($errors) { throw new ContactValidation(array_values(array_unique($errors))); }
        return $clean;
    }

    private static function country(string $value): string
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $value)) { return ''; }
        $value = trim($value); $countries = config('app.countries', []); $code = strtoupper($value);
        if (preg_match('/^[A-Z]{2}$/D', $code) && isset($countries[$code])) { return $code; }
        $matches = array_keys(array_filter($countries, static fn ($label, $key) => preg_match('/^[A-Z]{2}$/D', (string) $key)
            && is_string($label) && strcasecmp(trim($label), $value) === 0, ARRAY_FILTER_USE_BOTH));
        return count($matches) === 1 ? $matches[0] : '';
    }

    private static function phone(string $value, string $country): ?string
    {
        if (preg_match('/^\+([1-9][0-9]{0,2})[. -]+([0-9() -]+)$/D', $value, $parts)) {
            $subscriber = preg_replace('/[() -]/', '', $parts[2]);
            if (preg_match('/^[0-9]{4,14}$/D', $subscriber) && strlen($parts[1].$subscriber) <= 15) { return '+'.$parts[1].'.'.$subscriber; }
        }
        if (in_array($country, ['US', 'CA'], true) && preg_match('/^\+?[0-9() .-]+$/D', $value)) {
            $digits = preg_replace('/[^0-9]/', '', $value);
            if (strlen($digits) === 11 && str_starts_with($digits, '1')) { $digits = substr($digits, 1); }
            elseif (str_starts_with($value, '+')) { return null; }
            if (preg_match('/^[2-9][0-9]{2}[2-9][0-9]{6}$/D', $digits)) { return '+1.'.$digits; }
        }
        return null;
    }
}
