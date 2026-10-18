<?php
// Enom 444f31c TLD pricing layout; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\{Schema,Components\Section};
use Filament\Infolists\Components\TextEntry;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts,SettingsActions};
class ViewTld extends ViewRecord
{
    protected static string $resource=TldResource::class;
    public string $reviewStamp='';
    public function mount(int|string $record): void
    {parent::mount($record);$this->reviewStamp=\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::stamp((int)$this->getRecord()->server_id,(int)$this->getRecord()->id);}
    protected function getHeaderActions(): array
    {return [\Filament\Actions\ActionGroup::make(SettingsActions::make((int)$this->getRecord()->server_id,(int)$this->getRecord()->id,$this->reviewStamp))->label('Pricing & seller')->button()];}
    protected function fillForm(): void {$this->data=[];}
    public function getTitle(): string {Staff::requireView();return 'Pricing for .'.$this->getRecord()->tld;}
    public function infolist(Schema $schema): Schema
    {
        Staff::requireView();$t=$this->getRecord();
        $facts=['Registrar / account'=>TldFacts::registrar($t->registrar_key).' · account #'.$t->server_id,
            'Pricing'=>$t->use_default_rule?'Default rule':'TLD override',
            'Rule settings'=>Staff::can(Staff::POLICY)?TldFacts::rule($t):'Ask staff with pricing permissions','Seller for new sales'=>TldFacts::seller($t),
            'Catalogue sales status'=>TldFacts::salesState($t),
            'Renewal holders'=>TldFacts::holders($t).' · existing domains keep their holding registrar',
            'Cost refresh'=>TldFacts::refreshState((int)$t->server_id)];
        $entries=[];foreach($facts as $label=>$value){$entries[]=TextEntry::make('fact_'.count($entries))->label($label)->state($value);}
        return $schema->components([
            Section::make('TLD')->columns(2)->schema($entries)->columnSpanFull(),
            Section::make('Prices')->description('Registration and transfer below cost are held with a staff alert. Renewals below cost are billed with a staff alert. Issued invoices keep their prices.')
                ->schema([TextEntry::make('saved_prices')->hiddenLabel()->state(fn()=>TldFacts::table($t))->html()])->columnSpanFull(),
            Section::make('Revision history')->description('Changes for this TLD and shared account settings. Price publications include before and after revisions.')
                ->schema([TextEntry::make('revisions')->hiddenLabel()->state(fn()=>TldFacts::history((int)$t->server_id,(int)$t->id)?:['No staff changes recorded'])->listWithLineBreaks()->bulleted()])->columnSpanFull(),
        ]);
    }
}
