<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages;
use App\Models\{Service, User};
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
use Paymenter\Extensions\Servers\Domains\Data\Adoption\{Review, StaffContext, Target};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Adoption, Domain};
use Paymenter\Extensions\Servers\Domains\Services\{AdoptionCommit, AdoptionReader};
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
            ->disabled(fn () => !$this->confirmable())->modalHeading('4. Confirm adoption')->modalSubmitActionLabel('Confirm adoption')
            ->modalDescription(function (): string {
                $facts = $this->review['details'] ?? [];
                return 'Adopt '.$this->target['name'].' for Customer #'.$this->target['customer'].' / Service #'.$this->target['service'].' / Account #'.$this->target['account']
                    .'. Expiry (UTC): '.($facts['expiry'] ?? 'Unconfirmed').'. Annual charge: '.($facts['annual_charge'] ?? 'Unconfirmed').' USD including applicable tax. '
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
                } catch (\Throwable) { $this->refusal = 'Verification changed or expired. Read a fresh preview before trying again.'; $this->previewId = null; $this->review = []; }
                unset($this->cachedSchemas['content']);
            });
    }
    public function content(Schema $schema): Schema
    {
        self::permission(); $sections = [];
        $sections[] = Section::make(config('domains.adoption.enabled', false) === true ? 'Adoption review' : 'Adoption is not enabled yet')
            ->description('No charge occurs now. Adoption does not buy, transfer or renew a registration. Historical invoices remain unchanged.')
            ->schema([TextEntry::make('readiness')->label('Before activation')->state(['A0: install identity guards and drain in-flight native edits; user-deletion race remains open.',
                'Scenario baseline: accepted paired registrar scenarios; qualify the installed site.', 'Provider contract: accepted live account identity and ordinary renewal evidence required.', 'Managed public DNS: enrollment is retained; public readers and notice delivery need separate acceptance.'])->listWithLineBreaks()]);
        $sections[] = Section::make('1. Customer, service and account')->columns(2)->schema([
            TextEntry::make('selection')->label('Selected registration')->state($this->target ? $this->target['name'].' · Customer #'.$this->target['customer'].' · Service #'.$this->target['service'] : 'Choose a dedicated domain service.'),
            TextEntry::make('environment')->label('Proof account')->state($this->target ? 'Account #'.$this->target['account'].' · '.($this->target['environment'] === 'test' ? 'TEST — Test-account rehearsal. This does not verify a live registration.' : 'Live') : 'Not selected'),
            Actions::make([$this->chooseAction()])->columnSpanFull()]);
        if ($this->target && $this->adoptedId === null) { $sections[] = Section::make('2. Fresh readback')->description('Readback is an explicit staff action. Facts expire after 120 seconds.')->schema([Actions::make([$this->readbackAction()])]); }
        if ($this->refusal) { $sections[] = Section::make('Confirmation refused')->description($this->refusal)->schema([]); }
        if ($this->review && $this->adoptedId === null) {
            $details = $this->review['details'] ?? []; $a = $this->previewId ? Adoption::whereKey($this->previewId)->where('actor_id', Auth::id())->first() : null;
            $facts = ['Result' => ($this->review['code'] ?? '') === 'ready' ? (($details['test_only'] ?? false) ? 'TEST rehearsal only — no live authority' : 'Verified preview') : $this->review['message'],
                'Expiry (UTC)' => $details['expiry'] ?? 'Unconfirmed', 'Renewal due' => ($details['do_not_renew'] ?? false) ? 'No renewal — renewal off' : ($details['renewal_due'] ?? 'Unconfirmed'),
                'Annual charge' => isset($details['annual_charge']) ? $details['annual_charge'].' USD (including applicable tax)' : 'Unconfirmed',
                'Price review' => Review::priceNotice($details['price_notice'] ?? null),
                'Historical invoices' => array_map(fn ($i) => 'Invoice #'.$i['invoice_id'].' — '.ucfirst($i['status']), $details['history'] ?? []) ?: ['No verified historical invoices.'],
                'Renewal intent' => ($details['test_only'] ?? false) ? 'Rehearsal only. No invoice, charge or live renewal authority is created.' : (($details['do_not_renew'] ?? false) ? 'Do not renew. End-of-period cancellation will be retained.' : 'Renewal on. An invoice may be issued immediately if the registration is already inside its billing window. Future admission and billing gates still apply.'),
                'Reason' => $this->reason, 'Preview valid until (UTC)' => $a?->valid_until?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') ?? 'Expired — read again'];
            $entries = []; foreach ($facts as $label => $value) { $entries[] = TextEntry::make('review_'.count($entries))->label($label)->state($value)->listWithLineBreaks(); }
            $sections[] = Section::make('3. Review verified facts')->columns(2)->schema([...$entries, Actions::make([$this->confirmAction()])->columnSpanFull()]);
        }
        if ($this->adoptedId !== null) {
            $d = Domain::findOrFail($this->adoptedId); $a = Adoption::findOrFail($d->adoption_id);
            $sections[] = Section::make('Adopted')->description($a->do_not_renew ? 'Renewal off. No renewal invoices or renewal reminders are scheduled.' : 'Registration adopted. Future renewal admission and billing gates still apply.')
                ->schema([TextEntry::make('adopted')->label('Registration')->state($d->domain.' · Service #'.$d->service_id.' · expiry '.$d->provider_expires_at->format('Y-m-d')),
                    Actions::make([Action::make('holding')->label('View adopted registration')->url(DomainResource::getUrl('view', ['record' => $d->id]))])]);
        }
        return $schema->components($sections);
    }
}
