<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages;
use Filament\Resources\Pages\ListRecords;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\WatchFindings;
use Paymenter\Extensions\Servers\Domains\Models\WatchName;
class ListWatches extends ListRecords
{
    protected static string $resource=WatchResource::class;
    /** Request-scoped projection of the visible page; protected, so never part of the Livewire snapshot. */
    protected ?array $visible=null;
    protected ?array $attention=null;
    public function getTitle(): string{return 'Domain watch';}
    public function getSubheading(): string{return 'Saved public checks for hosting domains. Findings match what the customer sees; recorded evidence may be partial or out of date.';}
    /** @return list<int> */
    public function attentionIds(): array{return $this->attention??=WatchFindings::attentionIds();}
    public function watchRow(WatchName $record): array
    {
        $records=$this->getTableRecords();
        if(($this->visible['records']??null)!==$records){$rows=collect(method_exists($records,'items')?$records->items():$records);
            $this->visible=['records'=>$records,'findings'=>WatchFindings::batch($rows),'customers'=>WatchFindings::customers($rows->pluck('user_id')->unique()->all())];}
        return ['findings'=>$this->visible['findings'][$record->id]??WatchFindings::batch([$record])[$record->id],'customer'=>$this->visible['customers'][$record->user_id]??WatchFindings::customers([$record->user_id])[$record->user_id]];
    }
}
