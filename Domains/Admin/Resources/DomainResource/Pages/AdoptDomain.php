<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages;
use App\Models\{Server, Service, User};
use Filament\Actions\Action;
use Filament\Forms\Components\{Select, TextInput, Toggle};
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\Page;
use Filament\Schemas\{Schema, Components\Section, Components\Actions};
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{CanonicalName, Environment};
use Paymenter\Extensions\Servers\Domains\Data\Adoption\{RefusalCode, Review, StaffContext, Target};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Adoption, Domain};
use Paymenter\Extensions\Servers\Domains\Services\{AdoptionCommit, AdoptionReader, AdoptionRefused, StaffReason};
/** Native staff wizard. Request keys, digests and private evidence never leave the server. */
class AdoptDomain extends Page
{
    protected static string $resource = DomainResource::class;
    #[\Livewire\Attributes\Locked] public array $target = [];
    #[\Livewire\Attributes\Locked] public array $review = [];
    #[\Livewire\Attributes\Locked] public string $reason = '';
    #[\Livewire\Attributes\Locked] public ?int $previewId = null;
    #[\Livewire\Attributes\Locked] public ?int $adoptedId = null;
    #[\Livewire\Attributes\Locked] public ?string $refusal = null;
    public function mount(): void { self::permission(); }
    private static function permission(): void { abort_unless(Staff::can(Staff::ADOPT_PREVIEW), 403); }
    public function getTitle(): string { return 'Import a domain'; }
    public function getBreadcrumb(): string { return 'Import domain'; }
    protected function getHeaderActions(): array { return []; }
    public function chooseAction(): Action
    {
        return Action::make('choose')->label($this->target ? 'Change selection' : 'Choose customer and service')
            ->modalHeading('1. Choose the domain service')->modalSubmitActionLabel('Use selection')->schema([
                Select::make('customer')->label('Customer')->required()->searchable()->live()
                    ->getSearchResultsUsing(fn (string $search) => self::customerOptions($search))
                    ->getOptionLabelUsing(fn ($value) => self::customerOptions((string) $value)[$value] ?? null),
                Select::make('service')->label('Domain service')->required()->searchable()
                    ->rule(fn ($get) => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                        $s = Service::whereKey($value)->where('user_id', $get('customer'))->first(); $a = Account::where('server_id', $get('account'))->first();
                        if ($s && $a && is_string($why = self::targetFor($s, $a))) { $fail($why); }
                    })
                    ->options(fn ($get) => Service::where('user_id', $get('customer'))->whereHas('product.server', fn ($q) => $q->where('extension', 'Domains'))
                        ->orderBy('id')->limit(100)->get()->mapWithKeys(fn ($s) => [$s->id => 'Service #'.$s->id.' ('.($s->properties()->where('key', 'domain')->value('value') ?? 'No domain field').')'])->all()),
                Select::make('account')->label('Registrar account')->required()->options(fn () => self::accountOptions()),
            ])->action(function (array $data): void {
                self::permission(); $this->review = []; $this->previewId = null; $this->reason = ''; $this->adoptedId = null; $this->refusal = null;
                $s = Service::whereKey($data['service'])->where('user_id', $data['customer'])->firstOrFail();
                $a = Account::where('server_id', $data['account'])->firstOrFail();
                $target = self::targetFor($s, $a); abort_unless(is_array($target), 422); // The form rule explains this first.
                $this->target = $target;
                unset($this->cachedSchemas['content']);
            });
    }
    /** Customers for the search box: an id always; name or email only for roles that may read user details. */
    public static function customerOptions(string $search): array
    {
        $search = trim($search); $details = Staff::can('admin.users.view'); $id = ctype_digit($search) ? (int) $search : null;
        if ($id === null && !$details) { return []; }
        $like = '%'.addcslashes($search, '%_\\').'%';
        return User::where(fn ($q) => $id !== null ? $q->whereKey($id) : $q->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('email', 'like', $like))
            ->orderBy('id')->limit(25)->get()->mapWithKeys(fn ($u) => [$u->id => $details ? $u->name.' · '.$u->email : 'Customer #'.$u->id])->all();
    }
    /** Enom accounts by name, with their mode. */
    public static function accountOptions(): array
    {
        $accounts = Account::where('registrar_key', 'enom')->orderBy('server_id')->get(); $names = Server::whereIn('id', $accounts->pluck('server_id'))->pluck('name', 'id');
        return $accounts->mapWithKeys(fn ($a) => [$a->server_id => ($names[$a->server_id] ?? 'Account #'.$a->server_id).' · '.($a->environment === 'test' ? 'Test' : 'Live')])->all();
    }
    /** The import target for this service and account, or the sentence that says why it can't be one. */
    private static function targetFor(Service $s, Account $a): array|string
    {
        if ((int) $s->product?->server_id !== (int) $a->server_id) { return 'This service is on another registrar account. Pick that account.'; }
        $names = $s->properties()->where('key', 'domain')->pluck('value');
        try { if ($names->count() !== 1) { throw new \DomainException; } $name = CanonicalName::normalize($names->sole()); }
        catch (\Throwable) { return 'This service has no domain field. Pick another service.'; }
        return ['name' => $name, 'customer' => (int) $s->user_id, 'service' => (int) $s->id, 'account' => (int) $a->server_id, 'environment' => $a->environment];
    }
    public function readbackAction(): Action
    {
        return Action::make('readback')->label('Check domain')->visible(fn () => $this->target !== [] && $this->adoptedId === null)
            ->modalHeading('2. Check the domain at the registrar')->modalSubmitActionLabel('Check domain')->modalDescription('We look up this name in the account you chose. Nothing is charged. The result lasts 2 minutes.')
            ->schema([TextInput::make('reason')->label('Reason')->required()->maxLength(500)->rules(['regex:'.StaffReason::SINGLE_LINE])
                ->helperText('Say how you confirmed this customer and service. Leave out contacts and credentials.'),
                Toggle::make('do_not_renew')->label('Do not renew')->helperText('The domain ends at its expiry date. No renewal invoice, reminder or request goes out.')])
            ->action(function (array $data): void {
                self::permission(); $this->review = []; $this->previewId = null; $this->refusal = null;
                try {
                    $t = $this->target; $context = new StaffContext((int) Auth::id(), trim($data['reason']), (bool) ($data['do_not_renew'] ?? false));
                    $this->reason = $context->reason;
                    $result = app(AdoptionReader::class)->preview(new Target($t['name'], $t['service'], $t['customer'], $t['account'], Environment::from($t['environment'])), $context);
                    $this->review = $result->jsonSerialize(); $this->previewId = $result->previewId;
                } catch (\Throwable $e) { $this->diagnostic($e, 'preview'); $this->review = (new Review('preview_failed'))->jsonSerialize(); }
                unset($this->cachedSchemas['content']);
            });
    }
    private function confirmable(): bool
    { return config('domains.adoption.enabled', false) === true && Staff::can(Staff::ADOPT) && ($this->review['code'] ?? null) === 'ready' && ($this->review['details']['environment'] ?? null) === 'live' && $this->adoptedId === null; }
    public function confirmAction(): Action
    {
        return Action::make('confirm')->label('Confirm import')->visible(fn () => $this->previewId !== null && $this->adoptedId === null)
            ->disabled(fn () => !$this->confirmable())->modalHeading(fn () => 'Import '.($this->target['name'] ?? 'domain').' for '.$this->customerLabel())->modalSubmitActionLabel('Confirm import')
            ->modalDescription(function (): string {
                $facts = $this->review['details'] ?? [];
                return 'Customer #'.$this->target['customer'].' · Service #'.$this->target['service'].' · Account #'.$this->target['account']
                    .'. Expiry: '.self::when($facts['expiry'] ?? null).'. Annual charge: '.($facts['annual_charge'] ?? 'Unconfirmed').' USD including tax. '
                    .(($facts['do_not_renew'] ?? false) ? 'Do not renew. The domain ends at its expiry date. ' : 'Renewal stays on. Normal invoices apply. ')
                    .'Nothing is charged now. Reason: '.$this->reason;
            })
            ->schema([Toggle::make('verified')->label('I checked the details above.')->required()->accepted()])
            ->action(function (): void {
                self::permission(); abort_unless($this->confirmable(), 403);
                $a = Adoption::whereKey($this->previewId)->where('actor_id', Auth::id())->firstOrFail();
                try {
                    $d = app(AdoptionCommit::class)->confirm($a->request_key, $a->request_digest, Auth::user());
                    $this->adoptedId = (int) $d->id; $this->refusal = null;
                } catch (\Throwable $e) { $this->diagnostic($e, 'confirmation'); $this->refusal = self::refused($e); $this->previewId = null; $this->review = []; }
                unset($this->cachedSchemas['content']);
            });
    }
    private function diagnostic(\Throwable $error, string $phase): void
    {
        if ($error instanceof AdoptionRefused) { return; }
        try { \Illuminate\Support\Facades\Log::warning('Domains adoption action failed.', [
            'phase' => $phase, 'exception_class' => $error::class, 'actor_id' => (int) Auth::id(),
            'service_id' => $this->target['service'] ?? null, 'server_id' => $this->target['account'] ?? null, 'preview_id' => $this->previewId]); }
        catch (\Throwable) { /* Diagnostics must preserve fixed browser text. */ }
    }
    /** One plain sentence for a disabled confirm action, in the order staff can act on it. */
    private function unavailable(): ?string
    {
        if ($this->previewId === null || $this->confirmable()) { return null; }
        if (config('domains.adoption.enabled', false) !== true) { return "You can't confirm: import is off on this site. You can check domains, but nothing is imported."; }
        if (($this->review['code'] ?? null) !== 'ready') { return "You can't confirm: this check found a problem. Fix the issue in Result, then check again."; }
        if (($this->review['details']['environment'] ?? null) !== 'live') { return "You can't confirm: this is a test account. Pick a live account and check again."; }
        return "You can't confirm: your role can check domains but not import them. Ask the site administrator.";
    }
    /** Fixed refusal sentences; exception text never reaches the browser. Mapped refusals are thrown before the attempt commits; anything else may follow a commit, so its outcome is reported as uncertain. */
    private static function refused(\Throwable $e): string
    {
        return match ($e instanceof AdoptionRefused ? $e->reason : null) {
            RefusalCode::VerificationChanged, RefusalCode::RequestChanged => 'Not imported: the check expired or a detail changed. Nothing was changed or charged. Check the domain again.',
            RefusalCode::Permission => 'Not imported: your role is missing a permission. Nothing was changed or charged. Ask the site administrator to check your role.',
            RefusalCode::ResultReview, RefusalCode::IdentityReview, RefusalCode::HoldingReview => 'Not imported: saved records for this name do not match this check. Nothing was changed or charged. Check the domain before you try again.',
            RefusalCode::Disabled => 'Not imported: import is off on this site. Nothing was changed or charged. Ask the site administrator.',
            default => "We can't tell if the import finished. See if this name now shows as imported before you check it again.",
        };
    }
    /** Registrar expiry and calendar dates for staff: date-only stays a date, instants show in UTC. */
    public static function when(?string $value): string
    {
        if ($value === null || $value === '') { return 'Unconfirmed'; }
        try {
            $utc = new \DateTimeZone('UTC');
            if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value)) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $utc);
                return $date && $date->format('Y-m-d') === $value ? $date->format('M j, Y') : 'Unconfirmed';
            }
            return (new \DateTimeImmutable($value))->setTimezone($utc)->format('M j, Y H:i').' UTC';
        } catch (\Throwable) { return 'Unconfirmed'; }
    }
    private function customerLabel(): string
    { $id = $this->target['customer'] ?? null; return (Staff::can('admin.users.view') ? User::find($id)?->email : null) ?? 'Customer #'.($id ?? ''); }
    public function content(Schema $schema): Schema
    {
        self::permission(); $sections = []; $enabled = config('domains.adoption.enabled', false) === true;
        $readiness = Section::make($enabled ? 'Before you import' : 'Import is off on this site')
            ->description($enabled ? 'Nothing is charged now. Import does not buy, transfer or renew a domain. Past invoices stay the same.'
                : 'You can check a domain here, but nothing is imported. Ask your site admin to turn it on.')->schema([]);
        if (!$enabled) { $readiness->icon('heroicon-o-exclamation-triangle')->iconColor('warning'); }
        $sections[] = $readiness;
        $sections[] = Section::make('1. Customer, service and account')->columns(2)->schema([
            TextEntry::make('selection')->label('Selected domain')->state($this->target ? $this->target['name'].' · '.$this->customerLabel().' · Service #'.$this->target['service'] : 'Choose a domain service.'),
            TextEntry::make('environment')->label('Registrar account')->state($this->target ? (Server::find($this->target['account'])?->name ?? 'Account #'.$this->target['account']).' · '.($this->target['environment'] === 'test' ? 'Test account. It cannot confirm a live domain.' : 'Live') : 'Not selected'),
            Actions::make([$this->chooseAction()])->columnSpanFull()]);
        if ($this->target && $this->adoptedId === null) { $sections[] = Section::make('2. Check at the registrar')->description('The result lasts 2 minutes.')->schema([Actions::make([$this->readbackAction()])]); }
        if ($this->refusal) { $sections[] = Section::make('Import not finished')->description($this->refusal)->schema([]); }
        if ($this->review && $this->adoptedId === null) {
            $details = $this->review['details'] ?? []; $a = $this->previewId ? Adoption::whereKey($this->previewId)->where('actor_id', Auth::id())->first() : null;
            $facts = ['Result' => ($this->review['code'] ?? '') === 'ready' ? (($details['test_only'] ?? false) ? 'Test only. No real domain is imported.' : 'Checks passed') : $this->review['message'],
                'Expiry' => self::when($details['expiry'] ?? null), 'Renewal due' => ($details['do_not_renew'] ?? false) ? 'No renewal' : self::when($details['renewal_due'] ?? null),
                'Annual charge' => isset($details['annual_charge']) ? $details['annual_charge'].' USD (including tax)' : 'Unconfirmed',
                'Price review' => Review::priceNotice($details['price_notice'] ?? null),
                'Past invoices' => array_map(fn ($i) => 'Invoice #'.$i['invoice_id'].' ('.ucfirst($i['status']).')', $details['history'] ?? []) ?: ['No verified past invoices.'],
                'Renewal' => ($details['test_only'] ?? false) ? 'Test only. No invoice or charge is created.' : (($details['do_not_renew'] ?? false) ? 'Do not renew. The domain ends at its expiry date.' : 'Renewal stays on. Normal invoices apply. If the domain is already due, an invoice may go out right away.'),
                'Reason' => $this->reason, 'Valid until' => $a?->valid_until ? $a->valid_until->setTimezone(new \DateTimeZone('UTC'))->format('M j, Y H:i:s').' UTC' : 'Expired. Check again.'];
            $entries = []; foreach ($facts as $label => $value) { $entries[] = TextEntry::make('review_'.count($entries))->label($label)->state($value)->listWithLineBreaks(); }
            if ($why = $this->unavailable()) { $entries[] = TextEntry::make('unavailable')->label("Why you can't confirm")->hiddenLabel()->state($why)->columnSpanFull(); }
            $sections[] = Section::make('3. Review the details')->columns(2)->schema([...$entries, Actions::make([$this->confirmAction()])->columnSpanFull()]);
        }
        if ($this->adoptedId !== null) {
            $d = Domain::findOrFail($this->adoptedId); $a = Adoption::findOrFail($d->adoption_id);
            $sections[] = Section::make('Imported')->description($a->do_not_renew ? 'Renewal off. No renewal invoices or reminders are scheduled.' : 'Domain imported. Renewals follow the normal rules.')
                ->schema([TextEntry::make('adopted')->label('Domain')->state($d->domain.' · Service #'.$d->service_id.' · Expires '.$d->provider_expires_at->format('M j, Y')),
                    Actions::make([Action::make('holding')->label('View imported domain')->url(DomainResource::getUrl('view', ['record' => $d->id]))])]);
        }
        return $schema->components($sections);
    }
}
