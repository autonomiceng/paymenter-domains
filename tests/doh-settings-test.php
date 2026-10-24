<?php
// Native encrypted settings and stock Filament, fake HTTPS only. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php'; require __DIR__.'/Support/staff-panel.php';
use App\Models\Setting;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\{Auth,DB,Route};
use Filament\Facades\Filament;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\PublicSuffixList;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\MonitoringSettings as Page;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\DnsReader;
use Paymenter\Extensions\Servers\Domains\Dns\{DohEndpoint,DohTransport,NativeDnsReader,DnsQuery,DnsContext};
use Paymenter\Extensions\Servers\Domains\Services\{DnsResolverSettings,Runtime};
$calls = $nativeCalls = 0; $response = ['status' => 200, 'body' => '{"Status":0,"Question":[{"name":"example.com","type":1}],"Answer":[{"name":"example.com","type":1,"TTL":60,"data":"192.0.2.1"}]}'];
$transport = new DohTransport(function ($request, $options) use (&$calls, &$response) { $calls++; if ($response instanceof Throwable) { throw $response; } return (new MockHandler([new Response($response['status'], [], $response['body'])]))($request, $options); });
$settings = new DnsResolverSettings($transport, new NativeDnsReader(function () use (&$nativeCalls) { $nativeCalls++; return []; }));
app()->instance(DnsResolverSettings::class, $settings);
Runtime::register();
same([null, "No resolver set. Checks use the server's resolver.", false], $settings->configuration(), 'Missing settings native only');
same('native_fallback', app(DnsReader::class)->read(new DnsQuery('example.com', 'A'), new DnsContext)->source, 'Default binding uses settings composition');
same(0, $calls, 'Missing config starts no HTTPS');
$settings->save(DohEndpoint::URL, DohEndpoint::IP);
$row = Setting::where('key', DnsResolverSettings::KEY)->sole(); expect($row->encrypted, 'First save encrypted');
expect(!str_contains($row->getRawOriginal('value'), 'cloudflare'), 'Endpoint encrypted at rest');
same(DohEndpoint::URL, $settings->configuration()[0]->url, 'Native decrypt readback');
same(0, $calls, 'Saving no query');
$row->value = '{'; $row->save(); same(null, $settings->configuration()[0], 'Invalid setting native only');
same([null, "Saved settings can't be read. Checks use the server's resolver.", false], $settings->configuration(), 'Invalid reason visible');
$settings->save(DohEndpoint::URL, DohEndpoint::IP);
$row = Setting::where('key', DnsResolverSettings::KEY)->sole(); $row->encrypted = false; $row->save();
same(null, $settings->configuration()[0], 'Unencrypted setting refused'); $settings->save(DohEndpoint::URL, DohEndpoint::IP);
try { $settings->save('http://example.com', '1.1.1.1'); expect(false, 'Invalid setting refused at write'); } catch (InvalidArgumentException) {}
same(DohEndpoint::URL, $settings->configuration()[0]->url, 'Invalid save preserves setting');
foreach (['::ffff:10.0.0.1', '::ffff:127.0.0.1', '::127.0.0.1', '64:ff9b::a00:1'] as $ip) {
    $endpointRefused=$settingsRefused=false;
    try { new DohEndpoint(DohEndpoint::URL,$ip); } catch (InvalidArgumentException) { $endpointRefused=true; }
    try { $settings->save(DohEndpoint::URL,$ip); } catch (InvalidArgumentException) { $settingsRefused=true; }
    same([true,true],[$endpointRefused,$settingsRefused],'Nonpublic embedded IPv4 refuses endpoint and settings: '.$ip);
    same(DohEndpoint::IP,$settings->configuration()[0]->ip,'Rejected save preserves public bootstrap');
    $saved=Setting::where('key',DnsResolverSettings::KEY)->sole();$saved->value=json_encode(['url'=>DohEndpoint::URL,'ip'=>$ip]);$saved->save();
    same(null,$settings->configuration()[0],'Retained nonpublic embedded IPv4 refuses at read: '.$ip);
    $settings->save(DohEndpoint::URL,DohEndpoint::IP);
}

staffPanel(); $panel = Filament::getCurrentPanel(); $panel->resources([WatchResource::class]);
Route::name('filament.admin.')->prefix($panel->getPath())->middleware($panel->getMiddleware())->group(fn() => Route::middleware($panel->getAuthMiddleware())->group(fn() => WatchResource::registerRoutes($panel)));
app('router')->getRoutes()->refreshNameLookups(); app('router')->getRoutes()->refreshActionLookups();
$rights = [Staff::VIEW, PublicSuffixList::VIEW, PublicSuffixList::MANAGE]; $staff = staffUser($rights); Auth::setUser($staff);
$setRights = function ($permissions) use ($staff) { $staff->role()->first()->update(['permissions' => $permissions]); };
$setRights([]); Livewire::actingAs($staff)->test(Page::class)->assertForbidden();
$setRights([Staff::VIEW, PublicSuffixList::MANAGE]); same(false, Page::canAccess(), 'Manage alone cannot view');
$setRights([Staff::VIEW, PublicSuffixList::VIEW]);
Livewire::actingAs($staff)->test(Page::class)->assertSee('cloudflare-dns.com')->assertSee(DohEndpoint::IP)->assertDontSee('Missing')->assertActionHidden('configure')->assertActionHidden('check');
same(0, $calls, 'Rendering no query'); $setRights($rights);
// The ? text must match FallbackDnsReader: a failed resolver query retries once with the server's resolver.
Livewire::actingAs($staff)->test(Page::class)->assertSee("If the resolver fails, checks retry once with the server's resolver.")->assertDontSee('Connection and response failures use');
$page = Livewire::actingAs($staff)->test(Page::class); $cachedCheck = $page->instance()->getCachedHeaderActions()[1]->getActionFunction();
$cachedSave = $page->instance()->getCachedHeaderActions()[0]->getActionFunction();
$setRights([Staff::VIEW, PublicSuffixList::VIEW]);
foreach ([fn() => $cachedCheck(), fn() => $cachedSave(['url' => DohEndpoint::URL, 'ip' => DohEndpoint::IP])] as $callback) {
    try { $callback(); expect(false, 'Revoked action denied'); } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { same(403, $e->getStatusCode(), 'Fresh permission check'); }
}
same(0, $calls, 'Revocation starts no query'); $setRights($rights);
Livewire::actingAs($staff)->test(Page::class)->mountAction('configure')->setActionData(['url' => 'http://resolver-placeholder.org', 'ip' => '127.0.0.1'])->callMountedAction()->assertHasActionErrors(['url', 'ip']);
foreach (['::ffff:10.0.0.1','::ffff:127.0.0.1','::127.0.0.1','64:ff9b::a00:1'] as $ip) {
    Livewire::actingAs($staff)->test(Page::class)->mountAction('configure')->setActionData(['url'=>DohEndpoint::URL,'ip'=>$ip])->callMountedAction()->assertHasActionErrors(['ip']);
}
Livewire::actingAs($staff)->test(Page::class)->mountAction('configure')->setActionData(['url' => DohEndpoint::URL, 'ip' => DohEndpoint::IP])->callMountedAction()->assertHasNoActionErrors()->assertNotified('Resolver saved');
$nativeBefore = $nativeCalls;
// The check result is a toast: its title is the whole message, including the time taken.
$toasts = function (): array { $sent = new \Filament\Notifications\Livewire\Notifications; $sent->mount(); return $sent->notifications->map(fn($n) => (string) $n->getTitle())->values()->all(); };
$page = Livewire::actingAs($staff)->test(Page::class)->callAction('check'); expect(preg_grep('/^Resolver works \(cloudflare-dns\.com, \d+ ms\)$/', $toasts()) !== [], 'Working check toast');
same(1, $calls, 'Check is exactly one query');
$response = new RuntimeException('PRIVATE-DETAIL');
$page->callAction('check')->assertNotified("Resolver failed: couldn't connect.")->assertDontSee('PRIVATE-DETAIL')
    ->assertDontSee("using the server's resolver (");
$response = new \Paymenter\Extensions\Servers\Domains\Dns\DohFailure('timeout'); $page->callAction('check')->assertNotified('Resolver failed: timed out.'); $response = new RuntimeException('PRIVATE-DETAIL');
same(3, $calls, 'Failed check once'); same($nativeBefore, $nativeCalls, 'Bounded check never calls native');
foreach ([2 => 'SERVFAIL', 5 => 'REFUSED'] as $code => $label) {
    $response = ['status' => 200, 'body' => '{"Status":'.$code.',"Question":[{"name":"example.com","type":1}]}'];
    $page->callAction('check')->assertNotified('Resolver returned '.$label.'.');
}
$response = ['status' => 200, 'body' => '{"Status":3,"Question":[{"name":"example.com","type":1}]}']; $page->callAction('check')->assertNotified('Resolver works, but returned no address for example.com.');
same($nativeBefore, $nativeCalls, 'Checks never call native for any answer');
Setting::where('key', DnsResolverSettings::KEY)->delete();
$before = $calls; Livewire::actingAs($staff)->test(Page::class)->assertSee('Missing')->assertSee("No resolver set. Checks use the server's resolver.")->assertActionHidden('check');
same(['ok' => false, 'message' => "No resolver set. Checks use the server's resolver."], $settings->check(), 'Missing settings check names the cause');
same($before, $calls, 'Missing settings check no network');
putenv('SSLKEYLOGFILE=/synthetic-disabled');
same([null, 'Turn off SSLKEYLOGFILE on the server to use a custom resolver.', true], $settings->configuration(), 'TLS logging has a visible native-only reason');
putenv('SSLKEYLOGFILE');
echo "Encrypted resolver settings, native binding, staff permissions, validation and bounded check states passed.\n"; $nativeSuccessful = true;
