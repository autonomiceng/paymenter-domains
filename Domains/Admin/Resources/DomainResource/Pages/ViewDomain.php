<?php
// Adapted from Enom 444f31c ViewDomain, split read-only support; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource,OperationResource};
use Paymenter\Extensions\Servers\Domains\Services\ObservationWork;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,DomainQueries,Staff};
class ViewDomain extends ViewRecord
{
    protected static string $resource = DomainResource::class;
    public function getTitle(): string { Staff::requireView(); return (string)$this->getRecord()->domain; }
    public function getHeading(): \Illuminate\Support\HtmlString { return new \Illuminate\Support\HtmlString('<span style="overflow-wrap:anywhere">'.e($this->getTitle()).'</span>'); }
    public function getBreadcrumbs(): array { return [$this->getResourceUrl()=>'Domains','Domain #'.$this->getRecord()->id]; }
    protected function getHeaderActions(): array
    { return [\Filament\Actions\Action::make('adoptionPreview')->label('Preview adoption')->visible(fn () => Staff::can(Staff::ADOPT_PREVIEW) && $this->getRecord()->adoptionPending())
        ->url(fn () => DomainResource::getUrl('adoption', ['record' => $this->getRecord()->id])),
        ...\Paymenter\Extensions\Servers\Domains\Admin\Support\AcquisitionActions::make($this->getRecord()),
        ...\Paymenter\Extensions\Servers\Domains\Admin\Support\HoldingActions::make($this->getRecord())]; }
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
            $stopped=ObservationWork::stopped($d);
            $description=$stopped?'No scheduled reads run for this domain until staff resume them.':'Domain facts still refresh. Record checks stay paused until staff resume them.';
            $review=[TextEntry::make('record_hold_reason')->label('Reason')->state($hold),
                TextEntry::make('record_next_step')->label('Next step')->state($request===null?
                    'There is no current registrar request for this domain. Ask an administrator to review scheduled reads.':
                    'Review the reason, then open the registrar request and choose Resume scheduled reads.')];
            if ($request!==null) {
                $review[]=TextEntry::make('record_permission')->label('Permission')->state('Requires the Resume scheduled reads permission.');
                $link='<a href="'.e(OperationResource::getUrl('view',['record'=>$request])).'" class="fi-link" style="text-decoration:underline;text-underline-offset:3px;--tw-outline-style:solid;display:inline-block;padding:2px;outline-offset:-2px">Open registrar request</a>';
                $review[]=TextEntry::make('record_recovery')->label('Recovery')->state(new \Illuminate\Support\HtmlString($link));
            }
            $sections[]=Section::make($stopped?'Scheduled reads stopped':'Record checks paused')->icon('heroicon-o-exclamation-triangle')->iconColor('warning')
                ->description($description)->columnSpanFull()->schema($review);
        }
        $adoption = DomainFacts::adoptionReview($this->getRecord());
        if ($adoption) { $sections[] = Section::make('Adoption needs review')->icon('heroicon-o-exclamation-triangle')->iconColor('warning')
            ->description('Adoption itself made no charge. These retained notes do not grant renewal authority.')
            ->columnSpanFull()->schema([TextEntry::make('adoption_review')->label('Review notes')->state($adoption)->listWithLineBreaks()]); }
        return $schema->components([...$sections,Section::make('Domain')->description('Saved facts. Viewing this page makes no registrar requests.')
            ->columnSpanFull()->columns(2)->schema($entries)]);
    }
}
