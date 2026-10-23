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
use Paymenter\Extensions\Servers\Domains\Services\{AdoptionCommit, AdoptionReader, AdoptionRefused};
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
    public function getTitle(): string { return 'Adopt a registration'; }
    public function getBreadcrumb(): string { return 'Adopt registration'; }
    protected function getHeaderActions(): array { return []; }
    public function chooseAction(): Action
    {
        return Action::make('choose')->label($this->target ? 'Change selection' : 'Choose customer and service')
            ->modalHeading('1. Choose the registration service')->modalSubmitActionLabel('Use selection')->schema([
                Select::make('customer')->label('Customer')->required()->searchable()->live()
                    ->getSearchResultsUsing(fn (string $search) => User::where(fn ($q) => $q->where('first_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%'))->orderBy('id')->limit(25)->get()->mapWithKeys(fn ($u) => [$u->id => 'Customer #'.$u->id.' — '.$u->name])->all())
                    ->getOptionLabelUsing(fn ($value) => User::find($value)?->name),
                Select::make('service')->label('Dedicated domain service')->required()->searchable()
                    ->options(fn ($get) => Service::where('user_id', $get('customer'))->whereHas('product.server', fn ($q) => $q->where('extension', 'Domains'))
                        ->orderBy('id')->limit(100)->get()->mapWithKeys(fn ($s) => [$s->id => 'Service #'.$s->id.' — '.($s->properties()->where('key', 'domain')->value('value') ?? 'Unconfirmed name')])->all()),
                Select::make('account')->label('Registrar account')->required()->options(fn () => Account::where('registrar_key', 'enom')->orderBy('server_id')->get()
                    ->mapWithKeys(fn ($a) => [$a->server_id => 'Account #'.$a->server_id.' · '.($a->environment === 'test' ? 'TEST' : 'Live')])->all()),
            ])->action(function (array $data): void {
                self::permission(); $this->review = []; $this->previewId = null; $this->reason = ''; $this->adoptedId = null; $this->refusal = null;
                $s = Service::whereKey($data['service'])->where('user_id', $data['customer'])->firstOrFail();
                $a = Account::where('server_id', $data['account'])->firstOrFail();
                abort_unless((int) $s->product?->server_id === (int) $a->server_id, 422);
                $names = $s->properties()->where('key', 'domain')->pluck('value'); abort_unless($names->count() === 1, 422);
                try { $name = CanonicalName::normalize($names->sole()); } catch (\Throwable) { abort(422); }
                $this->target = ['name' => $name, 'customer' => (int) $s->user_id, 'service' => (int) $s->id, 'account' => (int) $a->server_id, 'environment' => $a->environment];
                unset($this->cachedSchemas['content']);
            });
    }
    public function readbackAction(): Action
    {
        return Action::make('readback')->label('Read fresh preview')->visible(fn () => $this->target !== [] && $this->adoptedId === null)
            ->modalHeading('2. Read fresh registration facts')->modalSubmitActionLabel('Read fresh preview')->modalDescription('Read this exact name from the selected account. No charge occurs now. The preview expires after 120 seconds.')
            ->schema([TextInput::make('reason')->label('Reason')->required()->maxLength(500)->rules(['regex:/\A[^\x00-\x1f\x7f]*\z/u'])
                ->helperText('Explain how you verified this customer and service. Omit contacts and credentials.'),
                Toggle::make('do_not_renew')->label('Do not renew')->helperText('At adoption, record an end-of-period cancellation. No renewal invoice, reminder or renewal request will be scheduled.')])
            ->action(function (array $data): void {
                self::permission(); $this->review = []; $this->previewId = null; $this->refusal = null;
                try {
                    $t = $this->target; $context = new StaffContext((int) Auth::id(), trim($data['reason']), (bool) ($data['do_not_renew'] ?? false));
                    $this->reason = $context->reason;
                    $result = app(AdoptionReader::class)->preview(new Target($t['name'], $t['service'], $t['customer'], $t['account'], Environment::from($t['environment'])), $context);
                    $this->review = $result->jsonSerialize(); $this->previewId = $result->previewId;
                } catch (\Throwable) { $this->review = (new Review('preview_failed'))->jsonSerialize(); }
                unset($this->cachedSchemas['content']);
            });
    }
    private function confirmable(): bool
    { return config('domains.adoption.enabled', false) === true && Staff::can(Staff::ADOPT) && ($this->review['code'] ?? null) === 'ready' && ($this->review['details']['environment'] ?? null) === 'live' && $this->adoptedId === null; }
    public function confirmAction(): Action
    {
        return Action::make('confirm')->label('Confirm adoption')->visible(fn () => $this->previewId !== null && $this->adoptedId === null)
            ->disabled(fn () => !$this->confirmable())->modalHeading(fn () => 'Adopt '.($this->target['name'] ?? 'registration').' for '.$this->customerLabel())->modalSubmitActionLabel('Confirm adoption')
            ->modalDescription(function (): string {
                $facts = $this->review['details'] ?? [];
                return 'Customer #'.$this->target['customer'].' · Service #'.$this->target['service'].' · Account #'.$this->target['account']
                    .'. Expiry: '.self::when($facts['expiry'] ?? null).'. Annual charge: '.($facts['annual_charge'] ?? 'Unconfirmed').' USD including applicable tax. '
                    .(($facts['do_not_renew'] ?? false) ? 'Do not renew; retain end-of-period cancellation. ' : 'Renewal on; ordinary billing gates still apply. ')
                    .'No charge occurs now. Reason: '.$this->reason;
            })
            ->schema([Toggle::make('verified')->label('I verified the customer, service, expiry, annual charge and renewal intent above.')->required()->accepted()])
            ->action(function (): void {
                self::permission(); abort_unless($this->confirmable(), 403);
                $a = Adoption::whereKey($this->previewId)->where('actor_id', Auth::id())->firstOrFail();
                try {
                    $d = app(AdoptionCommit::class)->confirm($a->request_key, $a->request_digest, Auth::user());
                    $this->adoptedId = (int) $d->id; $this->refusal = null;
                } catch (\Throwable $e) { $this->refusal = self::refused($e); $this->previewId = null; $this->review = []; }
                unset($this->cachedSchemas['content']);
            });
    }
    /** One plain sentence for a disabled confirm action, in the order staff can act on it. */
    private function unavailable(): ?string
    {
        if ($this->previewId === null || $this->confirmable()) { return null; }
        if (config('domains.adoption.enabled', false) !== true) { return 'Confirmation is unavailable because adoption is switched off on this site; previews still work, but nothing can be adopted until it is switched on.'; }
        if (($this->review['code'] ?? null) !== 'ready') { return 'Confirmation is unavailable because this preview was refused; resolve the issue shown in Result, then read a fresh preview.'; }
        if (($this->review['details']['environment'] ?? null) !== 'live') { return 'Confirmation is unavailable because this is a TEST account rehearsal; choose a live account and read a fresh preview to adopt.'; }
        return 'Confirmation is unavailable because your role can preview but not confirm adoptions; ask the site administrator for the adopt permission.';
    }
    /** Fixed refusal sentences; exception text never reaches the browser. Mapped refusals are thrown before the attempt commits; anything else may follow a commit, so its outcome is reported as uncertain. */
    private static function refused(\Throwable $e): string
    {
        return match ($e instanceof AdoptionRefused ? $e->reason : null) {
            RefusalCode::VerificationChanged, RefusalCode::RequestChanged => 'Confirmation was refused because the preview expired or a detail changed; this attempt changed and charged nothing, so read a fresh preview to try again.',
            RefusalCode::Permission => 'Confirmation was refused because your role is missing a permission adoption needs; this attempt changed and charged nothing, so ask the site administrator to check your role.',
            RefusalCode::ResultReview, RefusalCode::IdentityReview, RefusalCode::HoldingReview => 'Confirmation was refused because existing records for this name do not match this preview; this attempt changed and charged nothing, so check the registration before trying again.',
            RefusalCode::Disabled => 'Confirmation was refused because adoption is switched off on this site; this attempt changed and charged nothing, so ask the site administrator whether adoption should be switched on.',
            default => 'Confirmation could not be completed and its outcome is unknown; check whether this name now appears as an adopted registration before reading a fresh preview.',
        };
    }
    /** Registrar expiry and calendar dates for staff: date-only stays a date, instants show in UTC. */
    public static function when(?string $value): string
    {
        if ($value === null || $value === '') { return 'Unconfirmed'; }
        try {
            $utc = new \DateTimeZone('UTC');
            return preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $utc)->format('M j, Y')
                : (new \DateTimeImmutable($value))->setTimezone($utc)->format('M j, Y H:i').' UTC';
        } catch (\Throwable) { return 'Unconfirmed'; }
    }
    private function customerLabel(): string
    { return User::find($this->target['customer'] ?? null)?->email ?? 'Customer #'.($this->target['customer'] ?? ''); }
    private static function readinessList(array $items): string
    {
        return '<ul style="list-style:disc outside;padding-inline-start:1.25rem;margin:0">'
            .implode('', array_map(fn ($item) => '<li style="margin-block:.25rem">'.e($item).'</li>', $items)).'</ul>';
    }
    public function content(Schema $schema): Schema
    {
        self::permission(); $sections = []; $enabled = config('domains.adoption.enabled', false) === true;
        $open = ['Run an end-to-end check on this site.',
            'Close the gap where deleting a customer during checkout can remove their new order and service.',
            'Confirm the live registrar account and its standard renewal pricing.',
            'Approve managed public DNS for adopted names. It is recorded but stays inactive until then.',
            "Check simultaneous staff and customer edits against this site's database."];
        if (!$enabled) { $open[] = 'Before switching on: let in-progress customer, order and service edits finish, then restart web and queue workers.'; }
        $readiness = Section::make($enabled ? 'Adoption review' : 'Adoption is not enabled yet')
            ->description($enabled ? 'No charge occurs now. Adoption does not buy, transfer or renew a registration. Historical invoices remain unchanged.'
                : 'You can choose a service and read previews now. Previews charge nothing and adopt nothing. Adoption does not buy, transfer or renew a registration, and historical invoices stay unchanged. Switching adoption on is a site configuration change, not a staff action.')
            ->schema([TextEntry::make('done')->label('Done')->state(self::readinessList(['Registrar test scenarios pass.']))->html(),
                TextEntry::make('readiness')->label($enabled ? 'Known open items' : 'Still needed')->state(self::readinessList($open))->html()]);
        if (!$enabled) { $readiness->icon('heroicon-o-exclamation-triangle')->iconColor('warning'); }
        $sections[] = $readiness;
        $sections[] = Section::make('1. Customer, service and account')->columns(2)->schema([
            TextEntry::make('selection')->label('Selected registration')->state($this->target ? $this->target['name'].' · '.$this->customerLabel().' · Service #'.$this->target['service'] : 'Choose a dedicated domain service.'),
            TextEntry::make('environment')->label('Registrar account')->state($this->target ? (Server::find($this->target['account'])?->name ?? 'Account #'.$this->target['account']).' · '.($this->target['environment'] === 'test' ? 'Test account. A rehearsal; it does not verify a live registration.' : 'Live') : 'Not selected'),
            Actions::make([$this->chooseAction()])->columnSpanFull()]);
        if ($this->target && $this->adoptedId === null) { $sections[] = Section::make('2. Fresh readback')->description('Readback is an explicit staff action. Facts expire after 120 seconds.')->schema([Actions::make([$this->readbackAction()])]); }
        if ($this->refusal) { $sections[] = Section::make('Confirmation not completed')->description($this->refusal)->schema([]); }
        if ($this->review && $this->adoptedId === null) {
            $details = $this->review['details'] ?? []; $a = $this->previewId ? Adoption::whereKey($this->previewId)->where('actor_id', Auth::id())->first() : null;
            $facts = ['Result' => ($this->review['code'] ?? '') === 'ready' ? (($details['test_only'] ?? false) ? 'TEST rehearsal only — no live authority' : 'Verified preview') : $this->review['message'],
                'Expiry' => self::when($details['expiry'] ?? null), 'Renewal due' => ($details['do_not_renew'] ?? false) ? 'No renewal — renewal off' : self::when($details['renewal_due'] ?? null),
                'Annual charge' => isset($details['annual_charge']) ? $details['annual_charge'].' USD (including applicable tax)' : 'Unconfirmed',
                'Price review' => Review::priceNotice($details['price_notice'] ?? null),
                'Historical invoices' => array_map(fn ($i) => 'Invoice #'.$i['invoice_id'].' — '.ucfirst($i['status']), $details['history'] ?? []) ?: ['No verified historical invoices.'],
                'Renewal intent' => ($details['test_only'] ?? false) ? 'Rehearsal only. No invoice, charge or live renewal authority is created.' : (($details['do_not_renew'] ?? false) ? 'Do not renew. End-of-period cancellation will be retained.' : 'Renewal on. An invoice may be issued immediately if the registration is already inside its billing window. Future admission and billing gates still apply.'),
                'Reason' => $this->reason, 'Preview valid until' => $a?->valid_until ? $a->valid_until->setTimezone(new \DateTimeZone('UTC'))->format('M j, Y H:i:s').' UTC' : 'Expired — read again'];
            $entries = []; foreach ($facts as $label => $value) { $entries[] = TextEntry::make('review_'.count($entries))->label($label)->state($value)->listWithLineBreaks(); }
            if ($why = $this->unavailable()) { $entries[] = TextEntry::make('unavailable')->label('Why confirmation is unavailable')->hiddenLabel()->state($why)->columnSpanFull(); }
            $sections[] = Section::make('3. Review verified facts')->columns(2)->schema([...$entries, Actions::make([$this->confirmAction()])->columnSpanFull()]);
        }
        if ($this->adoptedId !== null) {
            $d = Domain::findOrFail($this->adoptedId); $a = Adoption::findOrFail($d->adoption_id);
            $sections[] = Section::make('Adopted')->description($a->do_not_renew ? 'Renewal off. No renewal invoices or renewal reminders are scheduled.' : 'Registration adopted. Future renewal admission and billing gates still apply.')
                ->schema([TextEntry::make('adopted')->label('Registration')->state($d->domain.' · Service #'.$d->service_id.' · Expires '.$d->provider_expires_at->format('M j, Y')),
                    Actions::make([Action::make('holding')->label('View adopted registration')->url(DomainResource::getUrl('view', ['record' => $d->id]))])]);
        }
        return $schema->components($sections);
    }
}
