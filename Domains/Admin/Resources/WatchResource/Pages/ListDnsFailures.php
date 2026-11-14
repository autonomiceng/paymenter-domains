<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head,Row,Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Section;
use Paymenter\Extensions\Servers\Domains\Presentation\{Reader,State};
use Paymenter\Extensions\Servers\Domains\Services\{ManagedDnsSetup,ManagedPublicReconciliation};
/** Domains > Affected DNS checks, beside DNS monitoring setup: managed domains whose DNS checks keep failing. */
class ListDnsFailures extends ListManagedDns
{
    public function getSubNavigation(): array{return [];}
    public function getBreadcrumbs(): array{return [WatchResource::getUrl('index')=>'Domains','Affected DNS checks'];}
    protected function head(): Head{return Head::make('Affected DNS checks',Reader::Staff);}
    protected function actions(): array
    {return [Action::make('watches')->label('External domain checks')->color('gray')->url(WatchResource::getUrl('index',['filters'=>['status'=>['value'=>'problem']]]))];}
    protected function domains(): Builder
    {
        return ManagedDnsSetup::eligible(time())->whereIn('domains_domains.id',ManagedPublicReconciliation::eligible()
            ->where('attempts','>',0)->select('domain_id'));
    }
    protected function comparison(): State{return State::Checking;}
    protected function canAssign(): bool{return false;}
    protected function notices(): array
    {
        if(!$this->domains()->exists()){return [];}
        return [Section::make('DNS checks',[Row::notice('dns-failures',Value::state(State::Failed,Reader::Staff,
            'Managed domains with failed DNS checks. External domain checks are listed under Monitored domains.'))])];
    }
    protected function emptyLine(): array{return ['No affected DNS checks','No eligible managed domains have failed DNS checks.','No domains match'];}
}
