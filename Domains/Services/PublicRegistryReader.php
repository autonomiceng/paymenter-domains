<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Contracts\DnsReader;
use Paymenter\Extensions\Servers\Domains\Dns\{DnsQuery,RegistryContext,RegistryDeferred};
/** Public expiry only. No transfer, availability, billing, contacts or registrar authority. */
final class PublicRegistryReader
{
    public const BOOTSTRAP = 'https://data.iana.org/rdap/dns.json';
    private \Closure $transport;
    private ?array $bootstrap;
    public function __construct(private DnsReader $dns, ?array $bootstrap = null, ?callable $transport = null)
    {
        $this->bootstrap = $bootstrap === null ? null : json_decode(json_encode($bootstrap, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
        $this->transport = $transport === null ? (new Rdap())->pinned(...) : \Closure::fromCallable($transport);
    }
    public function revision(): string { return hash('sha256', json_encode($this->bootstrap, JSON_THROW_ON_ERROR)); }
    public function snapshot(): ?array { return $this->bootstrap; }
    public function read(string $name, RegistryContext $context): array
    {
        $unknown = ['result'=>'unknown','reason'=>'no_expiry','safe_code'=>'expiry_unknown','expiry'=>null,'precision'=>null,'source'=>null,'status'=>[]];
        try {
            if (DnsQuery::hostname($name) !== $name || !str_contains($name, '.')) { return $unknown; }
            $bootstrap = $this->bootstrap ?? $this->request(self::BOOTSTRAP, $context) ?? throw new \RuntimeException();
            if (!is_array($bootstrap['services'] ?? null) || !array_is_list($bootstrap['services']) || !$bootstrap['services']) { throw new \RuntimeException(); }
            $ending = substr($name, strrpos($name, '.') + 1); $endpoint = null; $matches = 0;
            foreach ($bootstrap['services'] as $service) {
                if (!is_array($service) || count($service) !== 2 || !is_array($service[0] ?? null) || !is_array($service[1] ?? null)
                    || !array_is_list($service[0]) || !array_is_list($service[1]) || !$service[0] || !$service[1]) { throw new \RuntimeException(); }
                foreach ($service[0] as $suffix) { if (!is_string($suffix) || !preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D',$suffix)) { throw new \RuntimeException(); } }
                foreach ($service[1] as $url) { if (!is_string($url)) { throw new \RuntimeException(); } }
                if (!in_array($ending, $service[0], true)) { continue; }
                $matches++;
                foreach ($service[1] as $candidate) {
                    if (is_string($candidate) && Rdap::safeUrl($candidate)) { $endpoint = rtrim($candidate, '/'); break; }
                }
            }
            if ($matches > 1) { throw new \RuntimeException(); }
            $this->bootstrap = $bootstrap; // Only validated bootstrap data may become a last-good snapshot.
            if ($matches !== 1 || $endpoint === null) { return array_replace($unknown, ['reason'=>'no_coverage','safe_code'=>'no_coverage']); }
            $data = $this->request($endpoint.'/domain/'.rawurlencode($name), $context, true);
            if ($data === null) { return array_replace($unknown, ['result'=>'not_registered','reason'=>'not_registered','safe_code'=>'not_registered','source'=>$endpoint]); }
            if (($data['objectClassName'] ?? null) !== 'domain' || !is_string($data['ldhName'] ?? null)
                || strtolower($data['ldhName']) !== $name || !is_array($data['events'] ?? null) || !array_is_list($data['events'])) { return $unknown; }
            $expiry = null; $precision = null;
            foreach ($data['events'] as $event) {
                if (!is_array($event)) { return $unknown; }
                if (($event['eventAction'] ?? null) !== 'expiration') { continue; }
                if ($expiry !== null) { return $unknown; }
                [$expiry, $precision] = self::expiration($event['eventDate'] ?? null);
                if ($expiry === null) { return $unknown; }
            }
            if ($expiry === null) { return $unknown; }
            $statuses = is_array($data['status'] ?? null) ? array_slice($data['status'], 0, 100) : [];
            $statuses = array_values(array_filter($statuses, static fn($s) => is_string($s) && preg_match('/\A[a-zA-Z -]{1,64}\z/D', $s)));
            return ['result'=>'facts','reason'=>'facts','safe_code'=>null,'expiry'=>$expiry,'precision'=>$precision,'source'=>$endpoint,'status'=>$statuses];
        } catch (RegistryDeferred) { return array_replace($unknown, ['reason'=>'deferred','safe_code'=>'budget_exhausted']); }
        catch (\Throwable) { return array_replace($unknown, ['reason'=>'endpoint_failed']); }
    }
    private static function expiration(mixed $value): array
    {
        if (!is_string($value)) { return [null,null]; }
        $day = preg_match('/\A\d{4}-\d\d-\d\d\z/D', $value) === 1;
        if (!$day && !preg_match('/\A\d{4}-\d\d-\d\dT(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/D', $value)) { return [null,null]; }
        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC')); $errors = \DateTimeImmutable::getLastErrors();
            if ($errors && ($errors['warning_count'] || $errors['error_count'])) { return [null,null]; }
            return [$day ? $date->format('Y-m-d') : $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'), $day ? 'date' : 'instant'];
        } catch (\Throwable) { return [null,null]; }
    }
    private function pins(string $host, RegistryContext $context): array
    {
        $seen = [];
        for ($hops = 0; $hops <= 3; $hops++) {
            if (isset($seen[$host])) { throw new \RuntimeException(); } $seen[$host] = true;
            $cname = $this->dns->read(new DnsQuery($host, 'CNAME'), $context->dns);
            if (in_array($cname->code,['budget_exhausted','deadline'],true)) { throw new RegistryDeferred(); }
            if (!$cname->known() && $cname->code !== 'ambiguous_empty') { throw new \RuntimeException(); }
            if ($cname->state === 'rrset') {
                if ($hops === 3) { throw new \RuntimeException(); }
                $host = $cname->values()[0]; continue;
            }
            $pins = [];
            foreach (['A','AAAA'] as $type) {
                $answer = $this->dns->read(new DnsQuery($host, $type), $context->dns);
                if (in_array($answer->code, ['budget_exhausted','deadline'], true)) { throw new RegistryDeferred(); }
                foreach ($answer->values() as $ip) {
                    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) || str_starts_with(strtolower($ip), '::ffff:')) { throw new \RuntimeException(); }
                    $pins[] = $ip;
                }
            }
            if ($pins) { return array_values(array_unique($pins)); }
            throw new \RuntimeException();
        }
        throw new \RuntimeException();
    }
    private function request(string $url, RegistryContext $context, bool $domain = false): ?array
    {
        if (!Rdap::safeUrl($url)) { throw new \RuntimeException(); }
        if (!$context->available()) { throw new RegistryDeferred(); }
        $response = null; $reserved = false;
        try {
            if (!$context->reserve($url)) { throw new RegistryDeferred(); } $reserved = true;
            $pins = $this->pins(parse_url($url, PHP_URL_HOST), $context);
            if ($context->dns->remaining()<0.001) { throw new RegistryDeferred(); }
            $response = ($this->transport)($url, min(8000, (int) floor(1000 * $context->dns->remaining())), $pins);
            if (!$context->dns->live()) { throw new RegistryDeferred(); }
            if (!is_array($response) || !is_string($response['body'] ?? null)
                || strlen($response['body']) > 2097152) { throw new \RuntimeException(); }
            if ($domain && ($response['status'] ?? null) === 404 && Rdap::missing($response['body'])) { return null; }
            if (($response['status'] ?? null) !== 200) { throw new \RuntimeException(); }
            // Preserve JSON array/object types before associative decoding (numeric object keys coerce in PHP).
            $shape = json_decode($response['body'], false, 32, JSON_THROW_ON_ERROR);
            if (!$shape instanceof \stdClass) { throw new \RuntimeException(); }
            if (property_exists($shape,'events') && !is_array($shape->events)) { throw new \RuntimeException(); }
            if (property_exists($shape,'services')) {
                if (!is_array($shape->services)) { throw new \RuntimeException(); }
                foreach ($shape->services as $service) {
                    if (!is_array($service) || count($service)!==2 || !is_array($service[0]) || !is_array($service[1])) { throw new \RuntimeException(); }
                }
            }
            $data = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data)) { throw new \RuntimeException(); }
            return $data;
        } catch (\Throwable $e) {
            if ($e instanceof RegistryDeferred || !$context->dns->live()) { $response = ['deferred'=>true]; throw new RegistryDeferred(); }
            throw $e;
        } finally {
            if ($reserved) { $context->completed($url, is_array($response) ? ['status'=>$response['status'] ?? null,'retry_after'=>$response['retry_after'] ?? null,'deferred'=>$response['deferred'] ?? false] : null); }
        }
    }
}
