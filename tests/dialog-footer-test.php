<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. One rule for every dialog in the admin: Cancel, then the action that saves.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use Filament\Actions\{Action,CreateAction};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Sheet;

(new Paymenter\Extensions\Servers\Domains\Domains)->boot();
$page=new class extends Livewire\Component { public function render(): string { return '<div></div>'; } };
$footer=static fn(Action $action)=>array_values(array_map(fn(Action $button)=>$button->getLabel(),$action->livewire($page)->getModalFooterActions()));
$cancelled=static fn(string $label)=>Action::make('form')->schema([])->modalSubmitActionLabel($label);
same(['Cancel','Save'],$footer($cancelled('Save')),'A form dialog reads Cancel, then Save');
same(['Cancel','Confirm'],$footer(Action::make('sure')->requiresConfirmation()),'A confirmation reads the same');
same(['Cancel','Email auth code'],$footer(Sheet::button($cancelled('Email auth code'))),'A row action reads the same');
same(['Cancel','Also','Save'],$footer($cancelled('Save')->extraModalFooterActions([Action::make('also')->label('Also')])),'Extra buttons sit between the two');
same(['Cancel'],$footer(Action::make('look')->modalSubmitAction(false)),'A read-only dialog keeps Cancel alone');
same(['Save'],$footer($cancelled('Save')->modalCancelAction(false)),'A dialog that drops Cancel keeps the rest');
same(['Cancel','Create'],array_slice($footer(CreateAction::make()->modalSubmitActionLabel('Create')->createAnother(false)),0,2),'Stock actions follow the rule');
echo "Dialog footers passed.\n";$nativeSuccessful=true;
