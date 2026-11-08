<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native fixture registration only.
declare(strict_types=1);
require_once __DIR__.'/staff-panel.php';
require_once __DIR__.'/commerce.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarAccount,RegistrarSetup,WhatYouSell};
function tldPanel(): void
{
    \Paymenter\Extensions\Servers\Domains\Services\ReminderSchema::install();\Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::install();
    \Paymenter\Extensions\Servers\Domains\Services\WatchDnsSchema::install();
    // The Registrars list reads balances; bind the clock as Services/Runtime.php does when nothing else did.
    if(!app()->bound(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)){app()->singleton(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,\Paymenter\Extensions\Servers\Domains\Data\SystemClock::class);}
    \Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__,2).'/Domains/resources/views');
    \Paymenter\Extensions\Servers\Domains\Admin\Support\SellToolbar::boot();\Paymenter\Extensions\Servers\Domains\Admin\Support\TableMenus::boot();
    staffPanel();$panel=Filament::getCurrentPanel();$panel->resources([TldResource::class])->pages([RegistrarSetup::class,RegistrarAccount::class,WhatYouSell::class]);
    Route::name('filament.admin.')->prefix($panel->getPath())->middleware($panel->getMiddleware())->group(function()use($panel){
        Route::middleware($panel->getAuthMiddleware())->group(function()use($panel){TldResource::registerRoutes($panel);RegistrarSetup::registerRoutes($panel);RegistrarAccount::registerRoutes($panel);WhatYouSell::registerRoutes($panel);});
    });
    app('router')->getRoutes()->refreshNameLookups();app('router')->getRoutes()->refreshActionLookups();
}

function tldRefusal(callable $call,string $message): void
{
    try{$call();expect(false,'Expected exact settings refusal: '.$message);}
    catch(DomainException $e){same($message,$e->getMessage(),'Exact settings refusal: expected '.$message.'; got '.$e->getMessage());}
}

/** Account actions shared by write assertions and rendered fixtures. */
function staffSetupActions(): array
{
    return ['configure','probe','import','processing','enroll_sandbox',
        'test_renewal','test_transfer','test_management','live_renewal','live_management','live_transfer','late_renewal','renewal_billing','unpaid_hold'];
}

/** No TLD/settings button: request answers belong to requests; price publication/defaults are choices in Edit prices; Default pricing lives on What you sell. */
function staffNoButtonActions(): array
{
    return ['call_limits','activate','enom_answer','publish','remove_override','acceptance','history','delivery_review','alert_review','default_pricing','default_rule','card_fee'];
}

/** The Default pricing dialog sends rule and fee together; a test names only what it changes. */
function pricingForm(array $form=[],?array $fee=null): array
{
    $fee??=\Paymenter\Extensions\Servers\Domains\Services\CardFee::read();$rule=(new \Paymenter\Extensions\Servers\Domains\Services\PricingRules)->defaultRule(false,$fee);
    return ['mode'=>$rule['mode'],'markup'=>$rule['markup'],'round_up'=>$rule['round_up'],'fixed_register'=>$rule['fixed']['register'],'fixed_renew'=>$rule['fixed']['renew'],'fixed_transfer'=>$rule['fixed']['transfer'],
        'fee_percent'=>$fee['percent'],'fee_fixed'=>$fee['fixed'],...$form];
}

function pricingReview(int $actor,string $reason='Update prices for these TLDs'): \Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview
{
    $all=\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::class;
    return new $all($actor,$all::ALL_ACCOUNTS,null,'default_pricing',$all::ticket($actor,$all::ALL_ACCOUNTS,null,'default_pricing',$all::stamp($all::ALL_ACCOUNTS,null)),$reason);
}

/** Pin the engine clock, as staff actions read it for cost freshness. */
function pinStaffClock(DateTimeImmutable $at): void
{
    Carbon\Carbon::setTestNow($at);
    app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new class($at) implements \Paymenter\Extensions\Servers\Domains\Contracts\Clock{public function __construct(private DateTimeImmutable $at){} public function now():DateTimeImmutable{return $this->at;}});
}

/** Synthetic TEST feature acceptance for pages that assert actual sale availability.
 * Uses the installed feature guards; no provider factory or request is resolved.
 */
function staffSalesFixture(): void
{
    config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,
        'domains.enom_writes.registration'=>true,'domains.enom_writes.renewal'=>true,'domains.enom_writes.transfer'=>true]);
    foreach(\Paymenter\Extensions\Servers\Domains\Models\Account::where('registrar_key','enom')->get() as $account){
        $binding=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($account);
        foreach([\Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::class,\Paymenter\Extensions\Servers\Domains\Services\EnomTestTransfer::class] as $policy){
            \App\Models\Server::findOrFail($binding->serverId)->settings()->updateOrCreate(['key'=>$policy::KEY],
                ['type'=>'string','encrypted'=>true,'value'=>json_encode(['fingerprint'=>$binding->fingerprint,...$policy::protocol($binding)],JSON_THROW_ON_ERROR)]);
        }
    }
}
