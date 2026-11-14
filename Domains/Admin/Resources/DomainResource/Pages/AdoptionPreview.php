<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages;
use Paymenter\Extensions\Servers\Domains\Services\StaffReason;

use Filament\Actions\Action;
use Filament\Forms\Components\{TextInput, Toggle};
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{AdoptionRows, Staff};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Head;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\RecordDetailPage;
use Paymenter\Extensions\Servers\Domains\Data\Adoption\{Review, StaffContext, Target};
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Presentation\Reader;
use Paymenter\Extensions\Servers\Domains\Services\AdoptionReader;

/** Check one domain waiting for import at its registrar. The check changes nothing, and import is off on this site. */
class AdoptionPreview extends RecordDetailPage
{
    protected static string $resource = DomainResource::class;
    #[\Livewire\Attributes\Locked] public array $review = [];
    #[\Livewire\Attributes\Locked] public string $reviewReason = '';
    public function mount(int|string $record): void
    { self::requirePermission(); parent::mount($record); abort_unless($this->getRecord()->adoptionPending(), 404); }
    private static function requirePermission(): void { abort_unless(Staff::can(Staff::ADOPT_PREVIEW), 403); }
    /** Confirm is always disabled here: this page has no import step for domains that are already imported without purchase records. */
    public const UNAVAILABLE = "You can't import from this page. You can check the domain, but nothing is imported.";
    protected function head(): Head { self::requirePermission(); return Head::make('Preview import', Reader::Staff); }
    public function getBreadcrumb(): string { return 'Preview import'; }
    protected function actions(): array
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
                } finally {
                    // The sheet was built with the empty check, so it is built again from this one.
                    unset($this->cachedSchemas['content']);
                }
            }), Action::make('confirm')->label('Confirm import')->color('gray')->disabled()->tooltip(self::UNAVAILABLE)];
    }
    private function environment(): ?string
    {
        $environment = $this->review['details']['environment'] ?? Account::where('server_id', $this->getRecord()->server_id)->value('environment');
        return in_array($environment, ['test', 'live'], true) ? $environment : null;
    }
    protected function sections(): array
    {
        self::requirePermission();
        return AdoptionRows::sections($this->getRecord(), $this->review, $this->reviewReason, $this->environment(), self::UNAVAILABLE);
    }
}
