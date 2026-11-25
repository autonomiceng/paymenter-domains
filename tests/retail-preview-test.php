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
// Edit prices shows, per row, the amount customers pay after saving.
$after=static function($page):array{$dom=new DOMDocument;@$dom->loadHTML($page->effects['partials']['action-modals.0']??'');$out=[];
    foreach((new DOMXPath($dom))->query('//*[contains(@class,"domains-price-preview")]//tbody/tr') as $tr){$cells=$tr->getElementsByTagName('td');$out[trim($tr->getElementsByTagName('th')->item(0)->textContent)]=trim($cells->item($cells->length-1)->textContent);}
    return $out;};
// Fixed prices below cost (register $6, renew $8) show as entered; three years is one registration and two renewals.
$page=Livewire\Livewire::actingAs(Illuminate\Support\Facades\Auth::user())->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld::class,['record'=>$tlds[0]->id])->mountAction('override')
    ->setActionData(['pricing'=>'custom','mode'=>'fixed','fixed_register'=>'5.17','fixed_renew'=>'7.29','fixed_transfer'=>'9.41'])->call('$refresh');
expect(str_contains($page->effects['partials']['action-modals.0']??'','First registration year (USD)'),'The native fixed-price modal labels the first registration year');
same(['Register, 1 year'=>'$5.17','Register, 3 years'=>'$19.75','Renew, 1 year'=>'$7.29','Transfer, 1 year'=>'$9.41'],$after($page),'Edit prices shows the fixed amounts staff entered');
// Use Default pricing shows the default staff saved (cost + $2.00), not the published prices or the fixed fields.
saveDefaultRule(['mode'=>'markup_amount','markup'=>'2.00','round_up'=>'none']);
$page->setActionData(['pricing'=>'default'])->call('$refresh');
same(['Register, 1 year'=>'$8.00','Register, 3 years'=>'$28.00','Renew, 1 year'=>'$10.00','Transfer, 1 year'=>'$9.00'],$after($page),'Edit prices shows the Default pricing amounts');
// Saving Edit prices from the TLD page publishes the amounts its preview showed.
$data=['pricing'=>'custom','mode'=>'markup_amount','markup'=>'1.20','round_up'=>'half'];
$expected=array_column(StaffCatalogue::routed()->preview($tlds[0],Paymenter\Extensions\Servers\Domains\Services\PricingRule::validate($data)),'new');
$page=Livewire\Livewire::actingAs(Auth::user())->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld::class,['record'=>$tlds[0]->id])
    ->mountAction('override')->setActionData($data)->call('$refresh');
$modal=$page->effects['partials']['action-modals.0']??'';foreach($expected as $amount){expect(str_contains($modal,'$'.$amount),'Edit prices previews $'.$amount);}
$page->setActionData([...$data,'reason'=>'Publish reviewed prices'])->callMountedAction()->assertHasNoActionErrors()->assertNotified('Prices published');
same($expected[0],$prices->current($tlds[0]->server_id,$tlds[0]->account_fingerprint,'com','register',1,'USD',$now)->retail,'Saving from the dialog publishes the previewed price');
$nativeSuccessful=true;echo "Native multi-year retail preview agrees with publication.\n";
