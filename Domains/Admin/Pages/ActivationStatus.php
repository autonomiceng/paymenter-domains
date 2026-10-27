<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use Filament\Pages\Page;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
/** Old address of Activation status. Go-live facts now show in each account's Setup checklist on Registrars. */
class ActivationStatus extends Page
{
    protected static ?string $slug='domains-activation-status';
    protected static bool $shouldRegisterNavigation=false;
    public static function canAccess(): bool {return Staff::can();}
    public function mount(): void {Staff::requireView();$this->redirect(RegistrarSetup::getUrl());}
}
