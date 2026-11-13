<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\Server;
use Filament\Actions\{Action, ActionGroup};
use Filament\Navigation\NavigationItem;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarAccount, RegistrarHistory, RegistrarSetup};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head, Row, Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Section;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Presentation\{AccountStatus, Reader, State};
use Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview;
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** What a registrar account's tabs share: the head with the account's own state, the setup actions, the tabs, and the
 * notices that say why the account is not simply Active. Data only; the page types draw it. */
trait RegistrarPage
{
    #[Locked] public int $account = 0;
    /** The settings revision this page shows; the setup actions refuse a stale one. */
    public string $reviewStamp = '';
    private ?array $status = null;

    public function mount(int $account): void
    {
        Staff::requireView();
        abort_unless(Server::where('extension', 'Domains')->whereKey($account)->exists(), 404);
        $this->account = $account; $this->reviewStamp = StaffSettingsReview::stamp($account, null);
        // The Domain alerts row counts by the badge's rule, so the badge is refreshed here too.
        NavigationCount::forget('domains');
    }
    protected function server(): Server { return Server::where('extension', 'Domains')->findOrFail($this->account); }
    private function record(): ?Account { return Account::where('server_id', $this->account)->first(); }
    /** @return array{label:string,tone:string,reason:?string} */
    private function status(): array { return $this->status ??= RegistrarFacts::status($this->server(), $this->record()); }

    /** The account and its own state only: Active, Orders paused, Not connected or Connection failed. A sale problem
     * is a notice, never the head. */
    protected function head(): Head
    {
        $a = $this->record();
        return Head::make((string) $this->server()->name, Reader::Staff)->tag($a !== null && DemoData::is($a), $a !== null && RegistrarFacts::mode($a) === 'Test')
            ->state(self::headState($this->status()['label']), 'registrar-'.$this->account);
    }
    private static function headState(string $label): State
    {
        return match ($label) {
            AccountStatus::SET['orders_paused'][0] => State::OrdersPaused, AccountStatus::SET['not_connected'][0] => State::NotConnected,
            AccountStatus::SET['connection_failed'][0] => State::ConnectionFailed, default => State::Active,
        };
    }
    /** Why the account is not simply Active, then the hold on its paid orders: the cause staff read first, said once. */
    protected function notices(): array
    {
        $status = $this->status();$sections = [];
        $state = match ($status['label']) {
            AccountStatus::SET['orders_paused'][0] => State::OrdersPaused, AccountStatus::SET['not_connected'][0] => State::NotConnected,
            AccountStatus::SET['connection_failed'][0] => State::ConnectionFailed, AccountStatus::SET['not_selling'][0] => State::NotForSale, default => null,
        };
        if ($state !== null && $status['reason'] !== null) { $sections[] = Section::make('Account', [Row::notice('registrar-status', Value::state($state, Reader::Staff, $status['reason']))], null, 'account'); }
        $holds = array_map(fn (array $h): Row => Row::notice('paid-'.$h['server_id'], Value::state($h['state'], Reader::Staff, PaidUncertainty::line($h)),
            $h['url'] ? Sheet::link('paid_request_'.$h['server_id'], $h['label'], $h['url']) : null), PaidUncertainty::holds($this->account));
        return $holds === [] ? $sections : [...$sections, Section::make('Paid orders', $holds, null, 'paid-orders')];
    }
    protected function actions(): array
    {
        $id = $this->account;$setup = fn (string $name) => SettingsActions::setup($id, $this->reviewStamp, $name);
        if (!$this->record()) { return [$setup('configure'), $setup('enroll_sandbox')]; }
        return [...($this->record()->environment === 'test' && !$this->record()->processing_enabled ? [$setup('processing')->name('activate_test')->label('Activate')] : []), ActionGroup::make([$setup('configure')])
            ->label('More')->button()->outlined()->color('gray')->size('sm')->icon('heroicon-m-chevron-down')->iconPosition('after')];
    }
    protected function afterActionCalled(Action $action): void
    {
        parent::afterActionCalled($action);
        // Writers load their own models. Rebuild the page so the next change needs no reload.
        $this->status = null;$this->reviewStamp = StaffSettingsReview::stamp($this->account, null);
        unset($this->cachedSchemas['content']);$this->cachedActions = [];$this->cachedHeaderActions = [];
        $this->cacheInteractsWithHeaderActions();$this->forceRender();
    }
    /** @return list<NavigationItem> */
    public function getSubNavigation(): array
    {
        $id = ['account' => $this->account];$history = $this instanceof RegistrarHistory;
        try { $url = RegistrarHistory::getUrl($id); } catch (\Throwable) { return []; }
        return [NavigationItem::make('Overview')->url(RegistrarAccount::getUrl($id))->isActiveWhen(fn () => !$history),
            NavigationItem::make('History')->url($url)->isActiveWhen(fn () => $history)->visible(RegistrarHistory::canAccess())];
    }
    public function getBreadcrumbs(): array { return [RegistrarSetup::getUrl() => 'Registrars', (string) $this->server()->name]; }
}
