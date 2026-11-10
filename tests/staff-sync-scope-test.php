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
// Run the action itself: queueing must not turn into a failure notification afterwards.
Illuminate\Support\Facades\Queue::fake();
config(['queue.default'=>'database','queue.connections.database.driver'=>'database','queue.connections.database.retry_after'=>900]);
$probe=new ReflectionProperty(Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::class,'probe');
$probe->setValue(null,[]);
$livewire=new class {public array $mountedActions=['sync'];};
foreach ([(string)$t->tld,null] as $scope) {
    $livewire->mountedActions=['sync'];
    session()->forget('filament.notifications');
    (SettingsActions::syncAll($server,tld:$scope)->getActionFunction())([], $livewire);
    Filament\Notifications\Notification::assertNotified(Filament\Notifications\Notification::make()->success()->title('Price sync queued')->body(SettingsActions::syncQueued($scope)));
    same([],$livewire->mountedActions,'The completed action closes its dialog');
}
// Refused enqueue reports danger only, and still closes the mounted dialog.
config(['queue.connections.database.retry_after'=>1]);
foreach([(string)$t->tld,null] as $scope){
    session()->forget('filament.notifications');$livewire->mountedActions=['sync'];
    (SettingsActions::syncAll($server,tld:$scope)->getActionFunction())([],$livewire);
    $notifications=session('filament.notifications',[]);
    same(1,count($notifications),'Refused sync sends exactly one notification');
    $notice=reset($notifications);
    same(["Couldn't sync prices",'danger',sprintf(Paymenter\Extensions\Servers\Domains\Jobs\SyncPrices::WORKER,75)],
        [$notice['title'],$notice['status'],$notice['body']],'Refused sync sends danger without success');
    same([],$livewire->mountedActions,'Refused sync closes the dialog');
}
$probe->setValue(null,null);
Illuminate\Support\Facades\Queue::assertPushed(Paymenter\Extensions\Servers\Domains\Jobs\SyncPrices::class);
$nativeSuccessful=true;echo "Staff sync scope passed\n";
