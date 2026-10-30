<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\ProductMappingResource\Pages;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\{Schema,Components\Callout,Components\Grid,Components\Section};
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Enums\FontFamily;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\PublicSuffixList;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{ProductMappingResource,RoutingProfileResource};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{MissingEntry,WatchActions,WatchFacts};
use Paymenter\Extensions\Servers\Domains\Models\WatchProfile;
class ViewConfiguration extends ViewRecord
{
    protected static string $resource=ProductMappingResource::class;
    /** The revision this page shows; writes from a stale page are refused. */
    #[Locked] public int $revision=0;
    public function mount(int|string $record): void{parent::mount($record);$this->revision=(int)$this->getRecord()->revision;}
    protected function fillForm(): void{$this->data=[];}
    public function getTitle(): string{$r=$this->getRecord();return (string)($r->product?->name??'Product #'.$r->product_id);}
    public function getBreadcrumbs(): array{return [ProductMappingResource::getUrl()=>'Hosting products',$this->getTitle()];}
    protected function getHeaderActions(): array{return [WatchActions::enable('mapping',$this->getRecord(),$this->revision),WatchActions::configuration('mapping',$this->getRecord(),$this->revision)];}
    public function infolist(Schema $schema): Schema
    {
        $r=$this->getRecord();$profile=$r->expected_ns_profile_id?WatchProfile::find($r->expected_ns_profile_id):null;$preview=WatchFacts::preview($r);
        $entry=fn(string $label,mixed $value)=>TextEntry::make('fact_'.md5($label))->label($label)->state($value)->inlineLabel()->extraAttributes(['style'=>'overflow-wrap:anywhere']);
        $settings=[$entry('Product',$this->getTitle()),$entry('Domain field',$r->property_key)->fontFamily(FontFamily::Mono),$entry('Monitoring',$r->enabled?'On':'Off'),
            MissingEntry::apply($entry('DNS template',$profile?->name)->url($profile?RoutingProfileResource::getUrl('view',['record'=>$profile->id]):null),cause:$profile?null:'Choose one in Edit')];
        $matched=match(true){$preview===null=>[Callout::make('Download the public suffix list to match domains.')->status('warning')->footer([Action::make('open_psl')->label('Public suffix list')->link()->url(fn()=>PublicSuffixList::getUrl())->visible(fn()=>\Illuminate\Support\Facades\Route::has(PublicSuffixList::getRouteName()))])],
            $preview===[]=>[TextEntry::make('matched')->hiddenLabel()->state('No services use this product')->color('gray')],
            default=>array_map(fn(array $row,int $i)=>TextEntry::make('matched_'.$i)->label($row['service'])->inlineLabel()->state($row['result'])->belowContent($row['domain'])->url($row['url'])->extraAttributes(['style'=>'overflow-wrap:anywhere']),$preview,array_keys($preview))};
        return $schema->components([Grid::make(['default'=>1,'lg'=>2])->columnSpanFull()->schema([Section::make('Settings')->columnSpan(1)->schema($settings),Section::make('Matched services')->columnSpan(1)->schema($matched)])]);
    }
}
