<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. A native action placed by a settings row works as it does on its own page: saved twice from one open page, refused when stale, absent for read-only staff. With DOMAINS_PANEL_SETUP the host panel's skin is installed first.
declare(strict_types=1);
require __DIR__.'/Support/panel-kit.php';
require __DIR__.'/Support/storefront.php';
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Auth, DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\PanelKit;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\ReminderSchedules;

$server = panelKitFixture();
$setup = (string) getenv('DOMAINS_PANEL_SETUP');
if ($setup !== '') { (static fn (string $file, $panel): mixed => require $file)($setup, Filament::getPanel('admin')); }
$schedule = static fn (): object => DB::table('domains_reminder_schedules')->where('server_id', $server)->sole();
// The days a reader sees on the Reminder schedule row and the days saved, each side of the expiry on its own.
$days = static fn (string $html): array => panelKitShownDays(storefrontDom($html)->evaluate('string(//div[@data-domains-row][normalize-space(div[@class="domains-panel-row-k"])="Reminder schedule"]//*[@data-domains-fact])'));
$stored = static fn (): array => panelKitSavedDays($server);
// The row places the page's own action: its click mounts reminders_<server> from the row's actions.
$writer = staffUser([Staff::VIEW, Staff::POLICY]);
$page = Livewire::actingAs($writer)->test(PanelKit::class)->assertSuccessful();
expect(preg_match('/wire:click="mountAction\(\'reminders_'.$server.'\', \{\}, JSON\.parse\(\'\{\\\\u0022schemaComponent\\\\u0022:\\\\u0022([a-z0-9.-]+)\\\\u0022\}\'\)\)"/', $page->html(), $m) === 1, 'Edit reminders mounts the native action from its row');
same('content.kit-rows.kit-rows-account.row-reminder-schedule.row-reminder-schedule-actions', $m[1], 'The action sits in the Reminder schedule row');
$edit = TestAction::make('reminders_'.$server)->schemaComponent(substr($m[1], strlen('content.')), 'content');
same($stored(), $days($page->html()), 'The row shows the saved days');

// The same open page saves twice: each save reads the revision the page now shows.
$page->callAction($edit, ['before' => '30,7', 'after' => '3', 'reason' => 'Fewer reminders before expiry'])->assertHasNoActionErrors()->assertNotified('Reminders saved');
same(['[7,30]', '[3]'], [$schedule()->before_days, $schedule()->after_days], 'The first save is stored');
same($stored(), $days($page->html()), 'The row shows the first save');
$page->callAction($edit, ['before' => '30,7,1', 'after' => '3,10', 'reason' => 'One more reminder each side'])->assertHasNoActionErrors()->assertNotified('Reminders saved');
same(['[1,7,30]', '[3,10]'], [$schedule()->before_days, $schedule()->after_days], 'The second save from the same page is stored');
same($stored(), $days($page->html()), 'The row shows the second save');

// A dialog left open while someone else saves is refused and changes nothing.
$stale = Livewire::actingAs($writer)->test(PanelKit::class)->assertSuccessful()->mountAction($edit)->assertActionMounted($edit);
$other = staffUser([Staff::VIEW, Staff::POLICY]);
ReminderSchedules::save($other, $server, (int) $schedule()->revision, (bool) $schedule()->enabled, '14', '3', 'Changed from another page');
$saved = (array) $schedule();
$stale->fillForm(['before' => '60', 'after' => '5', 'reason' => 'Stale page save attempt'])->callMountedAction()->assertNotified("Couldn't save reminders");
same($saved, (array) $schedule(), 'A stale save changes nothing');

// Staff who can view but not set policy read the days and who sets them, with no control, and can't run it.
$reader = staffUser([Staff::VIEW]);
$view = Livewire::actingAs($reader)->test(PanelKit::class)->assertSuccessful();
same($stored(), $days($view->html()), 'A read-only reader sees the saved days');
same(0, storefrontDom($view->html())->query('//button[@aria-label="Edit Reminder schedule"]')->length, 'No Edit control for a read-only reader');
$view->assertActionDoesNotExist($edit);
expect(str_contains(html_entity_decode($view->html(), ENT_QUOTES), 'Set by staff who can change domain policy.'), 'The "?" says who sets it');
// A forged click, as a browser would send it, saves nothing.
try { $view->call('mountAction', 'reminders_'.$server, [], ['schemaComponent' => $m[1]])->fillForm(['before' => '1', 'after' => '1', 'reason' => 'Read-only save attempt'])->callMountedAction(); } catch (Throwable) {}
same($saved, (array) $schedule(), 'A read-only reader changes nothing');

$nativeSuccessful = true;
echo 'Reminder edit placed by a settings row: saved twice, stale refused, read-only kept'.($setup !== '' ? ', with the host skin' : '').".\n";
