<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Services\StaffReason;
use Filament\Actions\{Action,ActionGroup};
use Filament\Forms\Components\{Select,Textarea,TextInput};
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Fieldset;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{RoutingProfileResource,WatchResource};
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchProductMap,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Services\{StaffWatchWrites as Writes,WatchProducts};
/** Staff writes for monitored domains, DNS templates and hosting product lines. The ticket binds the revision the page loaded, so a stale page changes nothing. */
final class WatchActions
{
    /** Audit reasons for writes that ask no question; the history shows the event once. */
    private const REASONS=['pause'=>'Monitoring paused','resume'=>'Monitoring resumed','read'=>'Check requested','profile'=>'DNS template changed'];
    private const DONE=['pause'=>'Monitoring paused','resume'=>'Monitoring resumed'];
    public static function action(string $kind,int $id,int $revision,string $action,string $label,array $fields=[]): Action
    {
        $a=Action::make($action)->label($label)->visible(fn()=>Staff::can(Writes::VIEW)&&Staff::can(Writes::MANAGE));
        if($fields){$a->schema($fields);}
        if($action==='read'){$a->tooltip('Monitoring uses 0 registrar reads per domain per day.');}
        return $a->action(function(array $data,array $arguments,$livewire)use($kind,$id,$revision,$action):void {
                $id=(int)($arguments['map']??$id);$revision=(int)($arguments['revision']??$revision);
                $reason=match($action){'note'=>(string)($data['note']??''),'retire'=>(string)($data['reason']??''),'save'=>$kind==='mapping'?'Domain field chosen':'DNS template saved','enable'=>'DNS template turned on',default=>self::REASONS[$action]};
                try{(new Writes())->run(Auth::user(),$kind,$id,$action,Writes::ticket((int)Auth::id(),$kind,$id,$revision,$action),$reason,$data+$arguments);}
                catch(\DomainException|\InvalidArgumentException|\Illuminate\Database\QueryException|\Illuminate\Database\Eloquent\ModelNotFoundException $e){
                    if($action==='read' && $e instanceof \DomainException && $e->getMessage()==='A check was recently requested. Try again later.'){$livewire->waitForCheck();}
                    Notification::make()->warning()->title(self::error($kind,$e))->send();return;
                }
                if($action==='read'){
                    $livewire->waitForCheck();
                    Notification::make()->success()->title('Check requested')
                        ->body('This takes up to about six minutes. This page updates by itself.')->persistent()->send();
                    return;
                }
                Notification::make()->success()->title(self::DONE[$action]??'Saved')->send();
                $livewire->redirect(match($kind){'watch'=>WatchResource::getUrl('view',['record'=>$id]),'profile'=>$id?RoutingProfileResource::getUrl('view',['record'=>$id]):RoutingProfileResource::getUrl(),default=>WatchResource::getUrl()});
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
            'This watch cannot be changed.'=>"This domain can't be changed.",
            'Public checks are disabled or the asynchronous queue is unavailable.'=>'Monitoring is off or its queue is not set up. Ask your site admin.',
            'This watch has no scheduled checks yet.'=>'This domain has no checks scheduled yet. Ask your site admin.',
            'A check was recently requested. Try again later.'=>'Check already requested',
            default=>$e->getMessage()};
    }
    private static function templates(): array{return WatchProfile::where('enabled',true)->orderBy('name')->pluck('name','id')->all();}
    /** Header buttons and the More menu for a monitored domain. Linked names belong to a registration and have none. */
    public static function watch(WatchName $w,int $revision): array
    {
        if(!in_array($w->state,['active','paused'],true)){return [];}$id=(int)$w->id;$can=fn(string $state)=>fn()=>$w->state===$state && Staff::can(Writes::VIEW)&&Staff::can(Writes::MANAGE);
        $main=[self::action('watch',$id,$revision,'read','Check now')->visible($can('active')),self::action('watch',$id,$revision,'pause','Pause monitoring')->color('gray')->visible($can('active')),
            self::action('watch',$id,$revision,'resume','Resume monitoring')->color('gray')->visible($can('paused'))];
        $more=[self::action('watch',$id,$revision,'profile','Change DNS template',[
                Select::make('profile')->label('DNS template')->options(fn()=>self::templates())->default($w->ns_profile_id)->required(),
                Select::make('expected_dns')->label('Expected nameservers')->options(['platform'=>WatchFindings::company(),'external'=>'Other'])->default($w->expected_dns)])->modalDescription('Future checks compare against this DNS template.')->modalSubmitActionLabel('Save'),
            self::action('watch',$id,$revision,'note','Add note',[Textarea::make('note')->label('Note')->required()->minLength(5)->maxLength(500)->rows(3)->rules(['regex:'.StaffReason::SINGLE_LINE])])->modalSubmitActionLabel('Add note'),
            self::action('watch',$id,$revision,'retire','Stop monitoring',[Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500)->rows(2)->rules(['regex:'.StaffReason::SINGLE_LINE])])
                ->color('danger')->requiresConfirmation()->modalHeading('Stop monitoring?')->modalDescription("Checks and alerts stop. The domain won't be added again automatically.")->modalSubmitActionLabel('Stop monitoring')];
        return [...$main,ActionGroup::make($more)->label('More')->button()->color('gray')];
    }
    /** "Check now" for the page notice and Missing hints; hidden unless the domain is monitored. */
    public static function check(WatchName $w,int $revision): Action
    {return self::action('watch',(int)$w->id,$revision,'read','Check now')->name('check')->visible(fn()=>$w->state==='active' && Staff::can(Writes::VIEW)&&Staff::can(Writes::MANAGE));}
    public static function enable(WatchProfile $row,int $revision): Action
    {
        return self::action('profile',(int)$row->id,$revision,'enable','Turn on')->modalSubmitActionLabel('Turn on')
            ->disabled($row->readProfile()===null)->tooltip($row->readProfile()===null?WatchFacts::TEMPLATE_UNAVAILABLE:null)
            ->visible(fn()=>!$row->enabled && Staff::can(Writes::VIEW)&&Staff::can(Writes::MANAGE));
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
    /** Add (no row) or Edit a DNS template. Saving turns the row off until staff turn it on again. */
    public static function configuration(?WatchProfile $row,int $revision=0): Action
    {
        if($row&&$row->readProfile()===null){return self::action('profile',(int)$row->id,$revision,'save','Edit')->color('gray')->disabled()->tooltip(WatchFacts::TEMPLATE_UNAVAILABLE);}
        $lines=fn(string $name,string $label,array $value)=>Textarea::make($name)->label($label)->default(implode("\n",$value))->helperText('One per line.')->maxLength(8192)->rows(2);
        $fields=[TextInput::make('name')->label('Name')->default($row?->name)->required()->maxLength(128),$lines('nameservers','Nameservers',$row?->nameservers??[])];
        foreach(['website_endpoints'=>'Website','email_endpoints'=>'Email'] as $key=>$label){$value=$row?->$key??[];
            $fields[]=Fieldset::make($label.' records')->columns(1)->schema([$lines($key,$label.' hostnames',$value['exact']??[]),$lines($key.'_suffix',$label.' hostname suffixes',$value['suffix']??[]),$lines($key.'_ips',$label.' IP addresses',$value['ips']??[])]);}
        $note='Saving turns this template off until you turn it on again.';
        $action=self::action('profile',(int)($row?->id??0),$row?$revision:0,'save',$row?'Edit':'Add DNS template',$fields)->modalSubmitActionLabel('Save');
        return $row?$action->color('gray')->modalDescription($note):$action;
    }
}
