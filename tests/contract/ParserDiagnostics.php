<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace OteContract;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{Outcome, RegistrarRequest, ResponseParser};

/** Test-only reflection keeps diagnostics on the actual production predicates, without retaining values. */
final class ParserDiagnostics
{
    public static function inspect(#[\SensitiveParameter] string $body, RegistrarRequest $request,
        #[\SensitiveParameter] string $key): array
    {
        $parser = new ResponseParser;
        $call = static fn (string $method, ...$args) => (new \ReflectionMethod($parser, $method))->invoke($parser, ...$args);
        $facts = ['outcome' => $parser->parse($body, $request, $key)->outcome->name,
            'predicate' => null, 'exception_class' => null];
        try {
            $data = $call('json', $body);
            $reply = $data['reply'] ?? null;
            $raw = json_decode($body, true, 24, JSON_THROW_ON_ERROR);
            $facts['code_type'] = get_debug_type($raw['reply']['code'] ?? null);
            $facts['detail_type'] = get_debug_type($raw['reply']['detail'] ?? null);
            $facts['structure'] = Shapes::shape($raw); // Wire types, whitelisted keys only.
            $facts['money_pattern'] = []; $facts['money_structure'] = [];
            $walk = static function (mixed $values, string $path = '$') use (&$walk, &$facts, $call): void {
                if (!is_array($values)) { return; }
                foreach ($values as $field => $value) {
                    // Dynamic TLDs and arbitrary account/domain keys must not reach the receipt.
                    $part = is_int($field) ? '[]' : (in_array($field, ['reply', 'com', 'net', 'balance', 'registration', 'transfer', 'renew', 'available', 'price'], true) ? $field : '[other-field]');
                    if (in_array($field, ['balance', 'registration', 'transfer', 'renew', 'price'], true)) {
                        $facts['money_pattern'][$path.'.'.$part][] = $call('amount', $value);
                        $facts['money_structure'][$path.'.'.$part][] = [
                            'decimal_scale_at_most_six' => is_string($value) && preg_match('/^[0-9]+(?:\.[0-9]{1,6})?$/D', $value) === 1,
                            'exact_at_two_decimals' => is_string($value) && preg_match('/^[0-9]+(?:\.[0-9]{1,2}0*)?$/D', $value) === 1,
                            'decimal_any_scale' => is_string($value) && preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $value) === 1,
                            'grouped_decimal' => is_string($value) && preg_match('/^[0-9]{1,3}(?:,[0-9]{3})+(?:\.[0-9]{1,2})?$/D', $value) === 1,
                            'trimmed_decimal' => is_string($value) && $call('amount', trim($value)),
                        ];
                    }
                    if (is_array($value)) { $walk($value, $path.'.'.$part); }
                }
            };
            $walk($data);
            foreach ($facts['money_pattern'] as &$matches) { $matches = array_values(array_unique($matches, SORT_REGULAR)); }
            unset($matches);
            foreach ($facts['money_structure'] as &$profiles) { $profiles = array_values(array_unique($profiles, SORT_REGULAR)); }
            unset($profiles);
            if ($facts['outcome'] === Outcome::Success->name) { return $facts; }
            // decode throws before parse's catch; never retain its message or arguments.
            $result = $call('decode', $body, $request, $key);
            if (($data['request']['operation'] ?? null) !== $request->command || !is_array($reply) || !is_string($reply['detail'] ?? null)) {
                $facts['predicate'] = 'envelope';
            } elseif ($result->code !== 300) { $facts['predicate'] = 'success_code'; }
            else {
                $facts['predicate'] = match ($request->command) {
                    'getAccountBalance' => 'amount.balance', 'getPrices' => 'prices',
                    default => 'validSuccess',
                };
            }
            if ($request->command === 'getPrices' && is_array($reply) && $result->code === 300) {
                $facts['price_predicates'] = []; $first = null;
                foreach ($reply as $tld => $prices) {
                    if (in_array($tld, ['code', 'detail', 'message'], true)) { continue; }
                    $valid = ['registration' => '1.00', 'transfer' => '1.00', 'renew' => '1.00'];
                    $checks = ['tld_key' => $call('prices', [$tld => $valid]), 'entry_object' => is_array($prices)];
                    foreach (['registration', 'transfer', 'renew'] as $field) {
                        $checks['amount.'.$field] = is_array($prices) && $call('amount', $prices[$field] ?? null);
                    }
                    $bucket = in_array($tld, ['com', 'net'], true) ? $tld : '[other-field]';
                    $facts['price_predicates'][$bucket][] = $checks;
                    $facts['price_key_structure'][$bucket][] = [
                        'ascii_hyphen_labels' => preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*$/D', (string) $tld) === 1,
                        'punycode_label' => preg_match('/(?:^|\.)xn--/', (string) $tld) === 1,
                        'non_ascii' => preg_match('/[^\x00-\x7f]/', (string) $tld) === 1,
                    ];
                    foreach ($checks as $predicate => $passed) { if (!$passed && $first === null) { $first = 'prices.'.$predicate; } }
                    if (in_array(false, $checks, true)) {
                        // Only syntactically valid public catalogue labels may be retained.
                        $label = $checks['tld_key'] && !str_contains((string) $tld, $key) ? $tld : '[invalid-tld]';
                        $categories = [];
                        if (!$checks['tld_key']) { $categories['tld_key'] = 'tld-key'; }
                        if (!$checks['entry_object']) { $categories['row'] = 'json-type'; }
                        foreach (['registration', 'transfer', 'renew'] as $field) {
                            if (!$checks['amount.'.$field]) {
                                $categories[$field] = self::amountFailure(is_array($prices) ? $prices : [], $field);
                            }
                        }
                        $facts['catalogue_failures'][] = ['tld' => $label, 'categories' => $categories,
                            'passes' => array_intersect_key($checks, array_flip(['amount.registration', 'amount.transfer', 'amount.renew']))];
                    }
                }
                foreach ($facts['price_predicates'] as &$checks) { $checks = array_values(array_unique($checks, SORT_REGULAR)); }
                unset($checks);
                foreach ($facts['price_key_structure'] ?? [] as $bucket => $profiles) { $facts['price_key_structure'][$bucket] = array_values(array_unique($profiles, SORT_REGULAR)); }
                $facts['predicate'] = $first ?? 'prices.nonempty';
            }
        } catch (\Throwable $error) {
            $facts['exception_class'] = match (get_class($error)) {
                \UnexpectedValueException::class, \TypeError::class, \JsonException::class, \Error::class => get_class($error),
                default => 'Throwable',
            };
            $facts['predicate'] = match ($error->getMessage()) {
                'Invalid registrar JSON.' => 'json.valid', 'Invalid availability shape.' => 'availability.collection',
                'Uncorrelated availability result.' => 'availability.correlation', 'Invalid availability price.' => 'amount.availability.price',
                'Invalid premium evidence.' => 'availability.premium', 'Incomplete availability result.' => 'availability.complete',
                'Invalid registrar amount.' => 'normalize.amount', 'Invalid registrar collection shape.' => 'project.collection',
                'Invalid registrar field shape.' => 'project.field', 'Invalid registrar record shape.' => 'project.record',
                'Invalid order identifier.' => 'identifier', default => 'parser.exception',
            };
        }
        return $facts;
    }

    /** Closed vocabulary only; never return any part of the amount or an exception message. */
    private static function amountFailure(array $row, string $field): string
    {
        if (!array_key_exists($field, $row)) { return 'missing'; }
        $value = $row[$field];
        if ($value === null) { return 'null'; }
        if (!is_string($value)) { return 'json-type'; }
        if ($value === '') { return 'empty-string'; }
        if (str_contains($value, ',')) { return 'grouped-ambiguous'; }
        if (trim($value) !== $value) { return 'whitespace'; }
        if (preg_match('/^-[0-9]+(?:\.[0-9]+)?$/D', $value)) { return 'negative'; }
        if (preg_match('/^[0-9]+\.[0-9]{2}0+$/D', $value)) { return 'fraction-trailing-zeros'; }
        if (preg_match('/^[0-9]+\.[0-9]{3,}$/D', $value)) { return 'fraction-digits>2'; }
        if (preg_match('/^(?:\.[0-9]+|[0-9]+\.)$/D', $value)) { return 'decimal-edge'; }
        if (preg_match('/^\+[0-9]+(?:\.[0-9]+)?$/D', $value)) { return 'positive-sign'; }
        if (preg_match('/^-?[0-9]+(?:\.[0-9]+)?[eE][+-]?[0-9]+$/D', $value)) { return 'exponent'; }
        return 'non-decimal';
    }
}
