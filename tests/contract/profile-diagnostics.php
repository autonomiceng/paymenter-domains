<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use OteContract\ProfileDiagnostics;
$p = array_fill_keys(array_keys(ProfileDiagnostics::FIELDS), 'invented-contact-secret');
$row = array_fill_keys(array_values(ProfileDiagnostics::FIELDS), 'invented-contact-secret') + ['contact_id' => '101', 'default_profile' => '0'];
$d = ProfileDiagnostics::inspect(['contact' => $row], $p, '101');
same('object', $d['shape'], 'Singleton object already supported by production');
same('none', $d['held_by'], 'All exact predicates pass');
same('list', ProfileDiagnostics::inspect(['contact' => [$row]], $p, '101')['shape'], 'List shape independently named');
foreach (ProfileDiagnostics::FIELDS as $wireField => $field) {
    foreach (['missing', 'null', 'other-type', 'string-mismatch'] as $category) {
        $bad = $row;
        if ($category === 'missing') { unset($bad[$field]); }
        else { $bad[$field] = match ($category) { 'null' => null, 'other-type' => 7, default => 'other-contact-secret' }; }
        $d = ProfileDiagnostics::inspect(['contact' => $bad], $p, '101');
        same('field.'.$field, $d['held_by'], 'Exact first failed identity predicate');
        same($category, $d['fields'][$field]['category'], 'Closed field category');
        same(false, $d['fields'][$field]['match'], 'Missing/malformed/mismatched fields cannot match');
    }
}
foreach (['contact_id', 'default_profile'] as $field) {
    $bad = $row; unset($bad[$field]);
    same($field, ProfileDiagnostics::inspect(['contact' => $bad], $p, '101')['held_by'], 'ID/default presence gates');
}
$bad = $row; $bad['default_profile'] = 'No';
same('no-string', ProfileDiagnostics::inspect(['contact' => $bad], $p, '101')['default_category'], 'Flag form named without accepting it');
$numericId = $row; $numericId['contact_id'] = 101.0;
$numeric = ProfileDiagnostics::inspect(['contact' => $numericId], $p, '101');
same(false, $numeric['id_type_ok'], 'Unaccepted structural ID type remains visible');
same('none', $numeric['held_by'], 'Diagnostic mirrors current production string-cast predicate');
$inventory = ProfileDiagnostics::inventory(['contact' => [$row]], $p);
same(false, $inventory['production_proof'], 'Inventory is never creation proof');
same(false, $inventory['rows'][0]['id_comparable'], 'No invented acknowledgement correlation');
foreach (['invented-contact-secret', 'other-contact-secret', '101'] as $secret) {
    expect(!str_contains(json_encode([$d, $inventory]), $secret), 'Contact diagnostics omit values and IDs');
}
