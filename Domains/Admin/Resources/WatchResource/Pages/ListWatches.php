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
    protected array $status=[];
    public function getTitle(): string{return 'Domain monitoring';}
    public function getSubheading(): ?string{return null;}
    /** @return list<int> */
    public function statusIds(string $status): array{return $this->status[$status]??=WatchFindings::statusIds($status);}
    public function watchRow(WatchName $record): array
    {
        $records=$this->getTableRecords();
        if(($this->visible['records']??null)!==$records){$rows=collect(method_exists($records,'items')?$records->items():$records);
            $this->visible=['records'=>$records,'summaries'=>WatchFindings::summaries($rows),'customers'=>WatchFindings::customers($rows->pluck('user_id')->unique()->all())];}
        $row=$this->visible['summaries'][$record->id]??WatchFindings::summaries([$record])[$record->id];
        return $row+['customer'=>$this->visible['customers'][$record->user_id]??WatchFindings::customers([$record->user_id])[$record->user_id],'status'=>WatchFindings::status($record,$row['keys'],$row['summary']?->expiry)];
    }
}
