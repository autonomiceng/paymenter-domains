<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;

use App\Models\Server;
use Filament\Schemas\Components\Callout;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting,UpgradeGate};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Row, Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Section;
use Paymenter\Extensions\Servers\Domains\Presentation\{Missing, Reader, State};

/** One setup notice across the account and catalogue pages; no price, credential or provider reads. */
final class CatalogueSalesNotice
{
    public const WHY = "NameSilo can't sell in the new catalogue yet. Add an Enom account to start selling.";
    /** True while the new catalogue is on and no connected registrar account can sell domains. */
    public static function needed(): bool
    {
        if (UpgradeGate::routeState()['state'] !== 'active') { return false; }
        $registrars=Account::whereIn('server_id',Server::where('extension','Domains')->where('type','server')->where('enabled',true)->select('id'))
            ->distinct()->pluck('registrar_key');
        return !$registrars->contains(CallerRouting::supportsSales(...));
    }
    /** The same notice for a list page: one section that opens with it. @return list<Section> */
    public static function sections(): array
    {
        $lines = self::lines();
        return $lines === [] ? [] : [Section::make('Selling', [Row::notice('catalogue-sales', Value::state(State::NeedsReview, Reader::Staff, $lines[0]))])];
    }
    /** @return list<Callout> */
    public static function components(): array
    {
        return self::needed() ? [Callout::make(Missing::LABEL.': an account that can sell domains.')->status(Missing::COLOR)->icon(Missing::icon())
            ->description(self::WHY)->columnSpanFull()] : [];
    }
    /** The same gap as sentences, for a page type's notice. @return list<string> */
    public static function lines(): array { return self::needed() ? ['No account can sell domains. '.self::WHY] : []; }
}
