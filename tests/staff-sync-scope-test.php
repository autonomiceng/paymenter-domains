<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Sync prices on a TLD page says it reads every TLD on the account, and its toast points at where the result shows.
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,Staff};
use Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview as Review;
extract(offerCommerceFixture());tldPanel();Carbon\Carbon::setTestNow($now);$t=$tlds[0];$server=(int)$t->server_id;
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
$originalQueue=config('queue');
config(['queue.default'=>'database','queue.connections.database.driver'=>'database','queue.connections.database.retry_after'=>900]);
$probe=new ReflectionProperty(Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::class,'probe');
$originalProbe=$probe->getValue();$probe->setValue(null,[]);
try {
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
Illuminate\Support\Facades\Queue::assertPushed(Paymenter\Extensions\Servers\Domains\Jobs\SyncPrices::class);
// The installed catalogue branch uses a valid review and refuses a changed retained queue binding.
$probe->setValue(null,null);
expect(Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue::routed()!==null,'The routed refusal uses an installed catalogue');
$queue=Illuminate\Support\Facades\DB::table('domains_observation_queues')->where('server_id',$server);
$savedFingerprint=(clone $queue)->value('account_fingerprint');
$queue->update(['account_fingerprint'=>hash('sha256','synthetic-stale-queue')]);
$queueReads=0;$measureQueue=false;
Illuminate\Support\Facades\DB::connection()->beforeExecuting(static function($query)use(&$queueReads,&$measureQueue):void {
    if($measureQueue&&str_starts_with($query,'select')&&str_contains($query,'"domains_observation_queues"')){++$queueReads;}
});
try {
    foreach([(string)$t->tld,null] as $scope){
        $key=Review::ticket((int)Auth::id(),$server,null,'import',Review::stamp($server,null));
        session()->forget('filament.notifications');$livewire->mountedActions=['sync'];$queueReads=0;$measureQueue=true;
        (SettingsActions::syncAll($server,tld:$scope)->getActionFunction())(['review_key'=>$key],$livewire);
        $notifications=session('filament.notifications',[]);same(1,count($notifications),'A routed refusal emits one notice');$notice=reset($notifications);
        same(["Couldn't sync prices",'danger'],[$notice['title'],$notice['status']],'A routed refusal emits danger without success');
        $measureQueue=false;
        same('Check this page, then try again. If it fails again, open System checks on the registrar account.',$notice['body'],'A routed queue refusal uses safe staff guidance');
        expect($queueReads>0,'The valid review reaches retained observation work before its refusal');
        $history=Review::historyRows(App\Models\Server::findOrFail($server));$last=end($history);
        same(['Sync TLD prices','completed'],[$last['action'],$last['phase']],'The review completed before the retained queue refused sync');
        same([],$livewire->mountedActions,'A routed refusal clears mounted actions');
    }
} finally {$measureQueue=false;$queue->update(['account_fingerprint'=>$savedFingerprint]);}
} finally {$probe->setValue(null,$originalProbe);config(['queue'=>$originalQueue]);}
$nativeSuccessful=true;echo "Staff sync scope passed\n";
