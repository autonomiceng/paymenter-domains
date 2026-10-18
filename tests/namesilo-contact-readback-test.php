<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/contract/Shapes.php';
require __DIR__.'/contract/ProfileDiagnostics.php';
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\ContactReadback;
use OteContract\{Shapes, ProfileDiagnostics};
foreach (['US', 'CA'] as $country) {
    expect(ContactReadback::phoneMatches('4155550123', '4155550123', $country), 'Exact phone retained');
    expect(ContactReadback::phoneMatches('415-555-0123', '4155550123', $country), 'Documented hyphen phone has identical digits');
    foreach ([null, 4155550123, '', '415-555-0124', '4155550124', '415-555-012', '415--555-0123',
        '+1.4155550123', '+1-415-555-0123', '(415) 555-0123', '415 555 0123', "415-555-0123\n"] as $bad) {
        same(false, ContactReadback::phoneMatches($bad, '4155550123', $country), 'Unknown or mismatched phone is never equivalent');
    }
}
same(false, ContactReadback::phoneMatches('415-555-0123', '4155550123', 'GB'), 'No inferred international normalization');
same(false, ContactReadback::phoneMatches('415-555-0123', '415555012', 'US'), 'No partial expected identity');
$p = array_fill_keys(array_keys(ProfileDiagnostics::FIELDS), 'invented'); $p['ph'] = '4155550123'; $p['ct'] = 'US';
$row = array_combine(array_values(ProfileDiagnostics::FIELDS), array_values($p));
$row += ['contact_id' => '101', 'default_profile' => '0', 'nickname' => '', 'fax' => '']; $row['phone'] = '415-555-0123';
$d = ProfileDiagnostics::inspect(['contact' => $row], $p, '101');
same(false, $d['fields']['phone']['exact_match'], 'Wire spelling remains distinct');
same(true, $d['identity_match'], 'Shared production equivalence predicate');
same('none', $d['held_by'], 'Documented phone clears this predicate');
$envelope = ['request' => ['operation' => 'contactList', 'ip' => '192.0.2.1'], 'reply' => ['code' => 300, 'detail' => 'synthetic']];
foreach ([$row, [$row]] as $contacts) {
    same([], Shapes::check('contactList', array_replace($envelope, ['reply' => $envelope['reply'] + ['contact' => $contacts]])), 'Singleton and list retain all documented fields');
}
foreach (['missing' => null, 'scalar' => 'invented', 'bad-row' => ['invented']] as $kind => $contacts) {
    $r = $envelope; if ($kind !== 'missing') { $r['reply']['contact'] = $contacts; }
    expect(Shapes::check('contactList', $r) !== [], 'Missing or malformed collection refused');
}
foreach (['phone', 'contact_id', 'email', 'default_profile'] as $field) {
    $bad = $row; unset($bad[$field]); $r = $envelope; $r['reply']['contact'] = $bad;
    expect(Shapes::check('contactList', $r) !== [], 'Missing field remains a mismatch after normalization');
    expect(ProfileDiagnostics::inspect(['contact' => $bad], $p, '101')['held_by'] !== 'none', 'Missing identity never matches');
}
$r = $envelope; $r['reply']['contact'] = $row + ['unexpected_metadata' => 'invented'];
expect(Shapes::check('contactList', $r) !== [], 'Unknown fields are not silently dropped');
echo "NameSilo contact phone equivalence and shape rejection checks passed\n";
