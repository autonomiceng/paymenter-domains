<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Real encrypted models and native Filament rendering are the affected boundaries.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Catalogue\{StaffCatalogue,StaffCommands,Writer,Projection,OfferState};
use Paymenter\Extensions\Servers\Domains\Models\{Tld,Price};
use Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview as Review;
extract(offerCommerceFixture());tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$source=new StaffCatalogue($ports,$clock);$t=$tlds[0]->fresh();

$public=$storefront->offer('com','register',1);$user=commerceUser();$cart=commerceCart($user);
commerceAdd($cart,$public['product'],'purchase-price.com');commerceCheckout($user,$cart);
$d=Paymenter\Extensions\Servers\Domains\Models\Operation::firstOrFail()->domain;
$service=App\Models\Service::findOrFail($d->service_id);$purchase=$service->price;Auth::setUser($actor);
$commands=new StaffCommands(new Writer(new Projection($native),$clock,static fn()=>null,static fn()=>true),$bindings,static fn()=>null);
$r=new Review($actor->id,$binding->serverId,$t->id,'override',Review::ticket($actor->id,$binding->serverId,$t->id,'override',StaffCommands::stamp($binding->serverId,$t->id)),'Change standard renewal price');
$commands->run($r,['mode'=>'fixed','fixed_register'=>'12.00','fixed_renew'=>'14.00','fixed_transfer'=>'13.00']);
$price=Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::for($d->fresh())['Renewal price'];
same('$14.00 / year',$price['value'],'Staff see the current renewal quote instead of the saved purchase price');
same('Standard pricing',$price['sub'],'Current renewal pricing does not claim to be the purchase price');
same($purchase,$service->fresh()->price,'The display does not reprice a purchase');
Livewire\Livewire::actingAs($actor)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$d->id])->assertSee('$14.00 / year')->assertDontSee('Price kept from purchase until the next renewal invoice.');
$nativeSuccessful=true;echo "Current renewal price is visible to staff.\n";
