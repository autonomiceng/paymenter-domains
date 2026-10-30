<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Notifications\Notification;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestSendLimit,RegistrarBalances};
/** The Check balance button, on the account page and on a request waiting for a fresh balance. */
final class BalanceCheck
{
    public static function run(int $server): void
    {
        Staff::requireView();$code=app(RegistrarBalances::class)->refresh($server);$record=Account::where('server_id',$server)->first();
        $toast=Notification::make()->title(match($code){'refreshed'=>'Balance updated','rate_limited'=>'Wait a minute before checking again.',default=>"Couldn't check balance"})
            ->body(match($code){'configuration_unavailable'=>'Check the account settings in the Setup checklist.',
                'test_call_refused'=>$record?->environment==='live'?'Our live Enom safety check stopped this request. Check our daily safety limits on this page.':EnomTestSendLimit::facts($record?EnomTestSendLimit::login($record):null),default=>null});
        match($code){'refreshed'=>$toast->success(),'rate_limited'=>$toast->warning(),default=>$toast->danger()};
        $toast->send();
    }
}
