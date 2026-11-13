<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. The panel kit page on a seeded demo Enom account with its reminder schedule; synthetic data only.
declare(strict_types=1);
require_once dirname(__DIR__).'/native-app.php';
require_once __DIR__.'/staff-panel.php';
require_once __DIR__.'/accounts.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{PanelKit,PanelKitDetail,PanelKitHistory,PanelKitList,PanelKitSettings};
use Paymenter\Extensions\Servers\Domains\Services\{AuditBatchRetirement, AuditEventSchema, FoundationSchema, ReminderSchedules, ReminderSchema, StaffAuditSchema};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;

/** The kit's account server id. A host skin (DOMAINS_PANEL_SETUP) is set up before the page is built. */
function panelKitFixture(): int
{
    config(['domains.time_zone' => 'America/Los_Angeles']);
    staffPanel();FoundationSchema::install();ReminderSchema::install();
    // Reminder changes are audit events, so the kit carries the event store as an upgraded install has it.
    StaffAuditSchema::install();AuditEventSchema::install();AuditBatchRetirement::install();
    $account=fixtureAccount(1,'enom',DemoData::ACCOUNTS['enom']);
    ReminderSchedules::enroll($account['server_id']);
    // The kit and its four page type examples.
    $pages=[PanelKit::class,PanelKitDetail::class,PanelKitSettings::class,PanelKitList::class,PanelKitHistory::class];
    $panel=Filament::getPanel('admin');$panel->pages($pages);
    Route::name('filament.admin.')->prefix($panel->getPath())->group(static function()use($panel,$pages){foreach($pages as $page){$page::registerRoutes($panel);}});
    app('router')->getRoutes()->refreshNameLookups();app('router')->getRoutes()->refreshActionLookups();
    return $account['server_id'];
}
/** Reminder days a reader sees, by direction: each number belongs to the before or after word that follows it, so a
 * swapped side fails while wording and punctuation never matter. Numbers with no direction land in none.
 * @return array{before: list<int>, after: list<int>, none: list<int>} */
function panelKitShownDays(string $text): array
{
    $days=['before'=>[],'after'=>[],'none'=>[]];$pending=[];
    preg_match_all('/\d+|\b(?:before|after)\b/i',$text,$tokens);
    foreach($tokens[0] as $token){
        if(ctype_digit($token)){$pending[]=(int)$token;continue;}
        $days[strtolower($token)]=[...$days[strtolower($token)],...$pending];$pending=[];
    }
    $days['none']=$pending;
    foreach($days as &$side){sort($side);}unset($side);
    return $days;
}
/** The reminder days saved for a server, by direction, in the same shape. @return array{before: list<int>, after: list<int>, none: list<int>} */
function panelKitSavedDays(int $server): array
{
    $saved=\Illuminate\Support\Facades\DB::table('domains_reminder_schedules')->where('server_id',$server)->sole();
    $days=['before'=>json_decode($saved->before_days,true),'after'=>json_decode($saved->after_days,true),'none'=>[]];
    foreach($days as &$side){$side=array_map('intval',$side);sort($side);}unset($side);
    return $days;
}
