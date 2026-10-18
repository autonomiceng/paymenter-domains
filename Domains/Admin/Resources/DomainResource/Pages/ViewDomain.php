<?php
// Adapted from Enom 444f31c ViewDomain, split read-only support; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts, Staff};
class ViewDomain extends ViewRecord
{
    protected static string $resource = DomainResource::class;
    public function getTitle(): string { Staff::requireView(); return (string)$this->getRecord()->domain; }
    protected function getHeaderActions(): array { return []; }
    // ViewRecord otherwise fills public form data with the entire model's attributes.
    protected function fillForm(): void { $this->data=[]; }
    public function infolist(Schema $schema): Schema
    {
        $entries=[];
        foreach (DomainFacts::for($this->getRecord()) as $label=>$value) {
            $entries[]=TextEntry::make('fact_'.count($entries))->label($label)->state($value)->listWithLineBreaks()
                ->extraAttributes(['style'=>'overflow-wrap:anywhere']);
        }
        return $schema->components([Section::make('Domain')->description('Saved facts. Viewing this page makes no registrar requests.')
            ->columnSpanFull()->columns(2)->schema($entries)]);
    }
}
