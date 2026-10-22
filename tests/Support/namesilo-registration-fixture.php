<?php
// Synthetic NameSilo registration setup; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/registration-fixture.php';
use Paymenter\Extensions\Servers\Domains\Models\Domain;
$sellers->change('com', 2, $tlds[1]->server_id, true, true, $clock->now()); $nsProduct = $native->product($tlds[1], 'register');
$nsNew = static function ($name) use ($nsProduct, $user) {
    $cart = commerceCart($user); commerceAdd($cart, $nsProduct, $name); commerceCheckout($user, $cart);
    $op = Domain::where('domain', $name)->sole()->operations()->sole();
    Illuminate\Support\Facades\DB::table('invoices')->where('id', $op->invoice_id)->update(['status' => 'paid']);
    App\Models\InvoiceTransaction::create(['invoice_id' => $op->invoice_id, 'amount' => $op->amount, 'fee' => '0.00', 'status' => 'succeeded']); return $op;
};
$binding = $bindingFor(1);
$exchange = static function ($origin, $command, $parameters, $timeout) use ($registration): string {
    same(0, Illuminate\Support\Facades\DB::transactionLevel(), 'All profile/purchase/follow-up work outside locks');
    same('https://ote.namesilo.com/apibatch', $origin, 'Fixed batch route'); expect($timeout > 0 && $timeout <= 25000, 'Bounded request');
    $registration->calls[] = $command; $reply = ['code' => 300, 'detail' => 'synthetic'];
    if ($command === 'contactAdd') { $registration->profile = $parameters; $reply['contact_id'] = (string) ++$registration->id; }
    elseif ($command === 'contactList') {
        $fields = ['first_name', 'last_name', 'company', 'address', 'address2', 'city', 'state', 'zip', 'country', 'email', 'phone'];
        $row = array_combine($fields, array_values($registration->profile));
        $row += ['contact_id' => (string) $registration->id, 'default_profile' => $registration->mode === 'default' ? '1' : '0'];
        if ($registration->mode === 'wrongcontact') { $row['email'] = 'other@example.com'; }
        if (in_array($registration->mode, ['hyphenphone', 'wrongphone'], true)) {
            $row['phone'] = substr($row['phone'], 0, 3).'-'.substr($row['phone'], 3, 3).'-'.substr($row['phone'], 6);
            if ($registration->mode === 'wrongphone') { $row['phone'][0] = $row['phone'][0] === '2' ? '3' : '2'; }
        }
        if ($registration->mode === 'missingphone') { unset($row['phone']); }
        $reply['contact'] = $row;
        if ($registration->mode === 'authoritydrift') {
            $current = Domain::where('domain', $registration->query->name)->sole()->operations()->sole();
            $current->update(['policy' => $current->policy + ['diagnostic_drift' => true]]);
        }
    } elseif ($command === 'registerDomain') {
        same((string) $registration->id, $parameters['contact_id'], 'Fresh proven profile only');
        same(0, $parameters['auto_renew'], 'Purchase disables auto-renew'); same(0, $parameters['private'], 'Frozen privacy policy');
        if ($registration->mode === 'unknown') { return '{}'; }
        $reply += ['domain' => $parameters['domain'], 'order_amount' => $registration->mode === 'amount' ? '1000000000000000000.00' : '99.00'];
        $reply['code'] = in_array($registration->mode, ['301', '302'], true) ? (int) $registration->mode : 300;
    } elseif ($command === 'getDomainInfo') {
        if ($registration->mode === 'lost') { throw new RuntimeException('Synthetic readback loss'); }
        $removed = in_array('removeAutoRenewal', $registration->calls, true);
        $reply += ['created' => '2026-10-18', 'expires' => '2027-10-18', 'status' => 'Active', 'locked' => 'Yes', 'private' => 'No',
            'auto_renew' => $registration->mode === 'stillon' || ($registration->mode === 'remove' && !$removed) ? 'Yes' : 'No',
            'nameservers' => [['nameserver' => 'ns2.provider.example'], ['nameserver' => 'ns1.provider.example']],
            'contact_ids' => array_fill_keys(['billing', 'technical', 'registrant', 'administrative'], (string) $registration->id)];
        if ($registration->mode === 'mismatch') { $reply['nameservers'][0]['nameserver'] = 'ns3.provider.example'; }
        if ($registration->mode === 'badns') { $reply['nameservers'] = ['nameserver' => 'invalid']; }
    } elseif ($command !== 'removeAutoRenewal') { throw new LogicException('Unexpected protocol command'); }
    return json_encode(['request' => ['operation' => $command], 'reply' => $reply], JSON_THROW_ON_ERROR);
};
