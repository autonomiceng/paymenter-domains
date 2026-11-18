<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\{Actions, EmbeddedSchema, Form};
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Sheet, WatchActions, WatchFacts};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Row, Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Concerns\ForRecord;
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact, Plain};
use Paymenter\Extensions\Servers\Domains\Services\{SiteDnsDefault, StaffWatchWrites as Writes};

class EditConfiguration extends Page
{
    use ForRecord { mount as private mountRecord; }
    protected static string $resource = RoutingProfileResource::class;
    public array $data = [];
    #[Locked] public int $revision;
    #[Locked] public int $defaultId;

    public function mount(int|string $record): void
    {
        $this->mountRecord($record);
        $profile = $this->getRecord();
        $this->revision = $profile->revision;
        $this->defaultId = SiteDnsDefault::uses($profile->id) ? $profile->id : 0;
        $this->form->fill();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(WatchActions::canEditTemplate($this->getRecord()), 403);
        abort_unless($this->getRecord()->readProfile(), 409, WatchFacts::TEMPLATE_UNAVAILABLE);
    }

    public function getTitle(): string { return 'Edit '.$this->getRecord()->name; }
    private function detailUrl(): string { return RoutingProfileResource::getUrl('view', ['record' => $this->getRecord()]); }
    public function getBreadcrumbs(): array
    {
        return [RoutingProfileResource::getUrl() => 'DNS templates', $this->detailUrl() => $this->getRecord()->name, 'Edit'];
    }
    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components(WatchActions::templateFields($this->getRecord()));
    }
    public function content(Schema $schema): Schema
    {
        $profile = $this->getRecord();
        $count = WatchFacts::usedBy($profile->id);
        return $schema->components([Sheet::split([
            Form::make([EmbeddedSchema::make('form')])->livewireSubmitHandler('save')->footer([
                Actions::make([Action::make('cancel')->label('Cancel')->color('gray')->url($this->detailUrl()),
                    Action::make('submit')->label('Save')->submit('save')])->alignEnd(),
            ]),
        ], [Sheet::section('What saving changes', [
            Row::make('New registrations', Value::plain($this->defaultId ? Plain::Yes : Plain::No,
                $this->defaultId ? 'New registrations using the default get the saved nameservers. Transfers keep their own.' : 'New registrations use another DNS setting.')),
            Row::make('Monitored domains', Value::fact(Fact::count($count), $profile->enabled
                ? 'Future checks use the saved records when monitoring runs.' : 'Checks wait while this template is off.')),
            Row::make('Live DNS', Value::plain(Plain::No, 'Existing domains keep their live nameservers and records.')),
        ])])]);
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
