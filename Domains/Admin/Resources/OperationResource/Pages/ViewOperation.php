<?php
// Enom 444f31c request review layout; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages;
use Filament\Resources\Pages\ViewRecord;
use Filament\Actions\ActionGroup;
use Filament\Schemas\{Schema,Components\Section};
use Filament\Infolists\Components\TextEntry;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Labels,OperationActions,PaidUncertainty,RequestFacts,Staff};
class ViewOperation extends ViewRecord
{
    protected static string $resource=OperationResource::class;
    public function getTitle(): string { Staff::requireView(); return 'Request #'.$this->getRecord()->id; }
    protected function fillForm(): void { $this->data=[]; }
    #[\Livewire\Attributes\Locked]
    public string $reviewStamp='';
    public function mount(int|string $record): void
    { parent::mount($record); $op=$this->getRecord(); $this->reviewStamp=\Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::stamp($op,$op->domain); }
    protected function getHeaderActions(): array { Staff::requireView(); return [ActionGroup::make(OperationActions::make($this->getRecord(),$this->reviewStamp))->label('Review actions')->button()]; }
    public function infolist(Schema $schema): Schema
    {
        $op=$this->getRecord(); Staff::requireView();
        $facts=['Domain'=>$op->domain->domain,'Request type'=>Labels::action($op->action),'Status'=>Labels::state($op->state),
            'Created'=>$op->created_at?->format('Y-m-d H:i').' UTC','Review'=>RequestFacts::review($op)];
        if ($op->safe_code!==null) { $facts['Reason']=RequestFacts::for($op)['reason']; }
        $holds=PaidUncertainty::for($op->domain); if($holds){$facts['Account spending holds']=$holds;}
        $reviews=PaidUncertainty::reviews($op->domain);if($reviews){$facts['Paid outcome review']=$reviews;}
        $activity=\Paymenter\Extensions\Servers\Domains\Services\RenewalReplacements::activity($op->domain);if($activity){$facts['Refunded debt review']=$activity;}
        $entries=[]; foreach($facts as $label=>$value){$entries[]=TextEntry::make('fact_'.count($entries))->label($label)->state($value)
            ->listWithLineBreaks()->extraAttributes(['style'=>'overflow-wrap:anywhere']);}
        return $schema->components([Section::make('Registrar request')->description('Saved evidence. Viewing makes no registrar requests.')
            ->columnSpanFull()->columns(2)->schema($entries)]);
    }
}
