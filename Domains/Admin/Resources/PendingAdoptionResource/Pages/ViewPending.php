<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\PendingAdoptionResource\Pages;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource,PendingAdoptionResource};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Head;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\RecordDetailPage;
use Paymenter\Extensions\Servers\Domains\Presentation\Reader;
/** A pending registration setup has no page of its own: it opens the domain's page. The Detail type holds the
 * title and access check, and the page never draws a sheet. */
class ViewPending extends RecordDetailPage
{
    protected static string $resource=PendingAdoptionResource::class;
    public function mount(int|string $record): void{parent::mount($record);$this->redirect(DomainResource::getUrl('view',['record'=>$this->getRecord()]));}
    protected function head(): Head{return Head::make('Registration setup pending',Reader::Staff);}
    protected function sections(): array{return [];}
}
