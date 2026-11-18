<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-monitoring.php';
require_once __DIR__.'/Support/site-dns-default.php';
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\ViewWatch;
use Paymenter\Extensions\Servers\Domains\Models\{WatchName, WatchProfile};
use Paymenter\Extensions\Servers\Domains\Services\{WatchNoticePreferences, WatchNoticeTarget};

config(['domains.site_dns_default.nameservers'=>['NS2.platform.test.', 'ns1.platform.test']]);
siteDnsFixture($profile->id);
$watchQuery = DB::table('domains_watch_names')->where('id', $id);
$watchQuery->update(['ns_profile_id'=>null, 'profile_revision'=>0, 'expected_dns'=>null]);
$mount = fn () => Livewire::actingAs($manager)->test(ViewWatch::class, ['record'=>$id])
    ->mountAction(TestAction::make('profile')->schemaComponent('sheet','content'));
$stored = fn () => array_values((array) (clone $watchQuery)->first(['ns_profile_id', 'profile_revision', 'expected_dns', 'revision']));
$providerState = fn () => [
    DB::table('domains_operations')->count(),
    DB::table('domains_watch_notices')->count(),
    DB::table('domains_watch_observations')->count(),
];
$initialProviderState = $providerState();
$consent = WatchNoticePreferences::read($uid, $watch->canonical_name);
$before = $stored();
$form = $mount();
$form->callMountedAction()->assertHasNoActionErrors();
same($before, $stored(), 'Saving the inherited choice preserves the null reference, intent and observation revision');
$pin = 'deployment:'.$profile->id.':'.$profile->revision;
$form = $mount()->set('mountedActions.0.data.assignment', $pin);
same('platform', $form->get('mountedActions.0.data.expected_dns'), 'Deliberately pinning the configured host set suggests matching');
$preview = $form->html().implode('', $form->effects['partials'] ?? []);
foreach ($profile->readProfile()->nameservers as $host) { expect(str_contains($preview, $host),'The assignment previews each exact nameserver'); }
$form->callMountedAction()->assertHasNoActionErrors();
same(true, WatchNoticeTarget::capture('watch', $id, $uid, $now, false)['expected'], 'Mismatch confirmation applies after an explicit matching assignment');
same([$profile->id, $profile->revision, 'platform', $before[3]+1], $stored(), 'Saving the explicit platform choice pins the previewed revision');
$mount()->set('mountedActions.0.data.expected_dns','external')->callMountedAction();
$before = $stored();
$form = $mount();
same('external', $form->get('mountedActions.0.data.expected_dns'), 'Reopening preserves the external override');
$form->callMountedAction();
same($before, $stored(), 'Reconfirming an external override leaves confirmed observations alone');
$target = WatchNoticeTarget::capture('watch', $id, $uid, $now, false);
same(false, $target['expected'], 'External override suppresses mismatch and restored notices');
same($consent, WatchNoticePreferences::read($uid, $watch->canonical_name), 'Staff assignment preserves customer alert consent');

$other = DB::transaction(fn () => WatchProfile::create(['name'=>'Other deployment', 'enabled'=>true, 'nameservers'=>['ns.other.test'],
    'website_endpoints'=>[], 'email_endpoints'=>[], 'edited_by'=>$manager->id, 'reason'=>'Synthetic other template']));
DB::table('domains_watch_profiles')->where('id', $other->id)->update(['nameservers'=>json_encode($profile->nameservers)]);
$otherKey = 'pin:'.$other->id.':'.$other->revision;
$form = $mount()->set('mountedActions.0.data.assignment',$otherKey);
same('external',$form->get('mountedActions.0.data.expected_dns'), 'Changing to another template keeps the saved external override');
$form->call('unmountAction');
same($before,$stored(),'Closing the assignment form does not replace the saved template');
$watchQuery->update(['expected_dns'=>null]);
$form = $mount()->set('mountedActions.0.data.assignment', $otherKey);
same(null, $form->get('mountedActions.0.data.expected_dns'), 'A non-default template needs explicit intent even when it lists the deployment hosts');
$form->callMountedAction()->assertHasActionErrors(['expected_dns']);
$form->set('mountedActions.0.data.expected_dns','external');
$before = $stored();
DB::table('domains_watch_profiles')->where('id',$other->id)->increment('revision');
$form->callMountedAction();
same($before, $stored(), 'A template edited after preview cannot be assigned');
$mount()->set('mountedActions.0.data.assignment', 'site:'.$profile->id.':'.$profile->revision)->callMountedAction();
same([null,0,null], array_slice($stored(),0,3),'Returning to the site default restores inheritance');
$form = $mount();
$before = $stored();
siteDnsFixture($other->id);
$form->callMountedAction();
same($before,$stored(),'Moving the default after preview cannot pin the old site choice');
same($other->id, \Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::watchProfile(WatchName::findOrFail($id))->id, 'An inherited watch follows the next site choice');
same($initialProviderState, $providerState(), 'Monitor assignment creates no registrar operation, observation or notice');
// Only the ordinary monitoring worker can turn later, confirmed observations into an alert.
$mount()->set('mountedActions.0.data.expected_dns','external')->callMountedAction();
noticeDns($f, $now, 'external');
noticeDns($f, $now+900, 'external');
$planner->plan('watch',$id,$uid,$now+902);
$alerts = fn () => DB::table('domains_watch_notices')->whereIn('kind',['ns_changed','ns_restored'])->count();
same(0,$alerts(),'Confirmed different nameservers respect the saved external expectation');
$mount()->set('mountedActions.0.data.assignment','deployment:'.$other->id.':'.$other->fresh()->revision)
    ->set('mountedActions.0.data.expected_dns','platform')->callMountedAction()->assertHasNoActionErrors();
noticeDns($f, $now+1800, 'external');
$planner->plan('watch',$id,$uid,$now+1802);
same(0,$alerts(),'A new matching assignment starts a fresh comparison');
noticeDns($f, $now+2700, 'external');
$planner->plan('watch',$id,$uid,$now+2702);
same(1,$alerts(),'Two confirmed differences on the new assignment create one ordinary mismatch alert');
$nativeSuccessful = true;
echo "Watch template assignments passed\n";
