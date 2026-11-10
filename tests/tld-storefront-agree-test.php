<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native fixture: staff For sale and the storefront agree. A TLD whose account has Renewals off is not offered to
// customers, so staff see Not for sale with the reason.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts,Projection,StaffCatalogue};
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers;
$fixture = offerCommerceFixture(); extract($fixture); tldPanel(); pinStaffClock($now);
Auth::setUser(staffUser(array_keys(Staff::permissions())));
$com = Tld::where('server_id', $binding->serverId)->where('tld', 'com')->sole();
// One read of both sides: the staff row, the For sale filter and what the storefront offers.
$read = static function (CallerPorts $p) use ($com, $clock, $bindings, $native): array {
    $staff = new StaffCatalogue($p, $clock);
    return ['staff' => TldFacts::snapshot($com->fresh(), null, $staff), 'filter' => $staff->saleIds(collect([$com->fresh()])),
        'store' => array_keys((new AcquisitionOffers($p, $bindings, $native))->display(['com'])['com'] ?? [])];
};
$start = $read($ports);
same('For sale', $start['staff']['sales']['label'], 'Start: staff see .com for sale');
same([(int) $com->id], $start['filter'], 'Start: the For sale filter keeps .com');
expect(in_array('register:1', $start['store'], true), 'Start: the storefront offers .com');

// The account sends registrations but its Renewals are off, as on the demo NameSilo account.
$noRenewals = new CallerPorts(new Projection($native), $clock, $bindings, offerCommerceAuthorize(),
    static fn ($b, ?string $action): bool => $action !== 'renew');
$off = $read($noRenewals);
same([], $off['store'], 'Renewals off: the storefront does not offer .com');
same(['Not for sale', 'Renewals are off for this account'], [$off['staff']['sales']['label'], $off['staff']['sales']['reason']],
    'Renewals off: staff see Not for sale and why, as the storefront does');
same(['register' => false, 'transfer' => false], $off['staff']['acquisition'], 'Renewals off: the account counts no sales for .com');
same([], $off['filter'], 'Renewals off: the For sale filter leaves .com out');
same('Turn on Renewals on the registrar account page.', TldFacts::fixText('Renewals are off for this account'), 'Renewals off: the TLD list says what to do');

$nativeSuccessful = true;
