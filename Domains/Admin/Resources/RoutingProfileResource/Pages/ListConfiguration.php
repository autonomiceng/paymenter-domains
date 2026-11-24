<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages;
use Filament\Actions\Action;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DefaultNameservers,Staff,StaffTabs,DnsTemplateActions};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head,Row,Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{Column,RecordListPage,Section};
use Paymenter\Extensions\Servers\Domains\Models\DnsTemplate;
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact,Plain,Reader,State};
use Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault;
/** Domains > DNS templates, a List page: the default nameservers for new domains on top, the templates they come from below. */
class ListConfiguration extends RecordListPage
{
    protected const PAGE_CLASSES = [...parent::PAGE_CLASSES, 'domains-template-list'];
    protected static string $resource=RoutingProfileResource::class;
    protected ?array $marks = null;
    public function getSubNavigation(): array{return StaffTabs::domains('DNS templates');}
    public function getBreadcrumbs(): array{return [];}
    protected function head(): Head{return Head::make('Domains',Reader::Staff);}
    protected function actions(): array{return [DnsTemplateActions::addTemplate()];}
    public function refreshTemplate(): void
    {
        $this->marks = null;
        unset($this->cachedSchemas['content']);
    }
    public function siteDefaultAction(): Action{return DefaultNameservers::choose(fn()=>$this->refreshTemplate());}
    protected function summary(): array
    {
        $default = SiteDnsDefault::profile();
        $canEdit = Staff::canEditDefaultDns();
        $problem = SiteDnsDefault::refusal($canEdit) ?? ($default ? null : ($canEdit ? SiteDnsDefault::MISSING : SiteDnsDefault::ASK));
        $value = $default ? Value::fact(Fact::names([$default->name]), self::nameservers($default->nameservers).'. Transfers keep their own.')
            : Value::state(State::NotSet, Reader::Staff);
        return [Section::make('New registrations', [
            ...($problem ? [Row::notice('default-nameservers', Value::state(State::NotSet, Reader::Staff, $problem))] : []),
            Row::make('New domains use', $value)->actions($this->siteDefaultAction()),
        ])];
    }

    private static function nameservers(array $names): string
    {
        return $names[0].(count($names) > 1 ? ' and '.(count($names) - 1).' more' : '');
    }

    private function mark(int $id): ?string{$this->marks??=SiteDnsDefault::marks();return $this->marks[$id]??null;}
    protected function columns(): array
    {
        // Saved references that cannot be used are marked too, matching the guard that keeps them.
        return [
            Column::value('name','Template',fn(DnsTemplate $p): Value=>Value::fact(Fact::names([$p->name]),$this->mark((int)$p->id)))->fill(),
            // A retained template the editor would refuse reads Couldn't read, the same check as its detail page.
            Column::value('nameservers','Nameservers',fn(DnsTemplate $p): Value=>($r=$p->readProfile())?Value::fact(Fact::names([self::nameservers($r->nameservers)])):Value::state(State::CouldntRead,Reader::Staff)),
            Column::value('enabled','Enabled',fn(DnsTemplate $p): Value=>Value::plain($p->enabled?Plain::On:Plain::Off)),
        ];
    }
    public function getTableRecords(): \Illuminate\Support\Collection|\Illuminate\Contracts\Pagination\Paginator|\Illuminate\Contracts\Pagination\CursorPaginator
    {
        $records = parent::getTableRecords();
        return $records instanceof \Illuminate\Pagination\LengthAwarePaginator && $records->onFirstPage() && !$records->hasMorePages()
            ? $records->getCollection() : $records;
    }
    protected function emptyLine(): array{return ['No DNS templates','Add one with Add DNS template.','No DNS templates match'];}
}
