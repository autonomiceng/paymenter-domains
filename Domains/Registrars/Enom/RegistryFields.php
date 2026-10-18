<?php
// Adapted from autonomiceng/paymenter-enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;

use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ProtocolResult;

final class RegistryFields
{
    /** @return list<array> normalized schema; [] when the ending needs nothing */
    public static function normalize(ProtocolResult $result): array
    {
        $container = $result->value('attributes');
        // Frozen .com replies omit Attributes and explicitly report ResponseCount=0.
        if ($container === '' || ($container === null && $result->value('responsecount') === '0')) { return []; }
        if (!is_array($container) || !array_key_exists('attribute', $container)) { throw new \InvalidArgumentException('Missing registry fields.'); }
        $attributes = $container['attribute'];
        if (is_array($attributes) && !array_is_list($attributes)) { $attributes = [$attributes]; }
        if (!is_array($attributes)) { throw new \InvalidArgumentException('Unreadable registry fields.'); }
        $schema = [];
        foreach (is_array($attributes) ? $attributes : [] as $attribute) {
            // A field we can't read safely makes the whole ending unsupported rather than silently optional.
            if (!is_array($attribute) || !is_string($attribute['name'] ?? null) || !preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $attribute['name'])
                || self::reserved($attribute['name'])) {
                throw new \InvalidArgumentException('Unreadable registry field.');
            }
            foreach (['required' => ['0', '1', '2'], 'ischild' => ['0', '1'], 'userdefined' => ['true', 'false']] as $flag => $values) {
                if (!is_string($attribute[$flag] ?? null) || !in_array(strtolower($attribute[$flag]), $values, true)) {
                    throw new \InvalidArgumentException('Unreadable registry field flags.');
                }
            }
            $name = $attribute['name'];
            $options = self::children($attribute, 'options', 'option');
            $choices = [];
            foreach (is_array($options) ? $options : [] as $option) {
                if (!is_array($option) || !is_string($option['value'] ?? null) || $option['value'] === '') { throw new \InvalidArgumentException('Unreadable registry choice.'); }
                $requires = self::children($option, 'requires', 'attribute');
                if (array_filter($requires, fn ($item) => !is_array($item) || !is_string($item['name'] ?? null)
                    || !preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $item['name']))) {
                    throw new \InvalidArgumentException('Unreadable registry field dependency.');
                }
                $choices[] = ['value' => $option['value'], 'label' => self::text($option['title'] ?? null) ?: $option['value'],
                    'description' => self::text($option['description'] ?? null),
                    'requires' => array_column($requires, 'name')];
            }
            $entry = ['name' => $name, 'label' => self::text($attribute['description'] ?? null) ?: ucfirst(str_replace('_', ' ', $name)),
                'required' => (string) ($attribute['required'] ?? '0') === '1', 'child' => (string) ($attribute['ischild'] ?? '0') === '1',
                'text' => strcasecmp((string) ($attribute['userdefined'] ?? ''), 'true') === 0, 'options' => $choices];
            // Repeated names must agree; only an initially empty options list may be filled.
            $existing = array_search($name, array_column($schema, 'name'), true);
            if ($existing === false) { $schema[] = $entry; }
            else {
                $previous = $schema[$existing];
                foreach (['required', 'child', 'text'] as $flag) {
                    if ($previous[$flag] !== $entry[$flag]) { throw new \InvalidArgumentException('Conflicting registry field flags.'); }
                }
                if ($choices && $previous['options'] && $choices !== $previous['options']) {
                    throw new \InvalidArgumentException('Conflicting registry field options.');
                }
                if ($choices && !$previous['options']) { $schema[$existing]['options'] = $choices; }
            }
        }
        $names = array_column($schema, 'name');
        foreach ($schema as $field) {
            foreach ($field['options'] as $option) {
                foreach ($option['requires'] as $required) {
                    if (!in_array($required, $names, true)) { throw new \InvalidArgumentException('Registry field depends on an unknown field.'); }
                }
            }
        }
        return $schema;
    }

    /** Missing or explicitly empty optional wrappers differ from malformed/repeated wrappers. */
    private static function children(array $parent, string $wrapper, string $child): array
    {
        if (!array_key_exists($wrapper, $parent) || $parent[$wrapper] === '') { return []; }
        $container = $parent[$wrapper];
        if (!is_array($container) || array_is_list($container) || !array_key_exists($child, $container)
            || array_diff(array_keys($container), [$child, '@attributes']) || !is_array($container[$child])) {
            throw new \InvalidArgumentException('Unreadable registry container.');
        }
        return array_is_list($container[$child]) ? $container[$child] : [$container[$child]];
    }

    /** Names that would collide with ordinary Enom request parameters. */
    private static function reserved(string $name): bool
    {
        return (bool) preg_match('/^(?:uid|pw|command|responsetype|sld|tld|numyears|overrideorder|usewiretransfer|deleteorderdetail|premiumdomain|chargeamount|creditcard[a-z]*|cvv2|ccname|ns\d+|usedns|renewname|renew|emailnotify|usecreditcard|queueorder|allowqueuing|ignorensfail|unlockregistrar|lock|authinfo\d*|ordertype|domaincount|preconfig|customersuppliedprice|(?:registrant|admin|tech|auxbilling|billing)[a-z]+)$/i', $name);
    }

    /** Whether every field the registry needs can be collected by the order form. */
    public static function supported(array $schema): bool
    {
        foreach ($schema as $field) {
            if (!$field['text'] && !$field['options']) { return false; }
        }
        return true;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim(preg_replace('/\s+/', ' ', $value)) : '';
    }
}
