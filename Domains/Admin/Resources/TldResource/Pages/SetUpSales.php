<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages;

use App\Models\Server;
use Filament\Actions\Action;
use Filament\Forms\Components\{Checkbox, Radio, Select, TextInput};
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\{Arr, Collection, Facades\Auth, Facades\Crypt, Facades\Log};
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions, Staff};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head, Partial};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{RecordDetailPage, Section};
use Paymenter\Extensions\Servers\Domains\Catalogue\SalesSetup;
use Paymenter\Extensions\Servers\Domains\Models\{Account, SalesPolicy};
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact, Reader};
use Paymenter\Extensions\Servers\Domains\Services\{EndingChoices, StaffReason, StaffSettingsReview as Review};

/** Review one numeric record or an exact selection from the catalogue. */
class SetUpSales extends RecordDetailPage
{

    public const MAX_TLDS = 25;
    public const RETURN_SELECTION = 'domains.sales-setup.return';
    private const SELECTION_CHANGED = 'The selection changed. Nothing was saved. Select the TLDs again.';
    protected static string $resource = TldResource::class;
    protected static bool $shouldRegisterNavigation = false;

    public array $draft = [];
    public array $confirmation = [];
    #[Locked] public array $selected = [];
    #[Locked] public array $review = [];
    #[Locked] public array $previousReview = [];
    #[Locked] public ?array $outcome = null;
    #[Locked] public ?string $problem = null;
    #[Locked] public string $initial = '';
    #[Locked] public array $origin = [];

    public static function canAccess(array $parameters = []): bool
    {
        return Staff::can(Staff::POLICY) && Staff::can(Review::permission('seller'));
    }

    public static function openerAttributes(): array
    {
        return ['id' => 'set-up-sales', 'x-data' => '', 'x-init' => <<<'JS'
            if (location.hash === '#set-up-sales') $nextTick(() => $el.focus())
            JS];
    }

    /** The catalogue resolves the filtered table selection before issuing this actor-bound handoff. */
    public static function selectionUrl(array $records, ?int $preferred, string $url): string
    {
        if (!$records || count($records) > self::MAX_TLDS) {
            throw new \DomainException('Select 1 to 25 TLDs to set up sales.');
        }
        return TldResource::getUrl('sales-setup', ['selection' => Crypt::encryptString(json_encode([
            'ids' => array_keys($records), 'stamp' => self::stamp($records),
            'preferred' => $preferred, 'url' => $url, 'actor' => (int) Auth::id(), 'until' => time() + 900,
        ], JSON_THROW_ON_ERROR))]);
    }

    public function mount(int|string|null $record = null): void
    {
        abort_unless(self::canAccess(), 403);
        $ticket = request()->query('selection');
        if ($ticket !== null) {
            try {
                $this->origin = json_decode(Crypt::decryptString($ticket), true, 12, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                abort(404);
            }
            abort_unless($this->origin['actor'] === (int) Auth::id(), 403);
            abort_unless($this->origin['until'] >= time(), 404);
            $records = $this->records();
            abort_unless(self::stamp($records) === $this->origin['stamp'], 404);
        } else {
            $record = TldResource::getEloquentQuery()->findOrFail(
                filter_var($record ?? request()->query('record'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
            $records = [$record->getKey() => $record];
            $this->origin = [
                'actor' => (int) Auth::id(), 'ids' => array_keys($records), 'stamp' => self::stamp($records),
                'preferred' => (int) $record->server_id,
                'url' => TldResource::getUrl('view', ['record' => $record]),
            ];
        }
        $this->record = reset($records);
        $this->selected = array_values(array_unique(array_map(static fn($row) => $row->tld, $records)));
        sort($this->selected, SORT_STRING);
        $policy = count($this->selected) === 1 ? SalesPolicy::where('tld', $this->selected[0])->first() : null;
        $enabled = self::setupAccounts()->where('enabled', true);
        $preferred = $enabled->get($policy?->server_id) ?? $enabled->get($this->origin['preferred']) ?? $enabled->first();
        $this->form->fill([
            'server' => $preferred?->id,
            'register' => $policy?->register_enabled ?? true,
            'transfer' => $policy?->transfer_enabled ?? true,
            'longest' => 'keep',
            'pricing' => 'keep',
        ]);
        $this->confirmationForm->fill();
        $this->initial = $this->draftStamp();
    }

    public function hydrate(): void
    {
        abort_unless(self::canAccess() && $this->origin['actor'] === (int) Auth::id(), 403);
        if (!Staff::can(Staff::COSTS)) {
            $this->previousReview = self::withoutCosts($this->previousReview);
            if (isset($this->review['rows'])) {
                $this->review['rows'] = self::withoutCosts($this->review['rows']);
            }
            if (isset($this->outcome['outcomes'])) {
                $this->outcome['outcomes'] = self::withoutCosts($this->outcome['outcomes']);
            }
        }
    }

    private static function withoutCosts(array $rows): array
    {
        foreach ($rows as &$row) {
            foreach ($row['prices'] as &$price) {
                unset($price['cost'], $price['profit']);
            }
        }
        return $rows;
    }

    private function records(): array
    {
        return TldResource::getEloquentQuery()->whereKey($this->origin['ids'])
            ->when(isset($this->origin['until']), fn($query) => $query->where('catalogue_converted', true))
            ->get()->keyBy('id')->all();
    }

    private static function stamp(array $records): string
    {
        ksort($records, SORT_STRING);
        $identity = array_map(static fn($row) => [$row->tld, (int) $row->server_id, $row->registrar_key, $row->account_fingerprint], $records);
        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }

    protected function head(): Head
    {
        $head = Head::make('Set up sales', Reader::Staff);
        if ($this->selected !== []) {
            $head->fact(count($this->selected) === 1 ? 'TLD' : 'TLDs', Fact::tlds($this->selected));
        }
        return $head->note(Fact::IN_USD);
    }

    public function getBreadcrumbs(): array { return [$this->catalogueUrl() => 'TLDs & pricing', 'Set up sales']; }

    public function catalogueUrl(): string
    {
        return isset($this->origin['until']) ? $this->origin['url'] : TldResource::getUrl('index');
    }

    protected function sections(): array
    {
        $review = Partial::make('domains::admin.sales-setup-review', ['page' => $this]);
        if ($this->outcome !== null) {
            return [Section::make('Result for each TLD', [$review])];
        }
        $fields = fn(string $key) => Partial::make('domains::admin.sales-setup-fields', ['fields' => fn() => $this->form->getComponent($key)], $key);
        return [
            Section::make('Registrar for new orders', [$fields('registrar')], 'Existing domains stay with their registrar.'),
            Section::make('What to sell', [$fields('terms')]),
            Section::make('Pricing', [$fields('pricing')]),
            Section::make('Review', [$review]),
        ];
    }

    protected function rail(): array
    {
        return $this->outcome === null
            ? [Section::make('Summary', [Partial::make('domains::admin.sales-setup-summary', ['page' => $this])])]
            : [];
    }

    private static function setupAccounts(): Collection
    {
        return Server::where('extension', 'Domains')->where('type', 'server')
            ->whereIn('id', Account::query()->select('server_id'))->orderBy('id')->get(['id', 'name', 'enabled'])->keyBy('id');
    }

    public function form(Schema $schema): Schema
    {
        $accounts = self::setupAccounts();
        $canSetTerm = Staff::can(Review::permission('acceptance'));
        $canPublish = Staff::can(Review::permission('publish'));
        $pricing = ['keep' => "Keep each TLD's pricing"];
        if ($canPublish && Staff::can(Review::permission('remove_override'))) {
            $pricing['default'] = 'Default pricing';
        }
        if ($canPublish && Staff::can(Review::permission('override'))) {
            $pricing['custom'] = 'Custom pricing';
        }
        $terms = ['keep' => "Keep each TLD's term"];
        foreach (range(1, 10) as $years) {
            $terms[$years] = 'Up to '.$years.($years === 1 ? ' year' : ' years');
        }
        $custom = static fn(Get $get) => $get('pricing') === 'custom';

        return $schema->statePath('draft')->components([
            Group::make([
                Radio::make('server')->label('Registrar for new orders')->hiddenLabel()
                    ->options($accounts->map(fn(Server $server) => $server->name.($server->enabled ? '' : ' (off)'))->all())
                    ->disableOptionWhen(fn($value) => !$accounts->get($value)?->enabled)->required()->live(),
            ])->key('registrar')->extraAttributes(['x-data' => '', 'x-init' => <<<'JS'
                $nextTick(() => {
                    const heading = $el.closest('[wire\\:id]')?.querySelector('h1')
                    if (heading) { heading.tabIndex = -1; heading.focus() }
                })
                JS], merge: true),
            Group::make([
                Group::make([
                    Checkbox::make('register')->label('Registrations')->live(),
                    Checkbox::make('transfer')->label('Transfers')->live(),
                ])->columns(['sm' => 2]),
                Select::make('longest')->label('Longest term')->options($terms)
                    ->selectablePlaceholder(false)->required()->hidden(!$canSetTerm)->live(),
            ])->key('terms'),
            Group::make([
                Radio::make('pricing')->label('Pricing')->hiddenLabel()->options($pricing)->required()->live(),
                ...SettingsActions::pricingRuleFields(static fn() => null, $custom, methodLabel: 'Pricing method', requireFixed: true),
            ])->key('pricing'),
        ]);
    }

    public function confirmationForm(Schema $schema): Schema
    {
        return $schema->statePath('confirmation')->components([
            TextInput::make('reason')->label('Reason')->helperText('Shown in each saved TLD’s History.')
                ->required()->minLength(5)->maxLength(500)->rule('regex:'.StaffReason::SINGLE_LINE)
                ->visible(fn() => isset($this->review['review_key'])),
        ]);
    }

    public function updatedDraft(): void
    {
        $this->review = [];
        $this->previousReview = [];
        $this->problem = null;
    }

    public function reviewChanges(): void
    {
        $this->submit(false);
    }

    public function save(): void
    {
        if (isset($this->review['review_key'])) {
            $this->submit(true);
        }
    }

    private function submit(bool $confirming): void
    {
        $this->hydrate();
        if ($this->outcome !== null) {
            return;
        }
        $this->problem = null;
        $review = $this->review;
        try {
            if (self::stamp($this->records()) !== $this->origin['stamp']
                || isset($this->origin['until']) && array_filter($this->selected, EndingChoices::hidden(...))) {
                $this->updatedDraft();
                $this->problem = self::SELECTION_CHANGED;
                return;
            }
            $state = $this->form->getState();
            $server = filter_var($state['server'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($server === false) {
                throw new \InvalidArgumentException('Invalid sales setup options.');
            }
            $options = self::setupOptions($state);
            if (!$options['register'] && !$options['transfer']) {
                throw ValidationException::withMessages(['draft.register' => 'Choose registrations, transfers or both.']);
            }
            $writer = SalesSetup::routed() ?? throw new \DomainException('Sales setup is unavailable.');
            if (!$confirming) {
                $this->review = [...$writer->preview($server, $this->selected, $options), 'server' => $server, 'options' => $options];
                return;
            }
            if ($server !== $review['server'] || $options !== $review['options']) {
                $this->updatedDraft();
                $this->problem = 'Settings changed. Review the changes again.';
                return;
            }
            $reason = trim((string) $this->confirmationForm->getState()['reason']);
            try {
                StaffReason::require($reason);
            } catch (\DomainException $error) {
                throw ValidationException::withMessages(['confirmation.reason' => $error->getMessage()]);
            }
            try {
                $this->outcome = $writer->save($server, $this->selected, $options, $review['review_key'], $reason);
            } catch (\DomainException $error) {
                if (!in_array($error->getMessage(), ['Settings changed. Reload this page.', 'Invalid review key.'], true)) {
                    throw $error;
                }
                // The old rows are for display only. Saving again needs a newly reviewed key.
                $this->previousReview = $review['rows'];
                $this->review = [];
                $this->reviewChanges();
                return;
            }
            $this->updatedDraft();
            unset($this->cachedSchemas['content']);
        } catch (ValidationException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $this->review = [];
            $this->problem = self::salesSetupRefusal($error, $confirming, (int) ($server ?? 0));
        }
    }

    public function cancelAction(): Action
    {
        return Action::make('cancel')->label('Cancel')->color('gray')->outlined()
            ->modal(fn() => $this->draftStamp() !== $this->initial)->requiresConfirmation()
            ->modalHeading('Discard sales setup?')->modalSubmitActionLabel('Discard changes')->modalCancelActionLabel('Keep editing')
            ->action(function (): void {
                $this->hydrate();
                if (isset($this->origin['until']) && self::stamp($this->records()) === $this->origin['stamp']) {
                    session()->flash(self::RETURN_SELECTION, ['actor' => $this->origin['actor'], 'ids' => $this->origin['ids']]);
                }
                $this->updatedDraft();
                $this->draft = $this->confirmation = [];
                $this->redirect($this->origin['url'].'#set-up-sales');
            });
    }

    private function draftStamp(): string { return hash('sha256', serialize([$this->draft, $this->confirmation])); }

    private static function setupOptions(array $state): array
    {
        $term = $state['longest'] ?? 'keep';
        $longest = $term === 'keep' ? null : filter_var($term, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);
        if ($longest === false) {
            throw new \InvalidArgumentException('Invalid sales setup options.');
        }
        $options = [
            'register' => ($state['register'] ?? false) === true,
            'transfer' => ($state['transfer'] ?? false) === true,
            'longest' => $longest,
            'pricing' => $state['pricing'] ?? null,
        ];
        if ($options['pricing'] === 'custom') {
            $options['rule'] = Arr::only($state, ($state['mode'] ?? null) === 'fixed'
                ? ['mode', 'fixed_register', 'fixed_renew', 'fixed_transfer'] : ['mode', 'markup', 'round_up']);
        }
        return $options;
    }

    private static function salesSetupRefusal(\Throwable $error, bool $confirming, int $server): string
    {
        $message = $error->getMessage();
        if ($message === 'Current staff permissions required.') {
            return 'Your permissions changed. Nothing was saved.';
        }
        if ($message === 'Review key used.') {
            return 'This review was already submitted. Check the TLDs before trying again.';
        }
        if ($error instanceof \DomainException || $error instanceof \InvalidArgumentException) {
            if (!$confirming && in_array($message, ['Invalid sales setup options.', 'Invalid pricing fields.'], true)) {
                return 'Check the sales and pricing settings and review again.';
            }
            if (!$confirming && $message === 'Sales setup is unavailable.') {
                return 'Sales setup is unavailable. Check System checks before trying again.';
            }
            return SettingsActions::refusal($error, 'set_up_sales', server: $server);
        }
        Log::warning('Domains sales setup page failed.', ['server_id' => $server, 'exception_class' => $error::class]);
        return $confirming
            ? 'Check the TLDs and their History before trying again.'
            : 'The review could not be loaded. Check the registrar account and try again.';
    }
}
