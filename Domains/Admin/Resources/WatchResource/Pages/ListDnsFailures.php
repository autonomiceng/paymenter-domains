<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages;
use Filament\Actions\Action;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource;
use Paymenter\Extensions\Servers\Domains\Services\{ManagedDnsSetup,ManagedPublicReconciliation};
class ListDnsFailures extends ListManagedDns
{
    public function getTitle(): string{return 'Affected DNS checks';}
    public function getSubheading(): ?string{return 'Managed domains with failed DNS checks. External domain checks are listed under Domain monitoring.';}
    protected function getHeaderActions(): array
    {return [Action::make('watches')->label('External domain checks')->url(WatchResource::getUrl('index',['filters'=>['status'=>['value'=>'problem']]]))];}
    protected function domains(): \Illuminate\Database\Eloquent\Builder
    {
        return ManagedDnsSetup::eligible(time())->whereIn('domains_domains.id',ManagedPublicReconciliation::eligible()
            ->where('attempts','>',0)->select('domain_id'));
    }
}
