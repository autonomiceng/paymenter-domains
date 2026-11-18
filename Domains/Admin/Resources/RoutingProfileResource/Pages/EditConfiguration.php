<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{WatchActions, WatchFacts};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head, Partial, Row, Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{RecordDetailPage, Section};
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact, Plain, Reader};
use Paymenter\Extensions\Servers\Domains\Services\{SiteDnsDefault, StaffWatchWrites as Writes};

class EditConfiguration extends RecordDetailPage
{
    protected const PAGE_CLASSES = [...parent::PAGE_CLASSES, 'domains-template-detail'];
    protected static string $resource = RoutingProfileResource::class;
    public array $data = [];
    #[Locked] public int $revision;
    #[Locked] public int $defaultId;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $profile = $this->getRecord();
        $this->revision = $profile->revision;
        $this->defaultId = SiteDnsDefault::uses($profile->id) ? $profile->id : 0;
        $this->form->fill();
    }

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();
        abort_unless(WatchActions::canEditTemplate($this->getRecord()), 403);
        abort_unless($this->getRecord()->readProfile(), 409, WatchFacts::TEMPLATE_UNAVAILABLE);
    }

    protected function head(): Head { return Head::make('Edit '.$this->getRecord()->name, Reader::Staff); }
    private function detailUrl(): string { return RoutingProfileResource::getUrl('view', ['record' => $this->getRecord()]); }
    public function getBreadcrumbs(): array
    {
        return [RoutingProfileResource::getUrl() => 'DNS templates', $this->detailUrl() => $this->getRecord()->name, 'Edit'];
    }
    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components(WatchActions::templateFields($this->getRecord()));
    }
    protected function sections(): array
    {
        return [Section::make('Template', [Partial::make('domains::admin.template-editor', [
            'editor' => $this, 'cancelUrl' => $this->detailUrl(),
        ])])];
    }
    protected function rail(): array
    {
        $profile = $this->getRecord();
        $count = WatchFacts::usedBy($profile->id);
        return [Section::make('What saving changes', [
            Row::make('New registrations', Value::plain($this->defaultId ? Plain::Yes : Plain::No,
                $this->defaultId ? 'New registrations using the default get the saved nameservers. Transfers keep their own.' : 'New registrations use another DNS setting.')),
            Row::make('Monitored domains', Value::fact(Fact::count($count), $profile->enabled
                ? 'Future checks use the saved records when monitoring runs.' : 'Checks wait while this template is off.')),
            Row::make('Live DNS', Value::plain(Plain::No, 'Existing domains keep their live nameservers and records.')),
        ])];
    }
    public function save(): void
    {
        $this->authorizeAccess();
        $data = $this->form->getState();
        $id = (int) $this->getRecord()->id;
        try {
            (new Writes())->run(Auth::user(), 'profile', $id, 'save', Writes::ticket((int) Auth::id(), 'profile', $id,
                $this->revision, 'save', $this->defaultId), 'DNS template saved', $data);
        } catch (\DomainException|\InvalidArgumentException $e) {
            Notification::make()->warning()->title($e->getMessage())->send();
            return;
        }
        Notification::make()->success()->title('DNS template saved')->send();
        $this->redirect($this->detailUrl());
    }
}
