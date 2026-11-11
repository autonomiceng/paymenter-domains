<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Services\StaffReason;
use Filament\Actions\{Action,ActionGroup};
use Filament\Forms\Components\{Select,Textarea,TextInput};
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Fieldset;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{RoutingProfileResource,WatchResource};
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchProductMap,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Services\{StaffWatchWrites as Writes,WatchDiscovery,WatchProducts};
/** Staff writes for monitored domains, DNS templates and hosting product lines. The ticket binds the revision the page loaded, so a stale page changes nothing. */
final class WatchActions
{
    /** Audit reasons for writes that ask no question; the history shows the event once. */
    private const REASONS=['pause'=>'Monitoring paused','resume'=>'Monitoring resumed','read'=>'Check requested','profile'=>'DNS template changed'];
    private const DONE=['pause'=>'Monitoring paused','resume'=>'Monitoring resumed'];
    /** The DNS template form says this once, for every box. */
    public const ONE_PER_LINE='Enter one value per line.';
    public static function action(string $kind,int $id,int $revision,string $action,string $label,array $fields=[]): Action
    {
        $a=Action::make($action)->label($label)->visible(fn()=>Staff::can(Writes::VIEW)&&Staff::can(Writes::MANAGE));
        if($fields){$a->schema($fields);}
        if($action==='read'){$a->tooltip('Monitoring uses 0 registrar reads per domain per day.');}
        return $a->action(function(array $data,array $arguments,$livewire)use($kind,$id,$revision,$action):void {
                $id=(int)($arguments['map']??$id);$revision=(int)($arguments['revision']??$revision);
                $reason=match($action){'note'=>(string)($data['note']??''),'save'=>$kind==='mapping'?'Domain field chosen':'DNS template saved','enable'=>'DNS template turned on','disable'=>'DNS template turned off','delete'=>'DNS template deleted',default=>self::REASONS[$action]};
                try{(new Writes())->run(Auth::user(),$kind,$id,$action,Writes::ticket((int)Auth::id(),$kind,$id,$revision,$action),$reason,$data+$arguments);}
                catch(\DomainException|\InvalidArgumentException|\Illuminate\Database\QueryException|\Illuminate\Database\Eloquent\ModelNotFoundException $e){
                    if($action==='read' && $e instanceof \DomainException && $e->getMessage()==='A check was recently requested. Try again later.'){$livewire->waitForCheck();}
                    Notification::make()->warning()->title(self::error($kind,$e))->send();
                    // A refused template save keeps the dialog open with what was typed.
                    if($kind==='profile'&&$action==='save'){throw new \Filament\Support\Exceptions\Halt();}
                    return;
                }
                if($action==='read'){
                    $livewire->waitForCheck();
                    Notification::make()->success()->title('Check requested')
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
                $livewire->redirect(match($kind){'watch'=>WatchResource::getUrl('view',['record'=>$id]),'profile'=>RoutingProfileResource::getUrl(),default=>WatchResource::getUrl()});
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
    private static function templates(): array{return WatchProfile::where('enabled',true)->orderBy('name')->pluck('name','id')->all();}
    /** Header buttons and the More menu for a monitored domain. Linked names belong to a registration and have none.
     * Pause and Resume are the one reversible pair; a domain stops only when its hosting service ends, so staff have no Stop. */
    public static function watch(WatchName $w,int $revision): array
    {
        if(!in_array($w->state,['active','paused'],true)){return [];}$id=(int)$w->id;$can=fn(string $state)=>fn()=>$w->state===$state && Staff::can(Writes::VIEW)&&Staff::can(Writes::MANAGE);
        $main=[self::action('watch',$id,$revision,'read','Check now')->visible($can('active')),self::action('watch',$id,$revision,'pause','Pause monitoring')->color('gray')->visible($can('active')),
            // Resume is hidden once staff intent is resumed and only the suspended hosting service keeps the watch paused.
            self::action('watch',$id,$revision,'resume','Resume monitoring')->color('gray')->visible(fn()=>$can('paused')() && WatchDiscovery::canResume($w))];
        $more=[self::action('watch',$id,$revision,'profile','Change DNS template',[
                Select::make('profile')->label('DNS template')->options(fn()=>self::templates())->default($w->ns_profile_id)->required(),
                Select::make('expected_dns')->label('Expected nameservers')->options(['platform'=>WatchFindings::company(),'external'=>'Other'])->default($w->expected_dns)])->modalDescription('Future checks compare against this DNS template.')->modalSubmitActionLabel('Save'),
            self::action('watch',$id,$revision,'note','Add note',[Textarea::make('note')->label('Note')->required()->minLength(5)->maxLength(500)->rows(3)->rules(['regex:'.StaffReason::SINGLE_LINE])])->modalSubmitActionLabel('Add note')];
        return [...$main,ActionGroup::make($more)->label('More')->button()->color('gray')];
    }
    /** "Check now" for Missing check-time hints; hidden unless the domain is monitored. */
    public static function check(WatchName $w,int $revision): Action
    {return self::action('watch',(int)$w->id,$revision,'read','Check now')->name('check')->visible(fn()=>$w->state==='active' && Staff::can(Writes::VIEW)&&Staff::can(Writes::MANAGE));}
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
    /** The one control on a hosting product line. The revision is the one the page loaded, so a stale page changes nothing. */
    public static function productSwitch(): Action
    {
        return Action::make('productSwitch')->view('domains::admin.watch-switch')->visible(fn($livewire)=>$livewire->canManageProducts())
            ->action(function(array $arguments):void {
                $id=(int)($arguments['map']??0);$on=WatchProductMap::whereKey($id)->value('enabled');$action=$on?'disable':'enable';
                try{(new Writes())->run(Auth::user(),'mapping',$id,$action,Writes::ticket((int)Auth::id(),'mapping',$id,(int)($arguments['revision']??0),$action),$on?'Monitoring turned off':'Monitoring turned on');}
                catch(\DomainException|\InvalidArgumentException|\Illuminate\Database\QueryException|\Illuminate\Database\Eloquent\ModelNotFoundException $e){Notification::make()->warning()->title(self::error('mapping',$e))->send();}
            });
    }
    /** For a line that isn't monitored. The system can't tell which field holds the domain, or that a field it or staff chose is wrong, so a person says which. */
    public static function chooseField(): Action
    {
        return self::action('mapping',0,0,'save','Choose a field')->name('chooseField')->visible(fn($livewire)=>$livewire->canManageProducts())->link()->modalHeading('Choose the domain field')->modalDescription('Choose the field that holds the main domain. Monitoring starts when you save.')->modalSubmitActionLabel('Save')
            ->schema(function(array $arguments):array {$fields=WatchProducts::fields(\App\Models\Product::findOrFail((int)($arguments['product_id']??0)));
                return [Select::make('property_key')->label('Domain field')->options($fields)->required()
                    ->helperText($fields===[]?'No service on this product has a text field yet. If it has a domain, add a text option for it.':null)];});
    }
    /** Add (no row) or Edit a DNS template. Validated edits preserve whether monitoring is on. */
    public static function configuration(?WatchProfile $row,int $revision=0): Action
    {
        if($row&&$row->readProfile()===null){return self::action('profile',(int)$row->id,$revision,'save','Edit')->color('gray')->disabled()->tooltip(WatchFacts::TEMPLATE_UNAVAILABLE);}
        // A short form: one note says one value per line, and the record groups are headed, not boxed.
        // Each box checks its lines in the dialog and names the bad one, so a mistake keeps the dialog and what was typed.
        $lines=fn(string $name,string $label,array $value,?\Illuminate\Contracts\Validation\ValidationRule $rule=null)=>Textarea::make($name)->label($label)->default(implode("\n",$value))->maxLength(8192)->rows(2)->rule($rule??FieldRules::hostname());
        $fields=[TextInput::make('name')->label('Name')->default($row?->name)->required()->maxLength(128)->rule(FieldRules::check(fn($name)=>Writes::requireUniqueName((string)$name,$row?->id))),$lines('nameservers','Nameservers',$row?->nameservers??[],FieldRules::hostname(13))->required()];
        foreach(['website_endpoints'=>'Website','email_endpoints'=>'Email'] as $key=>$label){$value=$row?->$key??[];
            $fields[]=Fieldset::make($label.' records')->contained(false)->columns(1)->schema([$lines($key,'Hostnames',$value['exact']??[]),$lines($key.'_suffix','Hostname suffixes',$value['suffix']??[]),$lines($key.'_ips','IP addresses',$value['ips']??[],FieldRules::ip())]);}
        $note=($row?'Future checks use these records. Monitoring stays on if this template is on. ':'').self::ONE_PER_LINE;
        return self::action('profile',(int)($row?->id??0),$row?$revision:0,'save',$row?'Edit':'Add DNS template',$fields)->modalHeading($row?'Edit DNS template':'Add DNS template')
            ->modalDescription($note)->modalWidth(Width::Medium)->modalSubmitActionLabel('Save')->color($row?'gray':'primary');
    }
}
