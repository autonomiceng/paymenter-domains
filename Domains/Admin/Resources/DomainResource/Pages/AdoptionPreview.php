<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages;
use Paymenter\Extensions\Servers\Domains\Services\StaffReason;

use Filament\Actions\Action;
use Filament\Forms\Components\{TextInput, Toggle};
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\{Schema, Components\Section};
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Data\Adoption\{Review, StaffContext, Target};
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\AdoptionReader;

class AdoptionPreview extends ViewRecord
{
    protected static string $resource = DomainResource::class;
    #[\Livewire\Attributes\Locked] public array $review = [];
    #[\Livewire\Attributes\Locked] public string $reviewReason = '';
    protected function fillForm(): void { $this->data = []; }
    public function mount(int|string $record): void
    { self::requirePermission(); parent::mount($record); abort_unless($this->getRecord()->adoptionPending(), 404); }
    private static function requirePermission(): void { abort_unless(Staff::can(Staff::ADOPT_PREVIEW), 403); }
    /** Confirm is always disabled here: this page has no import step for domains that are already imported without purchase records. */
    public const UNAVAILABLE = "You can't import from this page. You can check the domain, but nothing is imported.";
    public function getTitle(): string { self::requirePermission(); return 'Preview import'; }
    public function getBreadcrumb(): string { return 'Preview import'; }
    public function getRelationManagers(): array { return []; }
    protected function getHeaderActions(): array
    {
        self::requirePermission();
        return [Action::make('preview')->label('Check domain')->modalHeading('Check the domain at the registrar')
            ->modalDescription('We look up this name in its saved registrar account. Nothing is charged or imported.')
            ->modalSubmitActionLabel('Check domain')->schema([
                TextInput::make('reason')->label('Reason')->required()->maxLength(500)
                    ->helperText('Say how you confirmed this domain belongs to this customer and service. Use one line. Leave out contact details and credentials.')
                    ->rules(['regex:'.StaffReason::SINGLE_LINE])->validationMessages(['regex' => 'Use one line without tabs or control characters.']),
                Toggle::make('do_not_renew')->label('Do not renew')->default(false)
                    ->helperText('Saved for review only. This does not cancel the service or change auto-renew at the registrar.'),
            ])->action(function (array $data): void {
                self::requirePermission(); $this->review = []; $this->reviewReason = '';
                $d = $this->getRecord()->fresh(); abort_unless($d?->adoptionPending(), 404);
                try {
                    $context = new StaffContext((int) Auth::id(), trim((string) ($data['reason'] ?? '')), (bool) ($data['do_not_renew'] ?? false));
                    $this->reviewReason = $context->reason;
                    $a = Account::where('server_id', $d->server_id)->first();
                    if (!$a || !in_array($a->environment, ['test', 'live'], true)) { $this->review = (new Review('account_unconfirmed'))->jsonSerialize(); return; }
                    $result = app(AdoptionReader::class)->preview(new Target($d->domain, (int) $d->service_id, (int) $d->user_id,
                        (int) $d->server_id, Environment::from($a->environment)), $context);
                    $this->review = $result->jsonSerialize();
                } catch (\Throwable $error) {
                    \Illuminate\Support\Facades\Log::warning('Domain adoption preview failed.', ['exception_type' => get_class($error)]);
                    $this->review = (new Review('preview_failed'))->jsonSerialize();
                }
            }), Action::make('confirm')->label('Confirm import')->color('gray')->disabled()->tooltip(self::UNAVAILABLE)];
    }
    private function facts(): array
    {
        self::requirePermission(); $d = $this->getRecord(); $details = $this->review['details'] ?? [];
        $source = $details['source_expiry'] ?? ($d->renewal_policy['conversion_source_expiry'] ?? $d->provider_expires_at?->format('Y-m-d'));
        try { new \Paymenter\Extensions\Servers\Domains\Data\DateEvidence($source ?? '', \Paymenter\Extensions\Servers\Domains\Data\ExpiryPrecision::Date, 'conversion_source'); }
        catch (\Throwable) { $source = null; }
        return ['Name' => $d->domain, 'Customer' => 'Customer #'.$d->user_id.' ('.(\App\Models\User::find($d->user_id)?->name ?? 'Unconfirmed').')', 'Service' => 'Service #'.$d->service_id,
            'Result' => ($this->review['code'] ?? null) === 'ready' && ($details['test_only'] ?? false) ? 'Test only. No real domain is imported.' : ($this->review['message'] ?? 'Not checked yet.'),
            'Expiry' => AdoptDomain::when($details['expiry'] ?? null), 'Expiry on file' => AdoptDomain::when($source),
            'Renewal due' => ($details['do_not_renew'] ?? false) ? 'None. Do not renew was requested.' : AdoptDomain::when($details['renewal_due'] ?? null),
            'Current annual charge' => isset($details['annual_charge']) ? $details['annual_charge'].' USD (including tax)' : 'Unconfirmed',
            'Price review' => isset($details['annual_charge']) ? Review::priceNotice($details['price_notice'] ?? null) : 'Unconfirmed',
            'Past invoices' => empty($details['history']) ? 'No verified past invoices.'
                : array_map(fn ($i) => 'Invoice #'.$i['invoice_id'].' ('.ucfirst($i['status']).')', $details['history']),
            'Do not renew' => ($details['existing_do_not_renew'] ?? false) ? 'Already set to cancel at the end of the period. This check leaves that as is.' : (($details['do_not_renew'] ?? false) ? 'Requested for review. No cancellation was created.' : 'Not requested.'),
            'Reason' => $this->reviewReason !== '' ? $this->reviewReason : 'Needed when you check the domain.'];
    }
    private function environmentLabel(): string
    {
        $environment = $this->review['details']['environment'] ?? Account::where('server_id', $this->getRecord()->server_id)->value('environment');
        return $environment === 'test' ? 'Test only' : ($environment === 'live' ? 'Live' : 'Unconfirmed');
    }
    public function infolist(Schema $schema): Schema
    {
        self::requirePermission();
        $entries = [TextEntry::make('environment')->label('Registrar account')->state(fn () => $this->environmentLabel())
            ->badge()->color(fn () => str_starts_with($this->environmentLabel(), 'Test') ? 'warning' : 'gray')];
        foreach (array_keys($this->facts()) as $label) {
            $entries[] = TextEntry::make('fact_'.count($entries))->label($label)->state(fn () => $this->facts()[$label])->listWithLineBreaks()->extraAttributes(['style' => 'overflow-wrap:anywhere']);
        }
        $entries[] = TextEntry::make('unavailable')->label("Why you can't confirm")->hiddenLabel()->state(self::UNAVAILABLE)->columnSpanFull();
        return $schema->components([Section::make('Import is off on this site')->description(fn () => 'Preview only. Nothing is charged or imported, and past invoices stay the same.'.(str_starts_with($this->environmentLabel(), 'Test') ? ' This is a test account. It cannot confirm a live domain.' : ''))
            ->columnSpanFull()->columns(2)->schema($entries)]);
    }
}
