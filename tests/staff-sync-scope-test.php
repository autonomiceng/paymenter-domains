<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Sync prices on a TLD page says it reads every TLD on the account, and its toast points at where the result shows.
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,Staff};
use Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview as Review;
extract(commerceFixture(true));tldPanel();Carbon\Carbon::setTestNow($now);$t=$tlds[0];$server=(int)$t->server_id;
Auth::setUser(staffUser([Staff::VIEW,Staff::POLICY,Review::permission('import')]));
$page=SettingsActions::syncAll($server,tld:(string)$t->tld);
same('Sync prices for every TLD on this account?',$page->getModalHeading(),'The TLD page names the scope in the question');
same('Gets current costs from the registrar for every TLD this account sells, not only .'.$t->tld.'. It runs in the background.',$page->getModalDescription(),'It says the sync is not only this TLD');
same('Sync prices',SettingsActions::syncAll($server)->getModalHeading(),'The account-wide button keeps its heading');
// The toast points at where the result shows; the worker sends no result notice, so it never promises one.
same('You can keep working. Last updated on this page changes when it finishes.',SettingsActions::syncQueued((string)$t->tld),'The TLD page toast says where the result shows');
same('You can keep working. Check sync progress on What you sell.',SettingsActions::syncQueued(null),'Elsewhere the toast points to What you sell');
$nativeSuccessful=true;echo "Staff sync scope passed\n";
