<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Pricing,StaffCatalogue};
extract(commerceFixture(true,registrars:['enom']));staffSalesFixture();tldPanel();pinStaffClock($now);
Auth::setUser(staffUser(array_keys(Staff::permissions())));
$rows=StaffCatalogue::routed()->preview($tlds[0],Pricing::rule($tlds[0]));
$long=array_values(array_filter($rows,static fn($row)=>$row['action']==='register'&&$row['term']===3));
same(1,count($long),'The native edit form has an exact three-year registration row');
expect($long[0]['current']!==null&&$long[0]['new']!==null,'Price preview uses the first registration year and renewal input');
same($long[0]['current'],$long[0]['new'],'Unchanged rule preview agrees with the published multi-year retail');
Livewire\Livewire::actingAs(Illuminate\Support\Facades\Auth::user())->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld::class,['record'=>$tlds[0]->id])->mountAction('override')->setActionData(['pricing'=>'custom','mode'=>'fixed'])->call('$refresh')->assertSee('First registration year (USD)');
$nativeSuccessful=true;echo "Native multi-year retail preview agrees with publication.\n";
