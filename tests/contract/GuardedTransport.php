<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace OteContract;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{CurlTransport, DeadlineTransport, Environment, RegistrarRequest, RequestContext, TransportResponse};

/** The only network boundary; never installed in a registrar registry outside this runner. */
final class GuardedTransport implements DeadlineTransport
{
    private readonly \SensitiveParameterValue $secret;
    private float $last = 0;
    private array $sent = [];
    private array $latest = [];
    private array $details = [];
    private bool $renewalAuthorized = false;
    private ?\Closure $purchaseAuthorization = null;
    private ?PurchaseAttempt $purchaseAttempt = null;
    private array $privateDetails = [];
    private array $attempts = [];
    private ?string $renewalBefore = null;
    public readonly string $domain;
    public readonly ?string $continuationExpiry;
    public array $receipt = ['request_count' => 0, 'requests' => [], 'mismatches' => [], 'checks' => []];
    public function __construct(#[\SensitiveParameter] string $key, private readonly bool $write, private readonly string $output,
        private readonly ?\Closure $fake = null, private readonly ?\Closure $clock = null, private readonly int $limit = 60,
        private readonly bool $reconcile = false, private readonly ?string $continuationReceipt = null)
    {
        if ($reconcile && ($write || $limit > 15)) { throw new \RuntimeException('OTE reconciliation scope refused.'); }
        if ($limit < 1 || $limit > 60) { throw new \RuntimeException('OTE budget refused.'); }
        if ($clock !== null && $fake === null) { throw new \RuntimeException('OTE live clock cannot be overridden.'); }
        if (strlen($key) < 8) { throw new \RuntimeException('OTE credential unavailable.'); }
        $this->receipt['request_limit'] = $limit;
        $this->secret = new \SensitiveParameterValue($key);
        if ($continuationReceipt !== null && (!$write || getenv('OTE_RENEWAL_CONTEXT') !== 'interactive')) { throw new \RuntimeException('Interactive renewal authority required.'); }
        $this->renewalAuthorized = $continuationReceipt !== null;
        $this->continuationExpiry = $continuationReceipt !== null
            ? PurchaseAttempt::readPrivate(dirname($continuationReceipt).'/attempt-private.json')['before_expiry'] : null;
        $this->domain = $continuationReceipt !== null ? RenewalContinuation::claim($continuationReceipt, $limit) : 'contract-ote-'.bin2hex(random_bytes(6)).'.com';
    }
    public static function window(int $hour): void
    { if ($hour >= 18 && $hour < 21) { throw new \RuntimeException('OTE time window closed.'); } }
    private function timeGuard(): void
    { self::window($this->clock ? ($this->clock)() : (int) gmdate('G')); }
    public static function origin(string $origin): void
    {
        if (Environment::Sandbox->origin() !== 'https://ote.namesilo.com'
            || !in_array($origin, [Environment::Sandbox->origin().'/api', Environment::Sandbox->origin().'/apibatch'], true)
            || parse_url($origin, PHP_URL_HOST) !== 'ote.namesilo.com') { throw new \RuntimeException('OTE origin refused.'); }
    }
    public function send(Environment $e, RequestContext $c, RegistrarRequest $r, #[\SensitiveParameter] string $key): TransportResponse
    { return $this->sendWithin($e, $c, $r, $key, 25000); }
    public function sendWithin(Environment $e, RequestContext $c, RegistrarRequest $r, #[\SensitiveParameter] string $key, int $ms): TransportResponse
    {
        $this->clearReply($r->command, $r->parameters());
        if ($e !== Environment::Sandbox || !hash_equals($this->secret->getValue(), $key)) { throw new \RuntimeException('OTE environment refused.'); }
        return $this->exchange($e->origin().'/'.$c->value, $r->command, $r->parameters(), $ms,
            fn () => (new CurlTransport)->sendWithin($e, $c, $r, $key, $ms));
    }
    public function raw(string $origin, string $command, #[\SensitiveParameter] array $parameters, int $ms = 25000): string
    { return $this->exchange($origin, $command, $parameters, $ms)->body(); }
    public static function durableRegistration(array $attempt, string $domain): bool
    {
        return ($attempt['registration']['accepted'] ?? null) === true
            && ($attempt['registration']['query']['name'] ?? null) === $domain
            && ($attempt['registration']['query']['years'] ?? null) === 1;
    }
    public function attempted(string $command): bool { return ($this->sent[$command] ?? 0) > 0; }
    public function remaining(): int { return $this->limit - $this->receipt['request_count']; }
    public function authorizeRenewal(bool $accepted): void { $this->renewalAuthorized = $accepted; }
    public function purchaseAuthorization(\Closure $authorization): void { $this->purchaseAuthorization = $authorization; }
    public function purchaseAttempt(): PurchaseAttempt
    { return $this->purchaseAttempt ?? throw new \RuntimeException('Original purchase evidence unavailable.'); }
    public function renewalBefore(string $expiry): void { $this->renewalBefore = $expiry; }
    public static function purchaseOrigin(string $command, string $origin): string
    {
        $context = match ($command) {
            'registerDomain' => getenv('OTE_REGISTRATION_CONTEXT'), 'renewDomain' => getenv('OTE_RENEWAL_CONTEXT'), default => false,
        };
        return $context === 'interactive' ? Environment::Sandbox->origin().'/api' : $origin;
    }
    private function policy(string $command, #[\SensitiveParameter] array $p): void
    {
        if ($this->reconcile && !in_array($command, ['listOrders', 'orderDetails', 'listDomains', 'getDomainInfo'], true)) {
            throw new \RuntimeException('OTE reconciliation reads only.');
        }
        $keys = match ($command) {
            'getAccountBalance', 'getPrices' => [], 'checkRegisterAvailability' => ['domains'],
            'listOrders' => ['date_from', 'date_to'], 'orderDetails' => ['order_number'], 'listDomains' => ['pageSize', 'page'],
            'getDomainInfo', 'domainLock', 'domainUnlock', 'removeAutoRenewal', 'dnsListRecords' => ['domain'],
            'registerDomain' => ['domain', 'years', 'auto_renew', 'private', 'contact_id', 'ns1', 'ns2'],
            'renewDomain' => ['domain', 'years', 'auto_renew'], 'changeNameServers' => ['domain', 'ns1', 'ns2'],
            'contactAdd' => ['fn','ln','cp','ad','ad2','cy','st','zp','ct','em','ph'], 'contactList' => ['contact_id'],
            'dnsAddRecord' => ['domain','rrtype','rrhost','rrvalue','rrttl'], 'dnsDeleteRecord' => ['domain','rrid'],
            default => throw new \RuntimeException('OTE command refused.'),
        };
        if ($command === 'orderDetails' && !array_key_exists('order_number', $p)) { throw new \RuntimeException('OTE order number required.'); }
        if (array_diff(array_keys($p), $keys)) { throw new \RuntimeException('OTE parameters refused.'); }
        if ($this->continuationReceipt !== null && !in_array($command, ['getPrices', 'getDomainInfo', 'listOrders', 'orderDetails', 'renewDomain'], true)) {
            throw new \RuntimeException('Renewal continuation scope refused.');
        }
        $reads = ['getAccountBalance', 'getPrices', 'checkRegisterAvailability', 'getDomainInfo', 'listOrders', 'orderDetails', 'listDomains', 'contactList'];
        if (!$this->write && !in_array($command, $reads, true)) { throw new \RuntimeException('OTE write flag required.'); }
        if (in_array('domain', $keys, true) && ($p['domain'] ?? null) !== $this->domain) { throw new \RuntimeException('OTE domain scope refused.'); }
        if ($command === 'checkRegisterAvailability' && (!is_string($p['domains'] ?? null) || $p['domains'] === '')) { throw new \RuntimeException('OTE availability scope required.'); }
        if ($command === 'renewDomain' && !$this->renewalAuthorized) { throw new \RuntimeException('OTE durable registration required.'); }
        foreach (['domain', 'domains'] as $f) {
            foreach (isset($p[$f]) ? explode(',', $p[$f]) : [] as $name) {
                if (!in_array($name, [$this->domain, substr($this->domain, 0, -4).'.net'], true)
                    || !preg_match('/^contract-ote-[0-9a-f]{12}\.(com|net)$/D', $name)) { throw new \RuntimeException('OTE name refused.'); }
            }
        }
        foreach (['registerDomain', 'renewDomain'] as $purchase) {
            if ($command === $purchase && (($this->sent[$purchase] ?? 0) > 0 || ($p['domain'] ?? null) !== $this->domain
                || ($p['years'] ?? null) !== 1 || ($p['auto_renew'] ?? null) !== 0
                || ($command === 'registerDomain' && ($p['private'] ?? null) !== 0))) { throw new \RuntimeException('OTE purchase refused.'); }
        }
        foreach (['ns1', 'ns2'] as $f) {
            if (isset($p[$f]) && !in_array(strtoupper($p[$f]), ['NS1.NAMESILO.COM', 'NS2.NAMESILO.COM'], true)) { throw new \RuntimeException('OTE nameserver refused.'); }
        }
        if ($command === 'dnsAddRecord' && (($p['rrtype'] ?? '') !== 'TXT' || ($p['rrhost'] ?? '') !== '_contract'
            || ($p['rrvalue'] ?? '') !== 'synthetic-contract' || ($p['rrttl'] ?? null) !== 3600)) { throw new \RuntimeException('OTE DNS refused.'); }
    }
    private function clearReply(string $command, array $parameters): void
    {
        unset($this->latest[$command]);
        if ($command === 'orderDetails' && (is_string($parameters['order_number'] ?? null) || is_int($parameters['order_number'] ?? null))) {
            unset($this->details[(string) $parameters['order_number']]);
        }
    }
    private function exchange(string $origin, string $command, #[\SensitiveParameter] array $parameters, int $ms, ?\Closure $read = null): TransportResponse
    {
        $this->clearReply($command, $parameters);
        self::origin($origin);
        $origin = self::purchaseOrigin($command, $origin);
        self::origin($origin);
        if ($command === 'renewDomain' && (($origin === Environment::Sandbox->origin().'/api') !== ($this->continuationReceipt !== null))) {
            throw new \RuntimeException('Renewal route requires claimed continuation.');
        }
        $this->timeGuard(); $this->policy($command, $parameters);
        if ($ms < 1 || $ms > 25000 || $this->receipt['request_count'] >= $this->limit || getenv('SSLKEYLOGFILE')) { throw new \RuntimeException('OTE budget refused.'); }
        if (!$this->fake) {
            $wait = 1.01 - (hrtime(true) / 1e9 - $this->last);
            if ($wait > 0) { usleep((int) ceil($wait * 1e6)); }
        }
        $this->timeGuard(); // A spacing wait may cross 18:00 UTC.
        $key = $this->secret->getValue();
        $url = $origin.'/'.$command.'?'.http_build_query(['version' => 1, 'type' => 'json', 'key' => $key] + $parameters, '', '&', PHP_QUERY_RFC3986);
        if (parse_url($url, PHP_URL_HOST) !== 'ote.namesilo.com' || parse_url($url, PHP_URL_SCHEME) !== 'https') { throw new \RuntimeException('OTE resolved host refused.'); }
        $dispatchUtc = gmdate(DATE_ATOM);
        if (in_array($command, ['registerDomain', 'renewDomain'], true)) {
            $money = $this->purchaseAuthorization ? ($this->purchaseAuthorization)($command) : null;
            if (!$money instanceof \Paymenter\Extensions\Servers\Domains\Data\Money) { throw new \RuntimeException('Purchase authorization required.'); }
            $this->purchaseAttempt = PurchaseAttempt::persist($this->output, $this->domain, $money,
                $parameters['years'], '/'.basename($origin), $dispatchUtc, $command, $this->renewalBefore);
            $this->attempts[] = $this->purchaseAttempt;
        }
        $this->last = hrtime(true) / 1e9;
        $this->sent[$command] = ($this->sent[$command] ?? 0) + 1;
        $index = $this->receipt['request_count']++;
        $this->receipt['requests'][$index] = ['command' => $command, 'route' => basename($origin), 'utc' => $dispatchUtc,
            'request' => ['key' => '[redacted]', 'parameter_fields' => array_keys($parameters)], 'http_status' => null, 'code' => null];
        $this->save(); // Record an attempted send even if killed mid-request; never automatically replay.
        try {
            $response = $this->fake ? ($this->fake)($command, $parameters) : ($read ? $read() : $this->curl($url, $ms));
            $body = $response->body();
            $data = strlen($body) <= CurlTransport::BODY_LIMIT ? json_decode($body, true, 24) : null;
            $entry = &$this->receipt['requests'][$index]; $entry['http_status'] = $response->httpStatus;
            $code = $data['reply']['code'] ?? null;
            $entry['code'] = (is_int($code) || is_string($code)) && preg_match('/^[1-9][0-9]{2}$/D', (string) $code) ? (int) $code : null;
            if ($entry['code'] !== 300) {
                $entry['category'] = $entry['code'] === null ? 'unknown_reply' : 'provider_non_success';
                $detail = $data['reply']['detail'] ?? null;
                $detail = is_string($detail) ? $detail : null;
                if ($detail !== null) {
                    foreach (array_unique([$key, rawurlencode($key), urlencode($key), base64_encode($key)]) as $token) {
                        if (str_contains($detail, $token)) { throw new \RuntimeException('Credential-bearing detail refused.'); }
                    }
                    $this->privateDetails[] = $detail;
                }
                PurchaseAttempt::retainDetail($this->output, $command, $entry['code'], $detail);
            }
            // getPrices returns all endings; retain only .com/.net shapes and common envelope.
            $shaped = $data;
            if ($command === 'getPrices' && is_array($data['reply'] ?? null)) {
                $shaped['reply'] = array_intersect_key($data['reply'], array_flip(['code', 'detail', 'com', 'net']));
            }
            $entry['shape'] = Shapes::shape($shaped);
            $diagnosticRequest = match ($command) {
                'getAccountBalance' => RegistrarRequest::balance(), 'getPrices' => RegistrarRequest::prices(),
                'checkRegisterAvailability' => RegistrarRequest::availability(explode(',', $parameters['domains']), false),
                default => null,
            };
            if ($diagnosticRequest !== null) { $entry['parser'] = ParserDiagnostics::inspect($body, $diagnosticRequest, $key); }
            $readRequest = $diagnosticRequest ?? match ($command) {
                'getDomainInfo' => RegistrarRequest::domainInfo($parameters['domain']),
                'listOrders' => RegistrarRequest::listOrders($parameters['date_from'] ?? null, $parameters['date_to'] ?? null),
                'orderDetails' => RegistrarRequest::orderDetails($parameters['order_number']),
                default => null,
            };
            if ($readRequest !== null) {
                $parsed = (new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\ResponseParser)->parse($body, $readRequest, $key);
                $entry['read_outcome'] = $parsed->outcome->name;
                if (in_array($command, ['listOrders', 'orderDetails'], true)) { $entry['history_complete'] = $parsed->value('history_complete'); }
            }
            $shapeErrors = Shapes::check($command, $data, $command === 'checkRegisterAvailability' ? explode(',', $parameters['domains']) : []);
            foreach ($shapeErrors as $error) { $this->mismatch($command, $error); }
            if ($response->httpStatus !== 200) { $this->mismatch($command, 'http_status'); $this->save(); throw new \RuntimeException('OTE HTTP response refused.'); }
            $this->save();
            if ($shapeErrors === [] && ($readRequest === null || $parsed->outcome === \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\Outcome::Success)
                && is_array($data['reply'] ?? null)) {
                $this->latest[$command] = new \SensitiveParameterValue($data);
                if ($command === 'orderDetails') { $this->details[(string) $parameters['order_number']] = new \SensitiveParameterValue($data['reply']); }
            }
            return $response;
        } catch (\Throwable) {
            $this->receipt['requests'][$index]['transport_failed'] = true;
            if (in_array($command, ['registerDomain', 'renewDomain'], true)) { $this->receipt['checks']['purchase_unresolved'] = true; }
            $this->save();
            throw new \RuntimeException('OTE exchange unresolved.');
        } finally { $this->last = hrtime(true) / 1e9; }
    }
    private function curl(#[\SensitiveParameter] string $url, int $ms): TransportResponse
    {
        $handle = curl_init(); $body = '';
        try {
            if ($handle === false || !curl_setopt_array($handle, [CURLOPT_URL => $url, CURLOPT_HTTPGET => true,
                CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_VERBOSE => false, CURLOPT_HEADER => false,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT_MS => min(8000, $ms), CURLOPT_TIMEOUT_MS => $ms,
                CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true, CURLOPT_LOW_SPEED_LIMIT => 32, CURLOPT_LOW_SPEED_TIME => 10,
                CURLOPT_WRITEFUNCTION => static function ($h, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > CurlTransport::BODY_LIMIT) { return 0; } $body .= $chunk; return strlen($chunk);
                }]) || curl_exec($handle) === false) { throw new \RuntimeException('OTE transport incomplete.'); }
            if (parse_url((string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL), PHP_URL_HOST) !== 'ote.namesilo.com') { throw new \RuntimeException('OTE effective host refused.'); }
            return new TransportResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body);
        } finally { if ($handle !== false) { curl_close($handle); } }
    }
    /** Server-memory only; never persist the returned account reply. */
    public function latest(string $command): array { return isset($this->latest[$command]) ? ($this->latest[$command]->getValue()['reply'] ?? []) : []; }
    public function orderDetail(string $id): array { return isset($this->details[$id]) ? $this->details[$id]->getValue() : []; }
    public function mismatch(string $command, string $field): void
    {
        $row = ['command' => $command, 'field' => $field];
        if (!in_array($row, $this->receipt['mismatches'], true)) { $this->receipt['mismatches'][] = $row; }
    }
    public function check(string $name, bool|string|int|null $value): void
    {
        $this->receipt['checks'][$name] = $value;
        if ($value === false && $name !== 'production_history_complete_without_acceptance') { $this->mismatch('readback', $name); }
        $this->save();
    }
    public function save(): void
    {
        $json = json_encode($this->receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
        foreach ($this->attempts as $attempt) { $attempt->assertRedacted($json); }
        foreach ($this->privateDetails as $detail) {
            if ($detail !== '' && str_contains($json, $detail)) { throw new \RuntimeException('Private reply detail leak refused.'); }
        }
        $key = $this->secret->getValue();
        foreach (array_unique([$key, rawurlencode($key), urlencode($key), base64_encode($key)]) as $token) {
            if (str_contains($json, $token)) { throw new \RuntimeException('OTE receipt redaction failed.'); }
        }
        foreach ($this->receipt['requests'] as $r) { if ($r['request']['key'] !== '[redacted]') { throw new \RuntimeException('OTE key capture refused.'); } }
        if (file_put_contents($this->output.'/receipt.json', $json, LOCK_EX) !== strlen($json)) { throw new \RuntimeException('OTE receipt persistence failed.'); }
    }
    public function __debugInfo(): array { return []; }
    public function __serialize(): never { throw new \LogicException('OTE transport is private.'); }
}
