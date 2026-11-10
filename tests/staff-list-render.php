<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Renders the staff Domains list with a long name, customer and note; no public I/O.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-panel.php';require __DIR__.'/Support/panel-render.php';
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ListDomains;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
$out=renderOutput($argv[1]??null);
staffPanel();panelTheme($fixture);
[$held,$active,$long]=staffData();
// The long name is a held transfer, so its row also has a Note. A long customer name fills the 120px column.
// The binding is immutable, so the customer takes the row's synthetic user id.
(new App\Models\User)->forceFill(['id'=>$long->user_id,'first_name'=>'Maximiliana-Alexandrina','last_name'=>'Featherstonehaugh-Worthington',
    'email'=>'long-customer@example.test','password'=>'synthetic-password'])->save();
Auth::setUser(staffUser([Staff::VIEW]));
writePanelPage($out.'/staff-domains-list.html',Livewire::test(ListDomains::class));
$nativeSuccessful=true;echo "Staff Domains list rendered.\n";
