<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\DnsTemplateActions;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head,Partial,Row,Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{RecordDetailPage,Section};
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact,Plain,Reader,State};
use Paymenter\Extensions\Servers\Domains\Services\{SiteDnsDefault,DnsTemplateWrites as Writes};

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
        abort_unless(DnsTemplateActions::canEditTemplate($this->getRecord()), 403);
        abort_unless($this->getRecord()->readProfile(), 409, Writes::UNREADABLE);
    }

    protected function head(): Head { return Head::make('Edit '.$this->getRecord()->name, Reader::Staff); }
    private function detailUrl(): string { return RoutingProfileResource::getUrl('view', ['record' => $this->getRecord()]); }
    public function getBreadcrumbs(): array
    {
        return [RoutingProfileResource::getUrl() => 'DNS templates', $this->detailUrl() => $this->getRecord()->name, 'Edit'];
    }
    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->live(debounce: 300)->components(DnsTemplateActions::templateFields($this->getRecord()));
    }
    protected function sections(): array
    {
        return [Section::make('Template', [Partial::make('domains::admin.template-editor', [
            'editor' => $this,
        ])])];
    }
    protected function rail(): array
    {
        $profile = $this->getRecord();
        $rows = [];
        try {
            $draft = Writes::routingProfile($this->data);
            $saved = $profile->profile();
            $changes = [];
            $name = trim((string) ($this->data['name'] ?? ''));
            if ($name !== $profile->name) { $changes[] = 'Name changes to '.$name.'.'; }
            $compare = static function (string $label, array $before, array $after) use (&$changes): void {
                foreach (array_diff($before, $after) as $value) { $changes[] = 'Remove '.$label.' '.$value; }
                foreach (array_diff($after, $before) as $value) { $changes[] = 'Add '.$label.' '.$value; }
            };
            $compare('nameserver', $saved->nameservers, $draft->nameservers);
            $nsChanged = $saved->nameservers !== $draft->nameservers;
            $rows[] = Row::make('Pending edits', $changes ? Value::fact(Fact::lines($changes)) : Value::plain(Plain::None));
            $registration = $nsChanged && $this->defaultId;
            $rows[] = Row::make('New registrations', Value::plain($registration ? Plain::Yes : Plain::No,
                $registration ? 'New registrations use the edited nameservers.' : 'Registration nameservers stay the same.'));
        } catch (\InvalidArgumentException|\DomainException) {
            $rows[] = Row::make('Pending edits', Value::state(State::NeedsReview, Reader::Staff, 'Enter valid DNS targets to preview the changes.'));
        }
        $rows[] = Row::make('Live DNS', Value::plain(Plain::No, 'Existing domains keep their live nameservers and records.'));
        $rows[] = Partial::make('domains::admin.template-editor-actions', ['cancelUrl'=>$this->detailUrl()]);
        return [Section::make('What saving changes', $rows)];
    }
    public function save(): void
    {
        $this->authorizeAccess();
        $data = $this->form->getState();
        $id = (int) $this->getRecord()->id;
        try {
            (new Writes())->run(Auth::user(), $id, 'save', Writes::ticket((int) Auth::id(), $id,
                $this->revision, 'save', $this->defaultId), 'DNS template saved', $data);
        } catch (\Illuminate\Database\QueryException|\Illuminate\Database\Eloquent\ModelNotFoundException) {
            Notification::make()->warning()->title('Reload the template and try again.')->send();
            return;
        } catch (\DomainException|\InvalidArgumentException $e) {
            Notification::make()->warning()->title($e->getMessage())->send();
            return;
        }
        Notification::make()->success()->title('DNS template saved')->send();
        $this->redirect($this->detailUrl());
    }
}
