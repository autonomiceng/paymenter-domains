<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\{Schema,Components\Section};
use Filament\Infolists\Components\TextEntry;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{WatchActions,WatchFacts};
class ViewWatch extends ViewRecord
{
    protected static string $resource=WatchResource::class;
    protected function fillForm(): void{$this->data=[];}
    public function getTitle(): string{return $this->getRecord()->canonical_name;}
    public function getHeading(): HtmlString{return new HtmlString('<span style="overflow-wrap:anywhere">'.str_replace(['.','-'],['.<wbr>','-<wbr>'],e($this->getTitle())).'</span>');}
    public function getHeader(): ?\Illuminate\Contracts\View\View{return view('domains::staff.watch-header');}
    protected function getHeaderActions(): array{return WatchActions::watch($this->getRecord());}
    public function infolist(Schema $schema): Schema
    {
        $entries=[];foreach(WatchFacts::for($this->getRecord()) as $label=>$value){$entries[]=TextEntry::make('fact_'.count($entries))->label($label)->state($value)->listWithLineBreaks()->extraAttributes(['style'=>'overflow-wrap:anywhere']);}
        return $schema->components([Section::make('Saved domain checks')->description('No public requests run when viewing this page. Request check uses the existing schedule, feature gates and backoff.')->columnSpanFull()->columns(2)->schema($entries)]);
    }
}
