<?php
// Adapted from Enom 444f31c ViewDomain, split read-only support; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages;
use Filament\Actions\{Action, ActionGroup};
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\{Callout, Grid, Section};
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{AcquisitionActions, DomainFacts, HoldingActions, MissingEntry, OperationActions, RegistrarFacts, Staff};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
class ViewDomain extends ViewRecord
{
    protected static string $resource = DomainResource::class;
    /** Wider label column for Registration and Billing so labels with a help icon stay on one line at desktop width. */
    private const FACTS_STYLE='<style>@media (min-width:40rem){.domains-facts .fi-in-entry.fi-in-entry-has-inline-label{grid-template-columns:minmax(0,40%) minmax(0,1fr) minmax(0,1fr)}}'
        .'.domains-warn{color:var(--warning-600)}.dark .domains-warn{color:var(--warning-400)}.domains-err{color:var(--danger-600)}.dark .domains-err{color:var(--danger-400)}</style>';
    public function getTitle(): string { Staff::requireView(); return (string)$this->getRecord()->domain; }
    public function getHeading(): HtmlString
    {
        $status=DomainFacts::status($this->getRecord());
        return new HtmlString('<span style="display:inline-flex;flex-wrap:wrap;align-items:center;gap:.5rem"><span class="font-mono" style="overflow-wrap:anywhere">'.e($this->getTitle()).'</span>'
            .(DemoData::is($this->getRecord())?DemoData::tag(inline:true)->toHtml():'')
            .view('filament::components.badge',['color'=>$status['tone'],'size'=>'lg','slot'=>new HtmlString(e($status['label']))])->render().'</span>'.self::FACTS_STYLE);
    }
    public function getSubheading(): ?string { return DomainFacts::status($this->getRecord())['reason']; }
    public function getBreadcrumbs(): array { return [$this->getResourceUrl()=>'Domains',$this->getTitle()]; }
    protected function getHeaderActions(): array
    {
        $d=$this->getRecord(); $renewal=HoldingActions::make($d);
        $more=array_values(array_filter($renewal,fn(Action $a)=>$a->getName()==='reprice'));
        // Import domain for a pending import sits in the page notice.
        return [...AcquisitionActions::make($d),
            ...array_filter($renewal,fn(Action $a)=>$a->getName()!=='reprice'),
            ...($more?[ActionGroup::make($more)->label('More')->button()->color('gray')]:[])];
    }
    /** Resume sync sits in the page notice and is mounted as a page action. */
    public function resume_observationAction(): Action
    { return OperationActions::scheduledReads($this->getRecord())[0]??Action::make('resume_observation')->hidden(); }
    // ViewRecord otherwise fills public form data with the entire model's attributes.
    protected function fillForm(): void { $this->data=[]; }
    public function infolist(Schema $schema): Schema
    {
        $d=$this->getRecord(); $facts=DomainFacts::for($d); $notice=DomainFacts::notice($d); $components=[];
        if ($notice) {
            $buttons=[];
            $resume=($notice['resume']??false)?$this->getAction('resume_observation',false):null;
            if ($resume?->isVisible()) { $buttons[]=$resume->label('Resume sync')->toHtml(); }
            foreach ([$notice['action']??null,$notice['link']??null] as $i=>$link) {
                if ($link&&$link['url']) { $buttons[]=view('filament::components.button.index',['tag'=>'a','href'=>$link['url'],'color'=>$i===0?'primary':'gray','size'=>'sm','slot'=>new HtmlString(e($link['label']))])->render(); }
            }
            $components[]=Callout::make($notice['title'])->description($notice['body']??null)->status($notice['tone'])->columnSpanFull()
                ->footer($buttons?new HtmlString('<div style="display:flex;flex-wrap:wrap;gap:.5rem">'.implode('',array_filter($buttons)).'</div>'):null);
        }
        $section=fn(string $title,array $labels)=>Section::make($title)->schema(array_map(fn(string $label)=>self::entry($label,$facts[$label]),
            array_values(array_filter($labels,fn($label)=>isset($facts[$label])))))->columnSpan(1)->extraAttributes(['class'=>'domains-facts']);
        return $schema->components([...$components,Grid::make(['default'=>1,'lg'=>2])->columnSpanFull()
            ->schema([$section('Registration',DomainFacts::REGISTRATION),$section('Billing',DomainFacts::BILLING)])]);
    }
    private static function entry(string $label, array $fact): TextEntry
    {
        $entry=TextEntry::make('fact_'.md5($label))->label($label)->state($fact['value'])->inlineLabel()
            ->extraAttributes(['style'=>'overflow-wrap:anywhere'])->url($fact['url'])
            // A cause under a value in warning or danger takes the same tone, so an open alert stands out next to its row.
            ->belowContent($fact['value']!==null&&$fact['sub']!==null&&in_array($fact['tone'],['danger','warning'],true)?RegistrarFacts::tone($fact['sub'],$fact['tone']):$fact['sub']);
        if (is_array($fact['value'])) { $entry->listWithLineBreaks(); }
        if ($fact['help']) { $entry->hintIcon('heroicon-m-question-mark-circle',$fact['help']); }
        if ($fact['demo']) { $entry->suffix(DemoData::tag(inline:true)); }
        return MissingEntry::apply($entry,cause:$fact['value']===null?$fact['sub']:null,color:$fact['tone']);
    }
}
