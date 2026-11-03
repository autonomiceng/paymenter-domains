<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;

use App\Models\Server;
use Filament\Schemas\Components\Callout;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting,UpgradeGate};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;

/** One setup notice across the account and catalogue pages; no price, credential or provider reads. */
final class CatalogueSalesNotice
{
    /** @return list<Callout> */
    public static function components(): array
    {
        if (UpgradeGate::routeState()['state'] !== 'active') { return []; }
        $registrars=Account::whereIn('server_id',Server::where('extension','Domains')->where('type','server')->where('enabled',true)->select('id'))
            ->distinct()->pluck('registrar_key');
        if ($registrars->contains(CallerRouting::supportsSales(...))) { return []; }
        return [Callout::make(Missing::LABEL.': an account that can sell domains.')->status(Missing::COLOR)->icon(Missing::icon())
            ->description("NameSilo can't sell in the new catalogue yet. Add an Enom account to start selling.")->columnSpanFull()];
    }
}
