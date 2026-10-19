<?php
// Adapted from Enom 444f31c ViewDomain, split read-only support; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource,OperationResource};
use Paymenter\Extensions\Servers\Domains\Services\{ObservationWork,StaffOperationReview};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,DomainQueries,Staff};
class ViewDomain extends ViewRecord
{
    protected static string $resource = DomainResource::class;
    public function getTitle(): string { Staff::requireView(); return (string)$this->getRecord()->domain; }
    public function getHeading(): \Illuminate\Support\HtmlString { return new \Illuminate\Support\HtmlString('<span style="overflow-wrap:anywhere">'.e($this->getTitle()).'</span>'); }
    public function getBreadcrumbs(): array { return [$this->getResourceUrl()=>'Domains','Domain #'.$this->getRecord()->id]; }
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
        $sections=[];$hold=DomainFacts::recordHold($this->getRecord());
        if ($hold!==null) {
            $d=$this->getRecord();$request=DomainQueries::operations($d)->orderByDesc('id')->value('id');
            $description=ObservationWork::stopped($d)?'Scheduled reads are paused for this domain.':'Record checks are paused. Domain facts still refresh.';
            $description.=$request===null?' No current registrar request is available. Ask an administrator to review scheduled reads.':
                ' After reviewing the reason, open the registrar request and choose '.StaffOperationReview::ACTIONS['resume_observation'].'. Staff read-resume permission is required.';
            $review=[TextEntry::make('record_hold_reason')->label('Reason')->state($hold)];
            if ($request!==null) { $review[]=TextEntry::make('record_recovery')->label('Recovery')->state('Open registrar request')->url(OperationResource::getUrl('view',['record'=>$request])); }
            $sections[]=Section::make('Record checks need review')->icon('heroicon-o-exclamation-triangle')->iconColor('warning')
                ->description($description)->columnSpanFull()->schema($review);
        }
        return $schema->components([...$sections,Section::make('Domain')->description('Saved facts. Viewing this page makes no registrar requests.')
            ->columnSpanFull()->columns(2)->schema($entries)]);
    }
}
