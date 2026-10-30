<?php
// Enom 444f31c ListTlds layout; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions\Action;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,Staff};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Filament\Schemas\Components\{Callout,EmbeddedTable,RenderHook};
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
class ListTlds extends ListRecords
{
    protected static string $resource=TldResource::class;
    public function getTitle(): string{return 'TLDs & pricing';}
    public function getBreadcrumbs(): array{return [];}
    protected function getHeaderActions(): array
    {
        $servers=[];
        foreach(Account::query()->whereIn('server_id',\App\Models\Server::where('extension','Domains')->where('type','server')->select('id'))->orderBy('server_id')->get() as $a){
            if(SettingsActions::syncOffered($a)){$servers[]=(int)$a->server_id;}
        }
        return [...array_map(fn(int $s)=>SettingsActions::syncAll($s,count($servers)>1),$servers),
            Action::make('price_list')->label('Price list')->url(TldResource::getUrl('price-list'))->visible(fn()=>Staff::can(Staff::COSTS))];
    }
    public function content(Schema $schema): Schema
    {
        $failures=TldResource::syncFailures();
        if(!$failures){return parent::content($schema);}
        $lines=array_map(fn(array $f)=>'<div>'.e($f['account'].': '.$f['cause'].'.').'</div>',$failures);
        $open=RegistrarSetup::canAccess()?view('filament::components.button.index',['tag'=>'a','href'=>RegistrarSetup::getUrl(),'color'=>'gray','size'=>'sm','slot'=>'Open Registrars'])->render():'';
        $count=count($failures);
        return $schema->components([Callout::make('Price sync failed for '.$count.' registrar '.($count===1?'account':'accounts').'.')->status('danger')
            ->description(new HtmlString(implode('',$lines).'<div>Saved prices are still used.</div>'))->footer($open!==''?new HtmlString($open):null)->columnSpanFull(),
            $this->getTabsContentComponent(),RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
            EmbeddedTable::make(),RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER)]);
    }
}
