<?php
// Enom 444f31c DomainManager validation; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Services\ContactDetails;
class ManagementPayload
{
    public static function normalize(string $action, #[\SensitiveParameter] array $p, string $name, string $registrar): array
    {
        if ($action === 'nameservers') { return ['nameservers' => array_values(self::nameservers($p['nameservers'] ?? [])) ?: throw new \DomainException('Nameservers required.')]; }
        if ($action === 'lock') { if (!is_bool($p['locked'] ?? null)) { throw new \DomainException('Lock state required.'); } return ['locked' => $p['locked']]; }
        if ($action === 'contacts') {
            $role = $p['role'] ?? 'all'; if (!in_array($role, [...ContactDetails::ROLES, 'all'], true)) { throw new \DomainException('Invalid contact role.'); }
            $input=$p['contact']??[]; $extras=array_intersect_key($input,array_flip(['job_title','phone_ext','fax']));
            foreach($extras as $v){if(!is_string($v)||strlen($v)>255||preg_match('/[\x00-\x1f\x7f]/',$v)){throw new \DomainException('Invalid optional contact field.');}}
            $all = ContactDetails::validate($registrar, array_fill_keys(ContactDetails::ROLES, array_diff_key($input,$extras)));
            return ['role' => $role, 'contact' => $all['registrant']+array_map('trim',$extras)];
        }
        if (in_array($action, ['privacy_enable', 'privacy_disable', 'privacy_purchase', 'privacy_renew', 'auto_renew_off', 'auth_code_email'], true)) { return []; }
        if (str_starts_with($action, 'glue_')) {
            $host = strtolower(rtrim(self::text($p['host'] ?? '', 253), '.'));
            if (!str_ends_with($host, '.'.$name) || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) { throw new \DomainException('Glue must be a child of this domain.'); }
            if ($action === 'glue_delete') { return ['host' => $host]; }
            $ip = self::text($p['ip'] ?? '', 64); self::publicIp($ip); return ['host' => $host, 'ip' => strtolower($ip)];
        }
        if (in_array($action, ['ds_add', 'ds_delete'], true)) { return ['ds' => self::ds($p['ds'] ?? [])]; }
        if (in_array($action, ['dns', 'srv'], true)) {
            $rows = $p['records'] ?? null; $digest = $p['preimage_digest'] ?? null;
            if (!is_array($rows) || !array_is_list($rows) || !$rows || count($rows) > 50 || !is_string($digest) || !preg_match('/^[a-f0-9]{64}$/D', $digest)) { throw new \DomainException('Complete DNS list and preimage required.'); }
            return ['records' => array_map(fn (#[\SensitiveParameter] $row) => $action === 'dns' ? self::host($row) : self::srv($row), $rows), 'preimage_digest' => $digest];
        }
        throw new \DomainException('Unsupported management action.');
    }
    public static function nameservers(#[\SensitiveParameter] array $hosts): array
    {
        if(!array_is_list($hosts)||count($hosts)<2||count($hosts)>13){throw new \DomainException('Invalid nameservers.');}
        $out=[];foreach($hosts as $host){$host=strtolower(rtrim(self::text($host,254),'.'));if(!filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)||!str_contains($host,'.')||in_array($host,$out,true)){throw new \DomainException('Invalid nameserver.');}$out[]=$host;}return $out;
    }
    public static function text(#[\SensitiveParameter] mixed $v, int $limit): string
    { if (!is_string($v) || $v === '' || strlen($v) > $limit || preg_match('/[\x00-\x1f\x7f]/', $v)) { throw new \DomainException('Invalid management text.'); } return $v; }
    public static function integer(#[\SensitiveParameter] mixed $v, int $max = 65535): int
    {
        if ((!is_string($v) && !is_int($v)) || !preg_match('/^[0-9]+$/D', (string) $v)) { throw new \DomainException('Invalid record number.'); }
        $n = ltrim((string) $v, '0') ?: '0'; $m = (string) $max;
        if (strlen($n) > strlen($m) || strlen($n) === strlen($m) && strcmp($n, $m) > 0) { throw new \DomainException('Record number out of range.'); } return (int) $n;
    }
    public static function ds(#[\SensitiveParameter] array $r): array
    {
        $a = self::integer($r['algorithm'] ?? null); $t = self::integer($r['digest_type'] ?? null); $d = strtoupper(self::text($r['digest'] ?? '', 64));
        if (!in_array($a, [3, 5, 7, 8, 10, 12, 13, 14], true) || !in_array($t, [1, 2], true) || !ctype_xdigit($d) || strlen($d) !== ($t === 1 ? 40 : 64)) { throw new \DomainException('Unsupported DS tuple.'); }
        return ['algorithm' => $a, 'digest_type' => $t, 'digest' => $d, 'key_tag' => self::integer($r['key_tag'] ?? null)];
    }
    private static function fqdn(#[\SensitiveParameter] string $v): bool
    { return strlen($v) <= 254 && str_ends_with($v, '.') && filter_var(substr($v, 0, -1), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) && str_contains(substr($v, 0, -1), '.'); }
    public static function host(#[\SensitiveParameter] array $r): array
    {
        $name = self::text($r['name'] ?? '', 253); $type = strtoupper(self::text($r['type'] ?? '', 10)); $address = self::text($r['address'] ?? '', 2048);
        if (!preg_match('/^(?:@|\*|(?:\*\.)?[a-z0-9_-]+(?:\.[a-z0-9_-]+)*\.?)$/iD', $name)
            || !in_array($type, ['A', 'AAAA', 'CNAME', 'URL', 'FRAME', 'MX', 'MXE', 'TXT'], true)
            || (in_array($type, ['A', 'MXE'], true) && !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
            || ($type === 'AAAA' && !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
            || (in_array($type, ['CNAME', 'MX'], true) && !self::fqdn($address))
            || (in_array($type, ['URL', 'FRAME'], true) && (!filter_var($address, FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($address, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)))) { throw new \DomainException('Invalid DNS record.'); }
        return ['name' => $name, 'type' => $type, 'address' => $address, 'mx_pref' => $type === 'MX' ? self::integer($r['mx_pref'] ?? 10) : 10];
    }
    public static function srv(#[\SensitiveParameter] array $r): array
    {
        $s = self::text($r['service'] ?? '', 14); $p = strtolower(self::text($r['protocol'] ?? '', 3)); $t = self::text($r['target'] ?? '', 254);
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,12}[a-z0-9])?$/D', $s) || !in_array($p, ['tcp', 'udp'], true) || !self::fqdn($t)) { throw new \DomainException('Invalid SRV record.'); }
        $row = ['service' => $s, 'protocol' => $p, 'target' => $t]; foreach (['priority', 'weight', 'port'] as $f) { $row[$f] = self::integer($r[$f] ?? null); }
        if (isset($r['host_id'])) { $row['host_id'] = self::integer($r['host_id'], PHP_INT_MAX); } return $row;
    }
    public static function publicIp(#[\SensitiveParameter] string $ip): void
    {
        $b = filter_var($ip, FILTER_VALIDATE_IP) ? @inet_pton($ip) : false;
        if ($b === false) { throw new \DomainException('Invalid glue address.'); }
        $ranges = strlen($b) === 4 ? ['0.0.0.0/8','10.0.0.0/8','100.64.0.0/10','127.0.0.0/8','169.254.0.0/16','172.16.0.0/12','192.0.0.0/24','192.0.2.0/24','192.168.0.0/16','198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','224.0.0.0/3']
            : ['::/127','::ffff:0:0/96','64:ff9b::/96','100::/64','2001:db8::/32','2001::/23','fc00::/7','fe80::/10','ff00::/8'];
        foreach ($ranges as $range) { [$network, $bits] = explode('/', $range); $n = inet_pton($network); $bytes = intdiv((int) $bits, 8); $rest = (int) $bits % 8;
            if (strncmp($b, $n, $bytes) === 0 && ($rest === 0 || ((ord($b[$bytes]) ^ ord($n[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0)) { throw new \DomainException('Glue requires a public address.'); }
        }
    }
    public static function canonical(string $kind, #[\SensitiveParameter] array $rows): array
    {
        $rows = array_map(function (#[\SensitiveParameter] $r) use ($kind) {
            if ($kind === 'dns') { $r = self::host($r); $r['name'] = strtolower(rtrim($r['name'], '.')); if (in_array($r['type'], ['CNAME', 'MX'], true)) { $r['address'] = strtolower($r['address']); } }
            elseif ($kind === 'srv') { $r = self::srv($r); unset($r['host_id']); $r['target'] = strtolower($r['target']); }
            else { $r = self::ds($r); }
            ksort($r); return $r;
        }, $rows); usort($rows, fn ($a, $b) => strcmp(json_encode($a), json_encode($b))); return $rows;
    }
    public static function digest(#[\SensitiveParameter] array $rows, string $kind = 'dns'): string { return hash('sha256', json_encode(self::canonical($kind,$rows), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)); }
}
