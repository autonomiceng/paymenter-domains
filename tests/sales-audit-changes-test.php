<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native reviewed writes and their visible history.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,Edit,OfferState,Query,StaffCommands};
use Paymenter\Extensions\Servers\Domains\Services\{StaffSettingsReview as Review,StaffSettingsWrites};
$fixture = offerCommerceFixture(); extract($fixture); pinStaffClock($now);
\Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::reset();
[$netBinding, $netOffer] = offerCommerceSecond($fixture);
$actor = staffUser(array_keys(Staff::permissions())); Auth::setUser($actor);
$t = $tlds[0];
$save = static function (array $sell, $target = null) use ($actor, $t): void {
    $t = $target ?? $t;
    $r = new Review($actor->id, $t->server_id, null, 'acceptance',
        Review::ticket($actor->id, $t->server_id, null, 'acceptance', StaffCommands::stamp($t->server_id)), '');
    app(StaffSettingsWrites::class)->run($r, ['sell' => $sell]);
};
$save(['terms' => ['com' => ['transfer' => false]]]);
$history = TldFacts::history($t->server_id, $t->id);
same('.com: transfers off', $history[0]['reason'], 'Transfers-only history names only the changed field');
$intent = OfferState::decode($t->fresh()->catalogue_intent);
same(true, $intent['sell'], 'Transfer edit preserves sell'); same(3, $intent['longest'], 'Transfer edit preserves term');
// A choice-only save has its TLD in account history too, including a single-field no-op.
foreach (['skip' => "Don't sell", 'sell' => 'Sell'] as $choice => $label) {
    $net = Paymenter\Extensions\Servers\Domains\Models\Tld::where('server_id', $netBinding->serverId)->where('tld', 'net')->sole();
    $save(['choices' => ['net' => $choice]], $net);
    $history = TldFacts::history($net->server_id, null);
    same('.net: '.$label, $history[0]['reason'], 'Choice history keeps its TLD and only its changed choice');
    expect(!str_contains($history[0]['reason'], 'not recorded'), 'Current saves retain exact scope');
}
$save(['terms' => ['com' => ['transfer' => false]]]);
$history = TldFacts::history($t->server_id, null);
same('No sales settings changed', $history[0]['change'], 'No-op does not claim a sales change');
same('', $history[0]['reason'], 'No-op invents no changed fields');
// Saving identical normalized defaults is a no-op in account history, even though its revision moves.
$rule = ['mode'=>'only', 'years'=>2, 'transfers'=>false, 'max_cost'=>'12.00'];
$save(['rule'=>$rule]);
// The public catalogue command accepts a valid two-decimal retained cost limit.
$revision = $ports->readOffers(new Query($binding, ['com'], 'register'), Context::Staff)[0]->revision;
$ports->apply(Edit::defaults($binding, 'com', new Paymenter\Extensions\Servers\Domains\Catalogue\Intent(false, 2, false, '12.00')), $revision);
$defaults = OfferState::decode(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$t->server_id)->sole()->catalogue_defaults);
unset($defaults['_revision']);$defaults['maxAnnualCost']='12.0000';
$save(['rule'=>$rule]);
$again = OfferState::decode(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$t->server_id)->sole()->catalogue_defaults);
unset($again['_revision']);
same($defaults,$again,'Repeated default rule keeps the same effective defaults');
$history = TldFacts::history($t->server_id, null);
same('No sales settings changed',$history[0]['change'],'Unchanged defaults do not claim a sales change');
same('',$history[0]['reason'],'Unchanged defaults invent no changed fields');
// Intent can already allow transfers while the chosen registrar sells registration only.
$save(['terms' => ['com' => ['transfer' => true]]]);
$revision = $ports->readOffers(new Query($binding, ['com'], 'register'), Context::Staff)[0]->revision;
$ports->apply(Edit::seller($binding, 'com', ['register']), $revision);
$policy = Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld', 'com');
same(true, OfferState::decode($t->fresh()->catalogue_intent)['transfers'], 'Transfers intent is already on');
same(false, $policy->sole()->transfer_enabled, 'The chosen registrar initially sells registration only');
$save(['terms' => ['com' => ['transfer' => true]]]);
same(true, $policy->sole()->transfer_enabled, 'Transfers On enables the chosen registrar row');
foreach ([null, $t->id] as $scope) {
    $history = TldFacts::history($t->server_id, $scope);
    same('.com: transfers on', $history[0]['reason'], 'Seller-only transfer change names its TLD and changed flag');
}
$nativeSuccessful = true; echo "Sales history records only actual changes with the TLD.\n";
