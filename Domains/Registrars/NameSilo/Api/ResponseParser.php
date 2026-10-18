<?php
// Adapted from Enom Api/RegistrarClient.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api;

/** Pure protocol classification; accepts purchase mappings as offline evidence, never dispatches. */
final class ResponseParser
{
    public function parse(#[\SensitiveParameter] string $body, RegistrarRequest|PurchaseMapping $request,
        #[\SensitiveParameter] string $key): RegistrarResult
    {
        try {
            if (strlen($body) > CurlTransport::BODY_LIMIT) { return new RegistrarResult(Outcome::Unknown); }
            return $this->decode($body, $request, $key);
        } catch (\Throwable) {
            return new RegistrarResult(Outcome::Unknown);
        }
    }

    private function decode(#[\SensitiveParameter] string $body, RegistrarRequest|PurchaseMapping $request,
        #[\SensitiveParameter] string $key): RegistrarResult
    {
        $data = $this->json($body);
        if (!is_array($data) || ($data['request']['operation'] ?? null) !== $request->command
            || !is_array($data['reply'] ?? null) || !is_string($data['reply']['detail'] ?? null)) {
            return new RegistrarResult(Outcome::Unknown);
        }
        $reply = $data['reply'];
        $code = $reply['code'] ?? null;
        if (is_string($code) && preg_match('/^[1-9][0-9]{2}$/D', $code)) { $code = (int) $code; }
        if (!is_int($code) || $code < 100 || $code > 999) { return new RegistrarResult(Outcome::Unknown); }
        if ($code === 301 || $code === 302) {
            if (!$request instanceof PurchaseMapping || !$this->validSuccess($request, $reply)) {
                return new RegistrarResult(Outcome::Unknown, $code);
            }
            return new RegistrarResult(Outcome::Fallback, $code, $this->sanitize($this->project($request->command, $reply), $key));
        }
        if ($code !== 300) {
            // Even a documented refusal cannot erase contradictory purchase/order evidence.
            $evidence = array_intersect(['order_amount', 'order_number', 'order_details'], array_keys($reply));
            $rejected = $this->refusal($request->command, $code) && !$evidence;
            return new RegistrarResult($rejected ? Outcome::Rejected : Outcome::Unknown, $code, [],
                $rejected ? $this->scope($code) : null);
        }
        if (in_array($request->command, ['checkRegisterAvailability', 'checkTransferAvailability'], true)) {
            $reply = $this->availability($request, $reply);
        }
        if (!$this->validSuccess($request, $reply)) { return new RegistrarResult(Outcome::Unknown, 300); }
        unset($reply['code'], $reply['detail'], $reply['message']);
        return new RegistrarResult(Outcome::Success, 300, $this->sanitize($this->project($request->command, $reply), $key));
    }

    /** Preserve numeric lexemes before JSON's float conversion; amounts remain exact decimal strings. */
    private function json(#[\SensitiveParameter] string $body): array
    {
        if (!json_validate($body, 24)) { throw new \UnexpectedValueException('Invalid registrar JSON.'); }
        $output = '';
        $quoted = false;
        $escaped = false;
        $length = strlen($body);
        for ($index = 0; $index < $length; ++$index) {
            $char = $body[$index];
            if ($quoted) {
                $output .= $char;
                if ($escaped) { $escaped = false; }
                elseif ($char === '\\') { $escaped = true; }
                elseif ($char === '"') { $quoted = false; }
            } elseif ($char === '"') {
                $quoted = true;
                $output .= $char;
            } elseif (str_contains('-0123456789', $char)) {
                $start = $index;
                while ($index < $length && str_contains('-+0123456789.eE', $body[$index])) { ++$index; }
                $output .= '"'.substr($body, $start, $index - $start).'"';
                --$index;
            } else { $output .= $char; }
        }
        return json_decode($output, true, 24, JSON_THROW_ON_ERROR);
    }

    private function refusal(string $command, int $code): bool
    {
        $common = [101,102,103,104,105,106,107,108,109,110,111,112,113,114,116,117,118,119,120];
        $specific = match ($command) {
            'getDomainInfo' => [200],
            'checkRegisterAvailability', 'checkTransferAvailability' => [267],
            'registerDomain' => [263,267],
            'transferDomain' => [263,265,266,267],
            'renewDomain' => [263,264],
            default => [],
        };
        return in_array($code, [...$common, ...$specific], true);
    }

    private function scope(int $code): RejectionScope
    {
        if ($code === 119) { return RejectionScope::Funds; }
        if (in_array($code, [109,110,111,112,113,116,117,118,120], true)) { return RejectionScope::Account; }
        return in_array($code, [200,264,265,266], true) ? RejectionScope::Domain : RejectionScope::Request;
    }

    private function availability(RegistrarRequest $request, array $reply): array
    {
        $requested = explode(',', $request->parameters()['domains']);
        $seen = [];
        foreach (['available', 'unavailable', 'invalid'] as $field) {
            if (!array_key_exists($field, $reply)) { continue; }
            $records = $reply[$field];
            if (!is_array($records)) { throw new \UnexpectedValueException('Invalid availability shape.'); }
            $records = array_is_list($records) ? $records : [$records];
            foreach ($records as &$record) {
                $domain = $record['domain'] ?? null;
                if (!is_string($domain) || !in_array($domain, $requested, true) || isset($seen[$domain])) {
                    throw new \UnexpectedValueException('Uncorrelated availability result.');
                }
                $seen[$domain] = true;
                if ($field === 'available') {
                    if (!$this->amount($record['price'] ?? null)) { throw new \UnexpectedValueException('Invalid availability price.'); }
                    $premium = $record['premium'] ?? null;
                    if ($premium !== null && !in_array($premium, [0,1,'0','1'], true)) {
                        throw new \UnexpectedValueException('Invalid premium evidence.');
                    }
                    $record['premium'] = $premium === null ? null : (bool) $premium;
                }
            }
            unset($record);
            $reply[$field] = $records;
        }
        if (count($seen) !== count($requested)) { throw new \UnexpectedValueException('Incomplete availability result.'); }
        return $reply;
    }

    private function validSuccess(RegistrarRequest|PurchaseMapping $request, array $reply): bool
    {
        return match ($request->command) {
            'checkRegisterAvailability', 'checkTransferAvailability' =>
                count(array_intersect(['available', 'unavailable', 'invalid'], array_keys($reply))) > 0
                && $this->collections($reply, ['available', 'unavailable', 'invalid']),
            'getPrices' => $this->prices($reply),
            'getDomainInfo' => isset($reply['created'], $reply['expires'], $reply['status'])
                && is_string($reply['created']) && is_string($reply['expires']) && is_string($reply['status'])
                && $this->flags($reply),
            'getAccountBalance' => $this->amount($reply['balance'] ?? null),
            'listOrders' => isset($reply['order']) && is_array($reply['order']),
            'orderDetails' => $this->identifier($reply['order_number'] ?? null) === $request->parameters()['order_number']
                && isset($reply['order_details']) && is_array($reply['order_details']) && $this->amount($reply['total'] ?? null),
            'registerDomain', 'transferDomain', 'renewDomain' => ($reply['domain'] ?? null) === $request->parameters()['domain']
                && $this->amount($reply['order_amount'] ?? null),
            default => false,
        };
    }

    private function collections(array $reply, array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $reply) && !is_array($reply[$field])) { return false; }
        }
        return true;
    }

    private function prices(array $reply): bool
    {
        $count = 0;
        foreach ($reply as $tld => $prices) {
            if (in_array($tld, ['code', 'detail', 'message'], true)) { continue; }
            if (!preg_match('/^[a-z]{2,63}(?:\.[a-z]{2,63})*$/D', (string) $tld) || !is_array($prices)) { return false; }
            foreach (['registration', 'transfer', 'renew'] as $field) {
                if (!$this->amount($prices[$field] ?? null)) { return false; }
            }
            ++$count;
        }
        return $count > 0;
    }

    private function flags(array $reply): bool
    {
        foreach (['locked', 'private', 'auto_renew'] as $flag) {
            if (!in_array($reply[$flag] ?? null, ['Yes', 'No'], true)) { return false; }
        }
        return true;
    }

    private function amount(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', (string) $value) === 1;
    }

    private function project(string $command, array $reply): array
    {
        $fields = match ($command) {
            'checkRegisterAvailability', 'checkTransferAvailability' => ['available', 'unavailable', 'invalid'],
            'getDomainInfo' => ['created', 'expires', 'status', 'locked', 'private', 'auto_renew', 'traffic_type',
                'email_verification_required', 'portfolio', 'nameservers', 'contact_ids'],
            'getAccountBalance' => ['balance'],
            'listOrders' => ['order'],
            'orderDetails' => ['order_number', 'order_date', 'method', 'total', 'order_details'],
            'registerDomain', 'transferDomain', 'renewDomain' => ['domain', 'order_amount'],
            default => [],
        };
        if ($command === 'getPrices') {
            $result = [];
            foreach ($reply as $tld => $prices) {
                if (is_array($prices)) { $result[$tld] = array_intersect_key($prices, array_flip(['registration', 'transfer', 'renew'])); }
            }
            return $this->normalize($result);
        }
        $result = array_intersect_key($reply, array_flip($fields));
        if (in_array($command, ['listOrders', 'orderDetails'], true)) {
            $allowed = $command === 'listOrders' ? ['order'] : ['order_number', 'order_date', 'method', 'total', 'order_details'];
            $result['history_complete'] = !array_diff(array_keys($reply), ['code', 'detail', 'message', ...$allowed]);
        }
        $records = [
            'available' => ['domain', 'price', 'premium', 'duration'], 'unavailable' => ['domain'], 'invalid' => ['domain'],
            'nameservers' => ['nameserver', 'position'],
            'contact_ids' => ['registrant', 'administrative', 'technical', 'billing'],
            'order' => ['order_number', 'order_date', 'method', 'total'],
            'order_details' => ['description', 'years_qty', 'price', 'subtotal', 'status', 'credited_date', 'credited_amount'],
        ];
        foreach ($records as $field => $allowed) {
            if (!array_key_exists($field, $result)) { continue; }
            if (!is_array($result[$field])) { throw new \UnexpectedValueException('Invalid registrar collection shape.'); }
            $project = function (array $record) use ($field, $allowed, &$result): array {
                if (in_array($field, ['order', 'order_details'], true) && array_diff(array_keys($record), $allowed)) { $result['history_complete'] = false; }
                return $this->scalarFields(array_intersect_key($record, array_flip($allowed)));
            };
            $result[$field] = array_is_list($result[$field]) ? array_map($project, $result[$field]) : $project($result[$field]);
        }
        foreach ($result as $field => $value) {
            if (!isset($records[$field]) && !is_scalar($value) && $value !== null) {
                throw new \UnexpectedValueException('Invalid registrar field shape.');
            }
        }
        return $this->normalize($result);
    }

    private function identifier(mixed $value): ?string
    {
        return (is_string($value) || is_int($value)) && preg_match('/^[1-9][0-9]{0,19}$/D', (string) $value)
            ? (string) $value : null;
    }

    private function normalize(array $values): array
    {
        $amounts = ['registration', 'transfer', 'renew', 'balance', 'price', 'order_amount', 'total', 'subtotal', 'credited_amount'];
        foreach ($values as $field => &$value) {
            if (is_array($value)) { $value = $this->normalize($value); continue; }
            if (in_array($field, $amounts, true)) {
                if (!$this->amount($value)) { throw new \UnexpectedValueException('Invalid registrar amount.'); }
                [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');
                $value = (ltrim($whole, '0') ?: '0').'.'.str_pad($fraction, 2, '0');
            } elseif ($field === 'order_number') {
                $value = $this->identifier($value) ?? throw new \UnexpectedValueException('Invalid order identifier.');
            }
        }
        unset($value);
        return $values;
    }

    private function scalarFields(array $record): array
    {
        foreach ($record as $value) {
            if (!is_scalar($value) && $value !== null) { throw new \UnexpectedValueException('Invalid registrar record shape.'); }
        }
        return $record;
    }

    /** Drop diagnostics and recursively remove credential variants and URL queries from retained fields. */
    private function sanitize(array $reply, #[\SensitiveParameter] string $key): array
    {
        $tokens = array_unique([$key, rawurlencode($key), urlencode($key), base64_encode($key), htmlspecialchars($key, ENT_QUOTES)]);
        $clean = static fn (string $value): string => preg_replace('~https?://[^\s"<>]+~i', '[redacted-url]',
            str_replace($tokens, '[redacted]', $value));
        $walk = function (array $values) use (&$walk, $clean): array {
            $result = [];
            foreach ($values as $name => $value) {
                if (is_string($name) && preg_match('/^(?:key|api_key|token|secret|auth|detail|message|request|url)$/i', $name)) { continue; }
                $name = is_string($name) ? $clean($name) : $name;
                $sanitized = is_scalar($value) ? $clean((string) $value) : null;
                $result[$name] = is_array($value) ? $walk($value)
                    : ($sanitized !== null && $sanitized !== (string) $value ? $sanitized : $value);
            }
            return $result;
        };
        return $walk($reply);
    }
}
