<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages;

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
    public function getTitle(): string { self::requirePermission(); return 'Preview domain adoption'; }
    public function getBreadcrumb(): string { return 'Adoption preview'; }
    public function getRelationManagers(): array { return []; }
    protected function getHeaderActions(): array
    {
        self::requirePermission();
        return [Action::make('preview')->label('Read fresh preview')->modalHeading('Read fresh adoption preview')
            ->modalDescription('Read this one name from its saved account. No charge occurs now. Adoption remains disabled.')
            ->modalSubmitActionLabel('Read fresh preview')->schema([
                TextInput::make('reason')->label('Reason')->required()->maxLength(500)
                    ->helperText('Explain how you verified the customer and service mapping. Use one line; omit contacts and credentials.')
                    ->rules(['regex:/\A[^\x00-\x1f\x7f]*\z/u'])->validationMessages(['regex' => 'Use one line without tabs or control characters.']),
                Toggle::make('do_not_renew')->label('Do not renew')->default(false)
                    ->helperText('Review intent only. This preview creates no cancellation and does not change registrar auto-renew.'),
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
            }), Action::make('confirm')->label('Confirm adoption')->color('gray')->disabled()->tooltip('Adoption is not enabled yet')];
    }
    private function facts(): array
    {
        self::requirePermission(); $d = $this->getRecord(); $details = $this->review['details'] ?? [];
        $source = $details['source_expiry'] ?? ($d->renewal_policy['conversion_source_expiry'] ?? $d->provider_expires_at?->format('Y-m-d'));
        try { new \Paymenter\Extensions\Servers\Domains\Data\DateEvidence($source ?? '', \Paymenter\Extensions\Servers\Domains\Data\ExpiryPrecision::Date, 'conversion_source'); }
        catch (\Throwable) { $source = null; }
        return ['Name' => $d->domain, 'Customer' => 'Customer #'.$d->user_id.' — '.(\App\Models\User::find($d->user_id)?->name ?? 'Unconfirmed'), 'Service' => 'Service #'.$d->service_id,
            'Result' => $this->review['message'] ?? 'No fresh preview yet.', 'Expiry' => isset($details['expiry']) ? (new \DateTimeImmutable($details['expiry']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i').' UTC' : 'Unconfirmed',
            'Source expiry' => $source ? $source.' (UTC date)' : 'Unconfirmed',
            'Renewal due' => ($details['do_not_renew'] ?? false) ? 'No renewal (do not renew)' : ($details['renewal_due'] ?? 'Unconfirmed'),
            'Current annual charge' => isset($details['annual_charge']) ? $details['annual_charge'].' USD (including applicable tax)' : 'Unconfirmed',
            'Price review' => isset($details['annual_charge']) ? Review::priceNotice($details['price_notice'] ?? null) : 'Unconfirmed',
            'Historical invoices' => empty($details['history']) ? 'No verified historical invoices in this preview.'
                : array_map(fn ($i) => 'Invoice #'.$i['invoice_id'].' — '.ucfirst($i['status']), $details['history']),
            'Do not renew' => ($details['existing_do_not_renew'] ?? false) ? 'Existing end-of-period cancellation; preview leaves it unchanged.' : (($details['do_not_renew'] ?? false) ? 'Requested for later adoption review; no cancellation created.' : 'Not requested.'),
            'Reason' => $this->reviewReason !== '' ? $this->reviewReason : 'Required when reading a fresh preview.'];
    }
    private function environmentLabel(): string
    {
        $environment = $this->review['details']['environment'] ?? Account::where('server_id', $this->getRecord()->server_id)->value('environment');
        return $environment === 'test' ? 'TEST — rehearsal only' : ($environment === 'live' ? 'Live' : 'Unconfirmed');
    }
    public function infolist(Schema $schema): Schema
    {
        self::requirePermission();
        $entries = [TextEntry::make('environment')->label('Saved environment')->state(fn () => $this->environmentLabel())
            ->badge()->color(fn () => str_starts_with($this->environmentLabel(), 'TEST') ? 'warning' : 'gray')];
        foreach (array_keys($this->facts()) as $label) {
            $entries[] = TextEntry::make('fact_'.count($entries))->label($label)->state(fn () => $this->facts()[$label])->listWithLineBreaks()->extraAttributes(['style' => 'overflow-wrap:anywhere']);
        }
        return $schema->components([Section::make('Adoption is not enabled yet')->description(fn () => 'Preview only. No charge occurs now. '.(str_starts_with($this->environmentLabel(), 'TEST') ? 'TEST evidence can never establish a live holding. ' : '').'Historical invoices remain unchanged.')
            ->columnSpanFull()->columns(2)->schema($entries)]);
    }
}
