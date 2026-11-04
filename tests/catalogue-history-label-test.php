<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native audit storage and the real writer establish the displayed history contract.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,Edit,Query};
extract(offerCommerceFixture());
Auth::setUser(staffUser(array_keys(Staff::permissions())));
$revision=$ports->readOffers(new Query($binding,['com'],'register'),Context::Staff)[0]->revision;
$ports->apply(Edit::republish($binding,'com'),$revision);
foreach([null,$tlds[0]->id] as $scope){
    $history=TldFacts::history($binding->serverId,$scope);
    same('Prices published',$history[0]['change'],'Background republication uses the existing plain history label');
    same('.com: Prices recalculated using the current pricing and sales settings.',$history[0]['reason'],'History describes the work without claiming a specific price changed');
    same('System',$history[0]['by'],'Background publication keeps its system actor');
    foreach($history as $entry){expect(!str_starts_with($entry['change'],'catalogue_'),'Catalogue history never exposes raw action keys');}
}
$nativeSuccessful=true;echo "Catalogue history labels: ok\n";
