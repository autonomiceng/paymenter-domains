<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Services\PriceList as Prices;
class PriceList extends Page
{
    protected static string $resource=TldResource::class;
    protected string $view='domains::admin.price-list';
    public int $years=1;
    public string $filter='all';
    public string $serverId='';
    public function mount(): void {Prices::authorize();}
    public function getTitle(): string {return 'Price list';}
    public function getBreadcrumbs(): array {return [TldResource::getUrl()=>'TLDs & pricing','Price list'];}
    protected function getHeaderActions(): array
    {return [Action::make('csv')->label('Download CSV')->color('gray')->action(fn()=>$this->downloadCsv())];}
    protected function getViewData(): array
    {
        $service=new Prices;$matrix=$service->read($this->years,$this->filter,$this->accountId());
        return ['matrix'=>$matrix,'headings'=>$service->headings(),'prices'=>$service];
    }
    private function accountId(): ?int
    {
        if($this->serverId===''){return null;}
        if(!ctype_digit($this->serverId)||strlen($this->serverId)>10){throw new \InvalidArgumentException('Choose a registrar account.');}
        return (int)$this->serverId;
    }
    public function downloadCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $csv=(new Prices)->csv($this->years,$this->filter,$this->accountId());
        return response()->streamDownload(static function()use($csv):void{echo $csv;},'domain-prices-'.$this->years.'-'.($this->years===1?'year':'years').'.csv',['Content-Type'=>'text/csv; charset=UTF-8']);
    }
}
