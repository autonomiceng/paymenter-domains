<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/Support/reserved-fixtures.php';

foreach (['example.com', 'example.net', 'example.org', 'sealed.example.com', 'child.example.net',
    'child.example.org', 'child-placeholder.example.net', 'child-placeholder.net.test',
    'child-placeholder.org.example.com', 'fixture.test', 'nested.fixture.test'] as $name) {
    same($name, reservedFixtureName($name), 'Reserved fixture identity is preserved');
}
foreach(['Child-Placeholder.Example.NET','Child-Placeholder.example.net'] as $name){
    $rejected=false;
    try{reservedFixtureName($name);}catch(InvalidArgumentException){$rejected=true;}
    expect($rejected,'Reserved fixtures require canonical input: '.$name);
}
foreach (['rate-queued.com', 'other.com', 'example-sealed.com', 'timestamp-boundary.com',
    'example.com.evil.com', 'notexample.com', 'invalid..example.com'] as $name) {
    $rejected = false;
    try { reservedFixtureName($name); } catch (InvalidArgumentException) { $rejected = true; }
    expect($rejected, 'Fixture policy must reject '.$name);
}
foreach (['transfer-placeholder.com', 'late-page-placeholder-2.com'] as $name) {
    same($name, placeholderComFixtureName($name), 'Single-label .com placeholder is preserved');
}
foreach (['ordinary.com', 'example.com', 'two.labels-placeholder.com', 'not-placeholder.test'] as $name) {
    $rejected = false;
    try { placeholderComFixtureName($name); } catch (InvalidArgumentException) { $rejected = true; }
    expect($rejected, 'Placeholder .com fixture policy must reject '.$name);
}

/** Extract hostnames from PHP fixture source. */
function fixtureHostnameLiterals(string $source): array
{
    $names = []; $include = false;
    $identifiers = ['admin.domains.manage', 'admin.invoices.update', '_old_input.transfer_code', 'domains.domains.'];
    foreach (token_get_all($source) as $token) {
        if (is_string($token)) { if ($token === ';') { $include = false; } continue; }
        if (in_array($token[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) { $include = true; }
        if (!in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) { continue; }
        $literal = $token[0] === T_CONSTANT_ENCAPSED_STRING ? substr($token[1], 1, -1) : $token[1];
        if (in_array($literal, $identifiers, true)) { continue; }
        if ($include && preg_match('/\A[a-z0-9_.\/-]+\.php\z/i', $literal) === 1) { continue; }
        $literal = preg_replace_callback('~[a-z][a-z0-9+.-]*://[^\s\x27"<>]+~i', static function(array $match) use (&$names): string {
            $host = parse_url($match[0], PHP_URL_HOST);
            if (!is_string($host)) { return $match[0]; }
            $host = str_ends_with($host, '.') ? substr($host, 0, -1) : $host;
            if (preg_match('/\A(?:[a-z0-9_-]+\.)+[a-z][a-z0-9_-]*\z/i', $host) === 1) { $names[] = strtolower($host); }
            return '';
        }, $literal);
        preg_match_all('/(?<![a-z0-9_-])(?:[a-z0-9_-]+\.)+[a-z][a-z0-9_-]*\b/i', $literal, $matches);
        array_push($names, ...array_map('strtolower', $matches[0]));
    }
    return array_values(array_unique($names));
}

/** Namespace policy is separate from deliberate invalid-host validation cases. */
function fixtureHostnameAllowed(string $name, string $fixture = ''): bool
{
    if (preg_match('/(?:^|\.)example\.(?:com|net|org)\z|\.(?:test|example|invalid)\z/', $name) === 1) { return true; }
    if (preg_match('/\A[a-z0-9-]*-placeholder(?:-[0-9]+)?\.com\z/', $name) === 1) { return true; }
    // This exact negative fixture must remain inside its purchased domain.
    if ($fixture === 'owner-actions-test.php' && $name === 'ns1.owner-register-1-placeholder.com') { return true; }
    // Exact public constants matched by the runtime, as permitted by the fixture policy.
    return in_array($name, ['data.iana.org', 'reseller.enom.com', 'resellertest.enom.com',
        'www.namesilo.com', 'ote.namesilo.com', 'dns1.name-services.com', 'dns2.name-services.com',
        'dns3.name-services.com', 'dns4.name-services.com', 'dns5.name-services.com'], true);
}

$probe = <<<'SOURCE'
<?php
require __DIR__.'/Support/fixture.php';
$permissions = ['admin.domains.manage', 'admin.invoices.update'];
$session = '_old_input.transfer_code'; $route = 'domains.domains.';
$names = ['unreserved.io', 'https://nested.unreserved.dev/path', 'staff@mail.unreserved.cloud',
    'fixture.test', 'fixture.example', 'fixture.invalid', 'ns_1.example.net', 'example.com.evil.io'];
SOURCE;
same(['unreserved.io', 'nested.unreserved.dev', 'mail.unreserved.cloud', 'fixture.test', 'fixture.example',
    'fixture.invalid', 'ns_1.example.net', 'example.com.evil.io'], fixtureHostnameLiterals($probe),
    'Scanner finds every hostname suffix and skips only source references and exact identifiers');

foreach (['fixture.test', 'fixture.example', 'fixture.invalid', 'ns_1.example.net', 'child.example.org',
    'transfer-placeholder.com', 'late-page-placeholder-2.com', 'data.iana.org', 'dns1.name-services.com',
    'reseller.enom.com', 'resellertest.enom.com', 'www.namesilo.com', 'ote.namesilo.com'] as $name) {
    same([$name], fixtureHostnameLiterals("<?php \$name = '".$name."';"), 'Allowed hostname is discovered before classification');
    expect(fixtureHostnameAllowed($name), 'Reserved namespace or exact policy exception accepted: '.$name);
}
foreach (['unreserved.io', 'nested.unreserved.dev', 'mail.unreserved.cloud', 'example.com.evil.io',
    'fixture.test.evil.io', 'notexample.com', 'two.labels-placeholder.com', 'unreserved.manage',
    'child.data.iana.org', 'dns6.name-services.com', 'reseller.enom.com.evil.test.io'] as $name) {
    same([$name], fixtureHostnameLiterals("<?php \$name = '".$name."';"), 'Refused hostname reaches the scanner');
    expect(!fixtureHostnameAllowed($name), 'Nonreserved hostname is refused without partial-match exceptions: '.$name);
}
same(['unreserved.php'], fixtureHostnameLiterals("<?php require 'fixture.php'; \$host = 'unreserved.php';"), 'Only source references exclude file-like names');
same([], fixtureHostnameLiterals("<?php require __DIR__.'/Support/fixture.php';"), 'Local PHP source reference is excluded');
same(['unreserved.io'], fixtureHostnameLiterals("<?php require 'https://unreserved.io/fixture.php';"), 'URL include retains its hostname while excluding its source path');
same(['unreserved.io'], fixtureHostnameLiterals("<?php \$url = 'https://unreserved.io/dotted.fixture.php?next=other.example.com';"), 'URL hostname is scanned without treating its path or query as DNS names');
same(['reseller.enom.com'], fixtureHostnameLiterals("<?php \$url = 'https://reseller.enom.com/interface.asp';"), 'Exact endpoint exception scans the host, not the endpoint filename');
foreach (["require '%s';", "\$url = '%s';"] as $statement) {
    foreach ([['unreserved.io.', 'unreserved.io', false], ['fixture.test.', 'fixture.test', true]] as [$host, $expected, $allowed]) {
        $source = '<?php '.sprintf($statement, 'https://'.$host.'/fixture.php');
        same([$expected], fixtureHostnameLiterals($source), 'URL scanner normalizes one terminal DNS root dot');
        same($allowed, fixtureHostnameAllowed($expected), 'Root-dot URL host retains its namespace policy');
    }
}
expect(fixtureHostnameAllowed('ns1.owner-register-1-placeholder.com', 'owner-actions-test.php'), 'Exact self-nameserver fixture remains inside the purchased domain');
expect(!fixtureHostnameAllowed('ns1.owner-register-1-placeholder.com'), 'Self-nameserver exception does not allow arbitrary descendants');

// Scan domain literals in the commerce fixtures that also exercise presentation.
foreach (['invoice-binding-test.php', 'management-privacy-test.php', 'owner-actions-test.php',
    'registration-nameservers-test.php', 'Support/invoice-descriptions.php'] as $fixture) {
    foreach (fixtureHostnameLiterals(file_get_contents(__DIR__.'/'.$fixture)) as $name) {
        expect(fixtureHostnameAllowed($name, $fixture), $fixture.' uses a reserved or explicit placeholder name: '.$name);
    }
}

/** Known IP fixture strings have no escapes; PHP tokens make quote spelling irrelevant. */
function fixtureIpLiterals(string $source): array
{
    $ips = [];
    foreach (token_get_all($source) as $token) {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) { continue; }
        $literal = substr($token[1], 1, -1);
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) { $ips[] = strtolower($literal); }
    }
    return array_values(array_unique($ips));
}

function fixtureIpAllowed(string $ip, string $fixture = ''): bool
{
    if ($ip === '8.8.8.8') { return true; }
    $bytes = @inet_pton($ip);
    if ($bytes === false) { return false; }
    foreach (['192.0.2.0' => 3, '198.51.100.0' => 3, '203.0.113.0' => 3, '2001:db8::' => 4] as $network => $prefix) {
        $reserved = inet_pton($network);
        if (strlen($bytes) === strlen($reserved) && strncmp($bytes, $reserved, $prefix) === 0) { return true; }
    }
    // Exact public IPv6 fixture keeps real global-address admission and IPv6 pinning covered offline.
    if ($fixture === 'psl-transport-test.php' && $ip === '2606:4700:4700::1111') { return true; }
    // Deliberately unsafe DNS answers exercise refusal before any transport dispatch.
    if ($fixture === 'psl-test.php' && $ip === '127.0.0.1') { return true; }
    if ($fixture === 'psl-transport-test.php' && in_array($ip, ['127.0.0.1', '10.0.0.1',
        '169.254.169.254', '100.64.0.1', '192.168.0.1', '::1', 'fc00::1', '::ffff:8.8.8.8'], true)) { return true; }
    // Deliberate invalid glue inputs belong only to this field-error fixture.
    return $fixture === 'owner-record-errors-test.php' && in_array($ip, ['127.0.0.1', '::ffff:8.8.8.8'], true);
}

$ipProbe = <<<'SOURCE'
<?php $ips = ['1.1.1.1', "1.1.1.1", '9.9.9.9', "9.9.9.9"];
SOURCE;
same(['1.1.1.1', '9.9.9.9'], fixtureIpLiterals($ipProbe), 'IP literals are discovered independently of PHP quote spelling');
foreach (['8.8.8.8', '192.0.2.1', '198.51.100.255', '203.0.113.0', '2001:db8::1', '2001:0db8:ffff::1'] as $ip) {
    expect(fixtureIpAllowed($ip), 'Permitted public fixture or documentation range accepted: '.$ip);
}
foreach (['1.1.1.1', '9.9.9.9', '192.0.3.1', '198.51.101.1', '203.0.114.1', '2001:db9::1', '127.0.0.1', '::ffff:8.8.8.8'] as $ip) {
    expect(!fixtureIpAllowed($ip), 'Other public addresses and unscoped negative fixtures are refused: '.$ip);
}
foreach (['127.0.0.1', '::ffff:8.8.8.8'] as $ip) {
    expect(fixtureIpAllowed($ip, 'owner-record-errors-test.php'), 'Existing negative glue fixture is permitted only in its error test');
    expect(!fixtureIpAllowed($ip, 'owner-record-confirmation-test.php'), 'Negative glue exception does not allow positive confirmation fixtures');
}
expect(fixtureIpAllowed('2606:4700:4700::1111', 'psl-transport-test.php'), 'Exact IPv6 pin fixture is permitted');
foreach (['', 'psl-test.php', 'owner-record-confirmation-test.php'] as $fixture) {
    expect(!fixtureIpAllowed('2606:4700:4700::1111', $fixture), 'IPv6 pin exception stays in its transport fixture');
}
expect(!fixtureIpAllowed('2606:4700:4700::1001', 'psl-transport-test.php'), 'IPv6 exception does not allow neighboring public addresses');
foreach (['owner-record-confirmation-test.php', 'owner-record-errors-test.php', 'psl-test.php', 'psl-transport-test.php', 'client-script-render.php'] as $fixture) {
    $ips = fixtureIpLiterals(file_get_contents(__DIR__.'/'.$fixture));
    expect($ips !== [], $fixture.' must contain IP literals for this policy check');
    foreach ($ips as $ip) { expect(fixtureIpAllowed($ip, $fixture), $fixture.' uses a permitted IP fixture: '.$ip); }
}

// Captured reply shapes also carry synthetic DNS names in otherwise unused fields.
foreach (['enom-captured-reads.php', 'enom-captured-renewal.php'] as $fixture) {
    $replies = require __DIR__.'/Support/'.$fixture;
    $count = preg_match_all('/<(dns[1-5]?)>([^<]+)<\/\1>/', implode("\n", $replies), $names);
    expect($count > 0, $fixture.' must include nameservers for this policy check');
    foreach ($names[2] as $name) {
        same($name, reservedFixtureName($name), $fixture.' uses reserved nameservers');
    }
}

// Synthetic .net and .org placeholders must use reserved fixture namespaces.
foreach ([__DIR__, __DIR__.'/Support'] as $directory) {
    $files = glob($directory.'/*.php');
    expect(is_array($files) && $files !== [], 'Fixture policy must scan PHP sources');
    foreach ($files as $file) {
        $count = preg_match_all('/\b[a-z0-9.-]*placeholder[a-z0-9.-]*\.[a-z]{2,}\b/i', file_get_contents($file), $names);
        expect($count !== false, 'Fixture placeholder scan must parse');
        foreach (array_unique($names[0]) as $name) {
            $name = strtolower($name);
            if (!str_ends_with($name, '.net') && !str_ends_with($name, '.org')) { continue; }
            same($name, reservedFixtureName($name), basename($file).' uses reserved .net and .org fixture names');
        }
    }
}
// Variable labels in the paired database fixture also use explicit placeholder opt-in.
$deletionFixture=file_get_contents(__DIR__.'/Support/mariadb-user-deletion.php');
expect(preg_match('/\$name\s*=\s*placeholderComFixtureName\(/',$deletionFixture)===1,
    'MariaDB deletion fixture explicitly validates its generated .com placeholder');
foreach(['intent','checkout','snapshot']as$case){
    same($case.'-user-deletion-placeholder.com',placeholderComFixtureName($case.'-user-deletion-placeholder.com'),'Every deletion case uses a valid synthetic placeholder');
}
echo "Explicit synthetic fixture policy passed; no domain availability lookup.\n";
