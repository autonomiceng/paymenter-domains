<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Panel;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{AccountChecklist, ApiCallLimitAction, BalanceCheck, LiveActivationAction, PaidUncertainty, PriceFreshnessAction, PslActions, RegistrarFacts, RegistrarPage, RegistrarRows, SettingsActions, Sheet, Staff, TldFacts};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Row, Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{DetailPage, Section};
use Paymenter\Extensions\Servers\Domains\Data\CatalogueFreshness;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain};
use Paymenter\Extensions\Servers\Domains\Presentation\{AccountStatus, Fact, Reader, State};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, EnomLiveActivation, SiteDnsDefault};
/** One registrar account (a Domains server): its own state in the head, then Account, Balance and usage, Sales,
 * Renewals and Domain settings, with System checks on the rail. Reached from the Registrars list. Each switch sits with
 * what it controls; a link that came for a switch lands on it. A cause the head used to say opens the section it concerns. */
class RegistrarAccount extends DetailPage
{
    use RegistrarPage { mount as private openAccount; }
    protected static ?string $slug = 'registrar-account';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
    /** The switch a link came for (RegistrarFacts::SWITCHES). */
    #[Locked] public ?string $switch = null;
    public static function canAccess(): bool { return Staff::can(); }
    public static function getRoutePath(Panel $panel): string { return '/'.static::getSlug($panel).'/{account}'; }
    public function mount(int $account): void
    {
        $this->openAccount($account);
        $switch = request()->query('switch');$this->switch = is_string($switch) && isset(RegistrarFacts::SWITCHES[$switch]) ? $switch : null;
    }
    /** Rebuild the page after a System checks action changed what it shows. */
    private function resetContent(): void { unset($this->cachedSchemas['content']); }
    public function checkBalance(): void { BalanceCheck::run($this->account); }
    public function checkBalanceAction(): Action
    { return Sheet::button(Action::make('checkBalance')->label('Check balance')->visible(fn () => RegistrarFacts::connected($this->record(), $this->status()))->action(fn () => $this->checkBalance())); }

    protected function sections(): array
    {
        Staff::requireView();$s = $this->server();$a = $this->record();
        if (!$a) {
            return [Section::make('Account', [...$this->notice('account'), RegistrarRows::row(['label' => 'Registrar', 'value' => null, 'tone' => null, 'cause' => 'Account not set up yet. Use Connect account.']),
                Row::make('Domains', Value::fact(Fact::count(Domain::where('server_id', $s->id)->count())))], null, 'account')];
        }
        $id = (int) $s->id;
        $rows = array_column([...AccountChecklist::setup($a, $this->status()), ...AccountChecklist::renewals($id), self::whois($a)], null, 'label');
        $pick = fn (string ...$labels) => array_values(array_filter(array_map(fn (string $label) => $rows[$label] ?? null, $labels)));
        $funds = RegistrarFacts::connected($a, $this->status()) ? RegistrarFacts::balance($a)['funds'] : null;
        $usage = Section::make('Balance and usage', [...$this->notice('usage'), ...$this->rows($this->usageRows($s, $a))], RegistrarFacts::connected($a, $this->status()) ? \Paymenter\Extensions\Servers\Domains\Presentation\Fact::IN_USD : null, 'usage');
        return [
            Section::make('Account', [...$this->notice('account'), ...$this->rows([...$this->accountRows($a), ...$pick('API connection')])], null, 'account'),
            $funds ? $usage->link(Sheet::link('add_funds', 'Add funds', $funds, external: true)) : $usage,
            // Live sales, Prices stay current for and the TLD term rows sit with the sales they govern.
            Section::make('Sales', [...$this->notice('sales'), ...$this->rows($pick('Orders', 'Registrations', AccountChecklist::TRANSFER_LABEL, 'What you sell', 'TLD prices')),
                ...$this->rows([$this->freshness($s, $a)]), ...$this->rows($pick('Customer pays', 'Card fee')), ...$this->liveSales($a)], null, 'sales'),
            Section::make('Renewals', $this->rows($pick('Renewals', 'Late renewal', 'Customer can renew early', 'Invoice issued', 'Invoice due', 'Unpaid hold', 'Scheduled renewals', 'Selected TLD terms', 'Reminder emails', 'Reminder schedule')), null, 'renewal'),
            Section::make('Domain settings', [$this->newDomainsUse($a), ...$this->rows($pick('DNS, nameservers and contacts', 'WHOIS privacy'))], null, 'settings'),
        ];
    }
    protected function rail(): array
    {
        $checks = $this->checks();$a = $this->record();
        if ($a) {
            $rows = array_column([...AccountChecklist::setup($a, $this->status()), ...AccountChecklist::renewals((int) $a->server_id)], null, 'label');
            $checks['failing'] = [...AccountChecklist::pausedChecks($rows), ...$checks['failing']];
        }
        $all = [...$checks['failing'], ...$checks['passing']];
        return [Section::make('System checks ('.count($checks['passing']).' of '.count($all).' OK)', [RegistrarRows::checks($all)], null, 'system_checks')];
    }
    /** System checks for this account, failing first. @return array{failing:list<array>,passing:list<array>} */
    public function checks(): array
    {
        $system = AccountChecklist::system();$a = $this->record();
        if (Staff::can(PslActions::VIEW)) { $psl = PslActions::row(fn () => $this->resetContent());if (in_array($psl['tone'], ['danger', 'warning'], true)) { $system['failing'][] = $psl; } }
        if ($a) {
            $runtime = AccountChecklist::runtime($a);$system[$runtime['value'] === null ? 'failing' : 'passing'][] = $runtime;
            $alerts = AccountChecklist::alerts($a);$system[$alerts['tone'] === 'success' ? 'passing' : 'failing'][] = $alerts;
            if ($this->status()['label'] === AccountStatus::SET['not_selling'][0]) { $system['failing'][] = AccountChecklist::notSelling($a, (string) $this->status()['reason']); }
        }
        return $system;
    }
    /** The section's one notice: the account's cause for the section it concerns, the site-wide sales gap, or the hold on
     * paid orders. Two causes in one section read as one notice with the first one's word and action. @return list<Row> */
    private function notice(string $section): array
    {
        $status = $this->status();$label = $status['label'];$a = $this->record();$items = [];
        // The API connection row says why a connected account failed or is off; without an account the Account section says it.
        $connection = in_array($label, [AccountStatus::SET['connection_failed'][0], AccountStatus::SET['not_connected'][0]], true);
        if ($section === 'account' && $connection && !$a && $status['reason'] !== null) {
            $items[] = [$label === AccountStatus::SET['not_connected'][0] ? State::NotConnected : State::ConnectionFailed, $status['reason'], null];
        }
        if ($section === 'sales') {
            if ($label === AccountStatus::SET['orders_paused'][0] && $status['reason'] !== null) { $items[] = [State::Stopped, $status['reason'], null]; }
            if ($label === AccountStatus::SET['not_selling'][0] && $status['reason'] !== null && $a) {
                $items[] = [State::NotForSale, $status['reason'], AccountChecklist::notSelling($a, (string) $status['reason'])['buttons'][0] ?? null];
            }
        }
        // The catalogue gap concerns sales; a page with no account yet has only its Account section to say it in.
        if ($section === ($a ? 'sales' : 'account')) {
            foreach (\Paymenter\Extensions\Servers\Domains\Admin\Support\CatalogueSalesNotice::lines() as $line) { $items[] = [State::NeedsReview, $line, null]; }
        }
        if ($section === 'usage') {
            foreach (PaidUncertainty::holds($this->account) as $h) {
                $items[] = [$h['state'], PaidUncertainty::line($h), $h['url'] ? Sheet::link('paid_request_'.$h['server_id'], $h['label'], $h['url']) : null];
            }
        }
        if ($items === []) { return []; }
        $action = array_values(array_filter(array_column($items, 2)))[0] ?? null;
        $line = implode(' ', array_map(static fn (array $i): string => (string) RegistrarRows::sentence($i[1]), $items));
        return [Row::notice($section.'-cause', Value::state($items[0][0], Reader::Staff, $line, 'registrar-'.$this->account.'-'.$section), $action)];
    }
    /** Rows with their action column. A value of On or Off with a control is a switch; a switch a link can name is its target. @return list<Row> */
    private function rows(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $isSwitch = in_array($r['value'], ['On', 'Off'], true) && (!empty($r['buttons']) || $r['action'] || $r['cause']);
            $control = $isSwitch ? ($r['buttons'][0] ?? ($r['action'] ? $this->setup($r['action']) : null)) : null;
            [, $link] = RegistrarRows::line($r['cause'] ?? null, 'row');
            $row = RegistrarRows::row($r, $isSwitch ? [] : [...$this->side($r), $link], $control !== null && $control->isVisible() ? $control : null);
            $name = array_search($r['label'], RegistrarFacts::SWITCHES, true);
            $out[] = $name === false ? $row : $row->key('switch-'.$name)->target($name === $this->switch);
        }
        return $out;
    }
    /** A row's action column. Rows that change a saved value say Edit. */
    private function side(array $r): array
    {
        $edit = $r['edit'] ?? false;
        if (!empty($r['buttons'])) { return $edit ? [Sheet::edit($r['buttons'][0], $r['label'])] : $r['buttons']; }
        if (!$r['action']) { return []; }
        return [$edit || !in_array($r['action'], ['probe', 'configure'], true) ? Sheet::edit($this->setup($r['action']), $r['label']) : Sheet::button($this->setup($r['action']))];
    }
    private function setup(string $name): Action { return SettingsActions::setup($this->account, $this->reviewStamp, $name); }
    /** A value with no fix of its own. */
    private static function fact(string $label, ?string $value, string|Htmlable|null $cause = null, ?string $help = null): array
    { return ['label' => $label, 'value' => $value, 'tone' => null, 'cause' => $cause, 'action' => null, 'help' => $help]; }
    /** The default nameservers as they apply here: the template, its nameservers and Change, which opens Domains > DNS templates.
     * Change and the fix need both that page and the right to change the default; other staff are told who can. */
    private function newDomainsUse(Account $a): Row
    {
        $canEdit = Staff::canEditDefaultDns() && RoutingProfileResource::canViewAny();$default = SiteDnsDefault::profile();$refusal = SiteDnsDefault::refusal($canEdit);
        $cause = $refusal ?? ($default
            ? 'Nameservers: '.implode(', ', array_map('strval', (array) $default->nameservers)).'. New registrations use these nameservers unless the customer chooses others. Transfers keep the nameservers they already have.'
            : 'New registrations have no site-default nameservers. '.($a->registrar_key === 'namesilo' ? 'NameSilo needs explicit nameservers before an order can be sent. ' : '').($canEdit ? 'Choose one in Domains > DNS templates.' : SiteDnsDefault::ASK));
        try { $url = $canEdit ? RoutingProfileResource::getUrl() : null; } catch (\Throwable) { $url = null; }
        // A saved template this registrar can't use is set, but staff must change it before orders can go out.
        $fact = $refusal ? [...self::fact('New domains use', $default?->name ?? State::NeedsReview->label(), $cause), 'state' => State::NeedsReview] : self::fact('New domains use', $default?->name, $cause);
        return RegistrarRows::row($fact, $url ? [Action::make('new_domains_use')->label('Change')->link()->size('sm')->url($url)] : []);
    }
    /** Enom charges for WHOIS privacy and this site does not sell it: Not for sale, with why. */
    private static function whois(Account $a): array
    {
        $row = AccountChecklist::whois($a) ?? self::fact('WHOIS privacy', 'Free when supported by the TLD.');
        return str_starts_with((string) $row['value'], 'Not offered. ') ? [...$row, 'value' => State::NotForSale->label(), 'cause' => substr((string) $row['value'], strlen('Not offered. '))] : $row;
    }
    private function accountRows(Account $a): array
    {
        $rows = [self::fact('Registrar', TldFacts::registrar($a->registrar_key)), self::fact('Mode', RegistrarFacts::mode($a), null, RegistrarFacts::MODE_HELP)];
        if ($a->registrar_key === 'enom') { $rows[] = [...self::fact('API user', $user = RegistrarFacts::apiUser($a), $user === null ? 'Identity not verified yet. Use Edit.' : null), 'action' => 'configure', 'edit' => true, 'mono' => true]; }
        if ($a->registrar_key === 'enom' && $a->environment === 'live') {
            try { $fingerprint = AccountBindings::binding($a)->fingerprint; } catch (\Throwable) { $fingerprint = null; }
            // Operators copy it into domains.enom_live.accounts (release-runbook.md).
            $copy = $fingerprint === null ? [] : [Action::make('copy_live_account_id')->label('Copy')->link()->size('sm')->alpineClickHandler('window.navigator.clipboard.writeText('.json_encode($fingerprint).')')];
            $rows[] = [...self::fact('Live account ID', $fingerprint, $fingerprint === null ? "Saved credentials can't be read. Use More, then Edit API credentials." : null), 'mono' => true, 'buttons' => $copy];
        }
        return $rows;
    }
    private function usageRows(Server $s, Account $a): array
    {
        $rows = [];
        if (RegistrarFacts::connected($a, $this->status())) {
            $b = RegistrarFacts::balance($a);
            // The fix for an unread balance is the Check balance button on this row.
            $checked = $b['value'] === null ? $b['cause'] : 'Checked '.\Paymenter\Extensions\Servers\Domains\Presentation\StaffTime::at((int) strtotime((string) $b['checked'])).'.';
            $rows[] = [...self::fact('Balance', $b['value'], $checked), 'state' => $b['low'] ? State::LowBalance : $b['missing'], 'buttons' => [$this->checkBalanceAction()]];
            $rows[] = self::fact('Due in 30 days', $b['due'], RegistrarFacts::dueLine(RegistrarFacts::dueGap($a, $b)), RegistrarFacts::DUE_HELP);
        }
        try { $domains = DomainResource::getUrl('index', ['filters' => ['server_id' => ['value' => (string) $s->id]]]); } catch (\Throwable) { $domains = null; }
        $rows[] = [...self::fact('Domains', (string) Domain::where('server_id', $s->id)->count()), 'buttons' => [Sheet::link('view_domains', 'View domains', $domains)]];
        // Keep one link so the value and its counts remain readable on phones.
        $requests = RegistrarFacts::requests((int) $s->id);
        $statuses = $requests['statuses'];
        $review = ($statuses['on_hold'] ?? 0) + ($statuses['under_review'] ?? 0);
        $parts = [];
        foreach (['awaiting_payment' => 'awaiting payment', 'action_required' => 'action required', 'in_progress' => 'checking'] as $key => $label) {
            if ($statuses[$key] ?? 0) { $parts[] = $statuses[$key].' '.$label; }
        }
        if ($review) { $parts[] = $review.' under review'; }
        $rows[] = [...self::fact('Registrar requests', $requests['open'].' open', $parts === [] ? null : ucfirst(implode(', ', $parts)).'.'),
            'buttons' => [Sheet::link('view_requests', 'View requests', RegistrarFacts::requestsUrl((int) $s->id, RegistrarFacts::OPEN_REQUESTS))]];
        if ($calls = RegistrarFacts::calls($a)) { $rows[] = [...self::fact($a->environment === 'live' ? 'API calls, today (UTC)' : 'API calls, last 24 hours', $calls['value'], $calls['sub'], $calls['help']), 'tone' => $calls['tone'], 'edit' => true, 'buttons' => [ApiCallLimitAction::make($a, $this->reviewStamp)]]; }
        return $rows;
    }
    private function freshness(Server $s, Account $a): array
    {
        return [...self::fact('Prices stay current for', CatalogueFreshness::forAccount((int) $s->id)->hours.' hours', null, 'Prices sync daily. Older prices stop new orders until checked again.'),
            'edit' => true, 'buttons' => [PriceFreshnessAction::make($a, $this->reviewStamp)]];
    }
    /** Live sales, for staff who can activate: what activation does, its open causes, and Activate. The full summary is in its dialog. @return list<Row> */
    private function liveSales(Account $a): array
    {
        if (!LiveActivationAction::offered($a)) { return []; }
        try {
            $v = EnomLiveActivation::summary($a);
            $value = Value::fact(Fact::names(['Activate the selected Live features']), RegistrarRows::sentence(trim(substr((string) $v['text'], strlen('Activate the selected Live features.')).' New eligible orders can be sent after activation. '.implode(' ', array_map(static fn ($m): string => (string) RegistrarRows::sentence($m), (array) $v['missing'])))));
            $help = trim((string) ($v['help'] ?? ''));
        } catch (\Throwable) {
            $value = Value::state(State::CouldntRead, Reader::Staff, 'Live setup can’t be read. Reload this page, then check System checks.');$help = '';
        }
        $row = Row::make('Live sales', $value)->actions(LiveActivationAction::make($a, $this->reviewStamp))->key('live_sales');
        return [$help === '' ? $row : $row->help($help)];
    }
}
