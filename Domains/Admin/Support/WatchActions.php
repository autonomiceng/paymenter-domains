<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Services\StaffReason;
use Filament\Actions\{Action,ActionGroup};
use Filament\Forms\Components\{Select,Textarea,TextInput};
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\{Get, Set};
use Filament\Infolists\Components\TextEntry;
use Paymenter\Extensions\Servers\Domains\Services\{ManagedDnsSetup, WatchTemplateAssignment};
use Filament\Support\Enums\{Alignment, FontFamily, Width};
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{RoutingProfileResource,WatchResource};
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Services\{SiteDnsDefault,StaffWatchWrites as Writes,WatchDiscovery};
/** Staff writes for monitored domains, DNS templates and hosting product lines. The ticket binds the revision the page loaded, so a stale page changes nothing. */
final class WatchActions
{
    /** Audit reasons for writes that ask no question; the history shows the event once. */
    private const REASONS=['pause'=>'Monitoring paused','resume'=>'Monitoring resumed','read'=>'Check requested','profile'=>'DNS template changed'];
    private const DONE=['pause'=>'Monitoring paused','resume'=>'Monitoring resumed'];
    /** The DNS template form says this once, for every box. */
    public const ONE_PER_LINE='Enter one value per line.';
    public static function action(string $kind,int $id,int $revision,string $action,string $label,array $fields=[],?int $defaultId=null): Action
    {
        $a=Action::make($action)->label($label)->visible(fn()=>Staff::can(Writes::VIEW)&&Staff::can(Writes::MANAGE));
        if($fields){$a->schema($fields);}
        if($action==='read'){$a->tooltip('Monitoring uses 0 registrar reads per domain per day.');}
        return $a->action(function(array $data,array $arguments,$livewire)use($kind,$id,$revision,$action,$defaultId):void {
                if ($kind === 'mapping') { $id=(int)($arguments['map']??$id);$revision=(int)($arguments['revision']??$revision); }
                $reason=match($action){'note'=>(string)($data['note']??''),'save'=>'DNS template saved','enable'=>'DNS template turned on','disable'=>'DNS template turned off','delete'=>'DNS template deleted',default=>self::REASONS[$action]};
                try{(new Writes())->run(Auth::user(),$kind,$id,$action,Writes::ticket((int)Auth::id(),$kind,$id,$revision,$action,$defaultId),$reason,$data+$arguments);}
                catch(\DomainException|\InvalidArgumentException|\Illuminate\Database\QueryException|\Illuminate\Database\Eloquent\ModelNotFoundException $e){
                    if($action==='read' && $e instanceof \DomainException && $e->getMessage()==='A check was recently requested. Try again later.'){
                        $retry = $arguments['retryKind'] ?? null;
                        $livewire->waitForCheck($retry);
                        if ($retry) {
                            $label = Writes::checkLabel($retry);
                            Notification::make()->warning()->title($livewire->pendingCheck ? $label.' already requested'
                                : 'Try the '.strtolower($label).' again in a few minutes.')->send();
                            return;
                        }
                    }
                    Notification::make()->warning()->title(self::error($kind,$e))->send();
                    // A refused template save keeps the dialog open with what was typed.
                    if(($kind==='profile'&&$action==='save') || $action==='profile'){throw new \Filament\Support\Exceptions\Halt();}
                    return;
                }
                if($action==='read'){
                    $livewire->waitForCheck();
                    $retry = $arguments['retryKind'] ?? null;
                    Notification::make()->success()->title($retry ? Writes::checkLabel($retry).' requested' : 'Check requested')
                        ->body('This takes up to about six minutes. This page updates by itself.')->persistent()->send();
                    return;
                }
                $done=$kind==='profile'?match($action){'save'=>$id?'DNS template saved':'DNS template added','enable'=>'DNS template turned on','disable'=>'DNS template turned off','delete'=>'DNS template deleted'}:(self::DONE[$action]??'Saved');
                // A resume saved while the hosting service is suspended keeps the domain paused until the service is active.
                $waiting=$kind==='watch' && $action==='resume' && WatchName::whereKey($id)->value('state')==='paused';
                Notification::make()->success()->title($waiting?WatchFacts::RESUME_SAVED:$done)->body($waiting?WatchFindings::HOSTING_SUSPENDED:null)->send();
                if($kind==='profile' && $action!=='delete'){
                    if($id){$livewire->refreshTemplate();}
                    return;
                }
                $livewire->redirect(match($kind){'watch'=>WatchResource::getUrl('view',['record'=>$id]),'profile'=>RoutingProfileResource::getUrl(),default=>WatchResource::getUrl('settings')});
            });
    }
    /** Write errors in page words. */
    private static function error(string $kind,\Throwable $e): string
    {
        $changed=match($kind){'watch'=>'This domain changed.','profile'=>'This template changed.',default=>'This product changed.'}.' Reload the page and try again.';
        if(!$e instanceof \DomainException && !$e instanceof \InvalidArgumentException){return $changed;}
        return match($e->getMessage()){
            'The saved details changed. Reload and try again.','Reload and try again.','The watch state changed. Reload and try again.','Reload the disabled profile first.','Review the saved disabled mapping before enabling it.','The saved mapping changed.'=>$changed,
            'Enter one DNS target per line.'=>'Enter one value per line.',
            Writes::UNREADABLE=>WatchFacts::TEMPLATE_UNAVAILABLE,
            'This watch cannot be changed.'=>"This domain can't be changed.",
            'Public checks are disabled or the asynchronous queue is unavailable.'=>'Monitoring is off or its queue is unavailable. Open Registrars → System checks and restore the background worker.',
            'This watch has no scheduled checks yet.'=>'The scheduler adds checks automatically. Open Registrars and check the background worker, then try Check now again.',
            'A check was recently requested. Try again later.'=>'Check already requested',
            default=>$e->getMessage()};
    }
    /** Header buttons and the More menu for a monitored domain. Linked names belong to a registration and have none.
     * Pause and Resume are the one reversible pair; a domain stops only when its hosting service ends, so staff have no Stop. */
    public static function watch(WatchName $w,int $revision): array
    {
        if(!in_array($w->state,['active','paused'],true)){return [];}$id=(int)$w->id;$can=fn(string $state)=>fn()=>$w->state===$state && Staff::can(Writes::VIEW)&&Staff::can(Writes::MANAGE);
        $main=[self::action('watch',$id,$revision,'pause','Pause monitoring')->color('gray')->visible($can('active')),
            // Resume is hidden once staff intent is resumed and only the suspended hosting service keeps the watch paused.
            self::action('watch',$id,$revision,'resume','Resume monitoring')->color('gray')->visible(fn()=>$can('paused')() && WatchDiscovery::canResume($w))];
        $more=[
            self::action('watch',$id,$revision,'note','Add note',[Textarea::make('note')->label('Note')->required()->minLength(5)->maxLength(500)->rows(3)->rules(['regex:'.StaffReason::SINGLE_LINE])])->modalSubmitActionLabel('Add note')];
        return [...$main,ActionGroup::make($more)->label('More')->button()->color('gray')];
    }
    public static function changeTemplate(WatchName $w, int $revision): Action
    {
        return self::action('watch',(int)$w->id,$revision,'profile','Change')
            ->color('gray')->outlined()
            ->modalHeading('Change DNS template')->modalWidth('domains-template-dialog')
            ->modalFooterActionsAlignment(Alignment::Start)
            ->modalDescription('Checks compare '.$w->canonical_name.' with this template. Saving leaves live nameservers and DNS records unchanged.')
            ->modalSubmitActionLabel('Save')->schema(fn () => self::assignmentFields($w))
            ->visible(fn()=>in_array($w->state,['active','paused'],true) && Staff::can(Writes::VIEW) && Staff::can(Writes::MANAGE));
    }

    private static function assignmentFields(WatchName $watch): array
    {
        $profiles = WatchProfile::where('enabled', true)->orderBy('name')->get()->filter(ManagedDnsSetup::usable(...));
        $default = SiteDnsDefault::profile();
        $choices = [];
        $labels = [];
        $selected = null;
        if ($default) {
            $key = WatchTemplateAssignment::choice($default, true);
            $choices[$key] = $default;
            $labels[$key] = 'Follow site default ('.$default->name.')';
            if ($watch->ns_profile_id === null) { $selected = $key; }
        }
        foreach ($profiles as $profile) {
            $key = WatchTemplateAssignment::choice($profile, verified:WatchTemplateAssignment::verified($profile, $default));
            $choices[$key] = $profile;
            $labels[$key] = $profile->name;
            if ((int) $watch->ns_profile_id === (int) $profile->id) { $selected = $key; }
        }
        $replacement = $selected === null && $watch->ns_profile_id !== null;
        $savedName = $replacement ? SiteDnsDefault::watchProfile($watch)?->name : null;
        if ($replacement && $profiles->count() === 1) { $selected = array_key_last($choices); }
        $suggest = fn ($key) => isset($choices[$key]) ? WatchTemplateAssignment::suggested($watch, $choices[$key], str_starts_with($key, 'site:'), str_starts_with($key, 'deployment:')) : null;
        $intents = ['platform' => "Use this template's nameservers", 'external' => 'Uses different nameservers',
            'inherit' => 'Follow site expectation', 'unset' => 'Keep expectation unset'];
        return [
            Select::make('assignment')->label('DNS template')->options($labels)->default($selected)->required()->live()
                ->helperText($replacement ? ($savedName ? $savedName.' cannot be used. Choose a replacement.' : 'The saved template cannot be used. Choose a replacement.') : null)
                ->afterStateUpdated(fn ($state, Set $set) => $set('expected_dns', $suggest($state))),
            TextEntry::make('preview')->label(fn (Get $get) => isset($choices[$get('assignment')]) ? 'Nameservers in '.$choices[$get('assignment')]->name : 'Nameservers')
                ->fontFamily(FontFamily::Mono)->listWithLineBreaks()
                ->state(fn (Get $get) => ($choices[$get('assignment')] ?? null)?->readProfile()?->nameservers ?? [])
                ->belowContent(function (Get $get) use ($choices): ?string {
                    $template = ($choices[$get('assignment')] ?? null)?->readProfile();
                    if (!$template) { return null; }
                    $web = array_sum(array_map('count', $template->website));
                    $mail = array_sum(array_map('count', $template->email));
                    return 'Website records: '.($web ?: 'none').'. Email records: '.($mail ?: 'none').'.';
                }),
            TextEntry::make('expectation')->label('Expected nameservers')
                ->state(fn (Get $get) => $intents[$get('expected_dns')] ?? 'Open Advanced and choose what checks should expect.'),
            Section::make('Advanced')->collapsible()->collapsed(fn (Get $get) => in_array($get('expected_dns'), ['inherit', 'platform'], true))
                ->schema([Select::make('expected_dns')->label('Expected nameservers')->live()->required()
                    ->options(function (Get $get) use ($intents, $suggest) {
                        $key = $get('assignment');
                        $options = array_slice($intents, 0, 2, true);
                        if (str_starts_with((string) $key, 'site:')) { $options['inherit'] = $intents['inherit']; }
                        if ($suggest($key) === 'unset') { $options['unset'] = $intents['unset']; }
                        return $options;
                    })->default($suggest($selected))->helperText('When different nameservers are expected, a difference does not trigger a mismatch alert.')]),
        ];
    }
    /** "Check now" for Missing check-time hints; hidden unless the domain is monitored. */
    public static function check(WatchName $w,int $revision,?string $retryKind=null): Action
    {return self::action('watch',(int)$w->id,$revision,'read','Check now')->color('gray')->outlined()->arguments($retryKind ? ['retryKind'=>$retryKind] : [])->visible(fn()=>$w->state==='active' && Staff::can(Writes::VIEW)&&Staff::can(Writes::MANAGE));}
    public static function enable(WatchProfile $row,int $revision): Action
    {
        return self::templateGuard(self::action('profile',(int)$row->id,$revision,'enable','Turn on')->view('domains::admin.template-switch')
            ->arguments(['on'=>false,'blocked'=>$row->readProfile()===null])->requiresConfirmation()->modalHeading('Turn on DNS template?')
            ->modalDescription('Monitoring can check domains against these records. New domains keep their current template.')
            ->modalSubmitActionLabel('Turn on')
            ->visible(fn()=>!$row->enabled && Staff::can(Writes::VIEW)&&Staff::can(Writes::MANAGE)),$row->readProfile()===null?WatchFacts::TEMPLATE_UNAVAILABLE:null);
    }
    /** Blocked switches keep the same dimming and reason as the shared blocked button. */
    private static function templateGuard(Action $action,?string $reason): Action
    {
        return $reason?$action->tooltip($reason)->livewireClickHandlerEnabled(false)
            ->extraAttributes(['aria-disabled'=>'true','aria-label'=>'Monitoring. '.$reason,'class'=>'domains-blocked']):$action;
    }
    public static function disable(WatchProfile $row,int $revision): Action
    {
        $reason=\Paymenter\Extensions\Servers\Domains\Services\DnsTemplateUsage::refusal((int)$row->id)??($row->readProfile()===null?WatchFacts::TEMPLATE_UNAVAILABLE:null);
        return self::templateGuard(self::action('profile',(int)$row->id,$revision,'disable','Turn off')->view('domains::admin.template-switch')
            ->arguments(['on'=>true,'blocked'=>$reason!==null])->requiresConfirmation()->modalHeading('Turn off DNS template?')
            ->modalDescription('Monitoring will no longer use this template. Saved records stay here.')
            ->modalSubmitActionLabel('Turn off')->visible(fn()=>$row->enabled && Staff::can(Writes::VIEW)&&Staff::can(Writes::MANAGE)),$reason);
    }
    public static function deleteTemplate(WatchProfile $row,int $revision): Action
    {
        $reason=\Paymenter\Extensions\Servers\Domains\Services\DnsTemplateUsage::refusal((int)$row->id);
        if($reason){return Sheet::blocked('delete','Delete','DNS template',$reason)->color('danger')
            ->action(fn()=>Notification::make()->warning()->title($reason)->send())
            ->visible(fn()=>Staff::can(Writes::VIEW)&&Staff::can(Writes::MANAGE));}
        return self::action('profile',(int)$row->id,$revision,'delete','Delete')->color('danger')->outlined()
            ->requiresConfirmation()->modalHeading('Delete DNS template?')->modalDescription('The saved nameservers and records will be deleted. This cannot be undone.')
            ->modalSubmitActionLabel('Delete');
    }
    public static function addTemplate(): Action
    {
        return self::action('profile', 0, 0, 'save', 'Add DNS template', self::templateFields())
            ->modalDescription(self::ONE_PER_LINE)->modalWidth(Width::Medium)->modalSubmitActionLabel('Save');
    }

    public static function canEditTemplate(WatchProfile $row): bool
    {
        return Staff::can(Writes::VIEW) && Staff::can(Writes::MANAGE)
            && (!SiteDnsDefault::uses((int) $row->id) || Staff::canEditDefaultDns());
    }

    public static function templateFields(?WatchProfile $row = null): array
    {
        $fields = [
            TextInput::make('name')->label('Name')->default($row?->name)->required()->maxLength(128)
                ->rule(FieldRules::check(fn ($name) => Writes::requireUniqueName((string) $name, $row?->id))),
            Textarea::make('nameservers')->label('Nameservers')->default(implode("\n", $row?->nameservers ?? []))
                ->required()->maxLength(8192)->rows(max(4, count($row?->nameservers ?? [])))->rule(FieldRules::hostname(13))
                ->helperText('Enter one nameserver per line. Checks compare the complete set.'),
        ];
        foreach (['website_endpoints' => 'Website', 'email_endpoints' => 'Email'] as $key => $label) {
            $value = $row?->$key ?? [];
            $empty = !array_filter($value);
            $group = Section::make($empty ? 'Add '.strtolower($label).' records' : $label.' records')->collapsible()->collapsed($empty);
            $inputs = [];
            foreach (['exact' => [$key, 'Hostnames', 'host.example.com'], 'suffix' => [$key.'_suffix', 'Hostnames ending in', 'example.com'],
                'ips' => [$key.'_ips', 'IP addresses', '192.0.2.1']] as $type => [$name, $title, $example]) {
                $inputs[] = Textarea::make($name)->label($title)->placeholder($example)->default(implode("\n", $value[$type] ?? []))
                    ->rows(2)->maxLength(8192)->rule($type === 'ips' ? FieldRules::ip() : FieldRules::hostname());
            }
            $fields[] = $group->schema($inputs);
        }
        return $fields;
    }
}
