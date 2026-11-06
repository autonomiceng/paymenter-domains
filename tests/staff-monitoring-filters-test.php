<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native staff monitoring over synthetic local evidence.
declare(strict_types=1);
require __DIR__.'/Support/staff-monitoring.php';
use Illuminate\Support\Facades\{Auth,Crypt,DB,Route};
use Livewire\Livewire;
use Filament\Facades\Filament;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{WatchResource,RoutingProfileResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\{ListWatches,ViewWatch};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\{ListConfiguration as ListTemplates,ViewConfiguration as ViewTemplate};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,WatchFacts,WatchFindings};
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites as Writes;
DB::table('domains_watch_names')->where('id',$id)->update(['state'=>'retired']);
// Apply the real table filters, including Filament's deferred filter state.
// Stopped includes both retained states, and excludes active and paused records.
DB::beginTransaction();
$activeFilter=$makeWatch('active-filter.example',$uid);$pausedFilter=$makeWatch('paused-filter.example',$uid,'paused');
$otherFilter=$makeWatch('other-filter.example',(int)$other->id);
$linkedFilter=$makeLinked('linked-filter.example');$retiredFilter=WatchName::findOrFail($id);
$stopped=$applyFilters(Livewire::actingAs($manager)->test(ListWatches::class),['status'=>'stopped']);
$stopped->assertCanSeeTableRecords([$retiredFilter,$linkedFilter])->assertCanNotSeeTableRecords([$activeFilter,$pausedFilter,$otherFilter])->assertCountTableRecords(2);
$applyFilters(Livewire::actingAs($manager)->test(ListWatches::class),['status'=>'paused'])
    ->assertCanSeeTableRecords([$pausedFilter])->assertCanNotSeeTableRecords([$retiredFilter,$linkedFilter,$activeFilter,$otherFilter])->assertCountTableRecords(1);
// Name and email searches require the current native permission, even with a stale loaded role.
DB::table('users')->where('id',$uid)->update(['first_name'=>'Mira','last_name'=>'Filtermatch','email'=>'mira-filter@example.test']);
$viewer=staffUser([Staff::VIEW,Writes::VIEW,'admin.users.view']);$loadedRole=$viewer->role;
$ownedFilter=[$retiredFilter,$linkedFilter,$activeFilter,$pausedFilter];
$searchCustomer=static fn(string $term)=>$applyFilters(Livewire::actingAs($viewer)->test(ListWatches::class),['customer'=>['customer'=>$term]]);
foreach(['Mira','Filtermatch','mira-filter@example.test'] as $term){$searchCustomer($term)->assertCanSeeTableRecords($ownedFilter)->assertCanNotSeeTableRecords([$otherFilter])->assertCountTableRecords(4);}
$searchCustomer('no-such-customer@example.test')->assertCountTableRecords(0);
DB::table('roles')->where('id',$viewer->role_id)->update(['permissions'=>json_encode([Staff::VIEW,Writes::VIEW])]);
expect(in_array('admin.users.view',$loadedRole->permissions,true),'Customer filter actor retains a stale role with the revoked permission');
Auth::setUser($viewer->setRelation('role',$loadedRole));
foreach(['Mira','Filtermatch','mira-filter@example.test'] as $term){same([],WatchFindings::customer(WatchName::query(),$term)->pluck('id')->all(),'Customer text lookup refreshes the retained actor after permission revocation');}
foreach(['Mira','Filtermatch','mira-filter@example.test'] as $term){$searchCustomer($term)->assertCanNotSeeTableRecords([...$ownedFilter,$otherFilter])->assertCountTableRecords(0);}
$searchCustomer((string)$uid)->assertCanSeeTableRecords($ownedFilter)->assertCanNotSeeTableRecords([$otherFilter])->assertCountTableRecords(4);
$searchCustomer((string)$other->id)->assertCanSeeTableRecords([$otherFilter])->assertCanNotSeeTableRecords($ownedFilter)->assertCountTableRecords(1);
DB::table('roles')->where('id',$viewer->role_id)->update(['permissions'=>json_encode([Staff::VIEW,Writes::VIEW,'admin.users.view'])]);
foreach(['Mira','mira-filter@example.test'] as $term){$searchCustomer($term)->assertCanSeeTableRecords($ownedFilter)->assertCanNotSeeTableRecords([$otherFilter])->assertCountTableRecords(4);}
DB::rollBack();Auth::setUser($manager);

$nativeSuccessful=true;echo "Staff monitoring customer and status filters passed\n";
