<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\{Action,ActionGroup};
use Filament\Forms\Components\{Select,Textarea,TextInput};
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Fieldset;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{ProductMappingResource,RoutingProfileResource,WatchResource};
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchProductMap,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites as Writes;
/** Staff writes for monitored domains, DNS templates and hosting products. The ticket binds the revision the page loaded, so a stale page changes nothing. */
final class WatchActions
{
    /** Audit reasons for writes that ask no question; the history shows the event once. */
    private const REASONS=['pause'=>'Monitoring paused','resume'=>'Monitoring resumed','read'=>'Check requested','profile'=>'DNS template changed'];
    private const DONE=['pause'=>'Monitoring paused','resume'=>'Monitoring resumed','read'=>'Check queued'];
    public static function action(string $kind,int $id,int $revision,string $action,string $label,array $fields=[]): Action
    {
        $a=Action::make($action)->label($label)->visible(fn()=>Staff::can(Writes::VIEW)&&Staff::can(Writes::MANAGE));
        if($fields){$a->schema($fields);}
        return $a->action(function(array $data,$livewire)use($kind,$id,$revision,$action):void {
                $reason=match($action){'note'=>(string)($data['note']??''),'retire'=>(string)($data['reason']??''),'save'=>$kind==='mapping'?'Hosting product saved':'DNS template saved','enable'=>$kind==='mapping'?'Monitoring turned on':'DNS template turned on',default=>self::REASONS[$action]};
                try{(new Writes())->run(Auth::user(),$kind,$id,$action,Writes::ticket((int)Auth::id(),$kind,$id,$revision,$action),$reason,$data);}
                catch(\DomainException|\InvalidArgumentException|\Illuminate\Database\QueryException|\Illuminate\Database\Eloquent\ModelNotFoundException $e){Notification::make()->warning()->title(self::error($kind,$e))->send();return;}
                Notification::make()->success()->title(self::DONE[$action]??'Saved')->send();
                $livewire->redirect(match($kind){'watch'=>WatchResource::getUrl('view',['record'=>$id]),'profile'=>$id?RoutingProfileResource::getUrl('view',['record'=>$id]):RoutingProfileResource::getUrl(),default=>$id?ProductMappingResource::getUrl('view',['record'=>$id]):ProductMappingResource::getUrl()});
            });
    }
    /** Write errors in page words. */
    private static function error(string $kind,\Throwable $e): string
    {
        $changed=match($kind){'watch'=>'This domain changed.','profile'=>'This template changed.',default=>'This product changed.'}.' Reload the page and try again.';
        if(!$e instanceof \DomainException && !$e instanceof \InvalidArgumentException){return $changed;}
        return match($e->getMessage()){
            'The saved details changed. Reload and try again.','Reload and try again.','The watch state changed. Reload and try again.','Reload the disabled profile first.','Review the saved disabled mapping before enabling it.','The saved mapping changed.'=>$changed,
            'A source mapping pins a different profile. Edit that mapping first.'=>'A hosting product uses another template. Edit that product first.',
            'Disable the referencing mappings before editing this profile.'=>'Turn off monitoring on its hosting products first.',
            'Choose an enabled routing profile.','Choose an enabled routing profile first.'=>'Choose a DNS template that is on.',
            'Enter one DNS target per line.'=>'Enter one value per line.',
            'This watch cannot be changed.'=>"This domain can't be changed.",
            'Public checks are disabled or the asynchronous queue is unavailable.'=>"Monitoring checks are off on this site, or the background worker isn't running.",
            'This watch has no scheduled checks yet.'=>'This domain has no checks scheduled yet.',
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
            self::action('watch',$id,$revision,'note','Add note',[Textarea::make('note')->label('Note')->required()->minLength(5)->maxLength(500)->rows(3)])->modalSubmitActionLabel('Add note'),
            self::action('watch',$id,$revision,'retire','Stop monitoring',[Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500)->rows(2)])
                ->color('danger')->requiresConfirmation()->modalHeading('Stop monitoring?')->modalDescription("Checks and alerts stop. The domain won't be added again automatically.")->modalSubmitActionLabel('Stop monitoring')];
        return [...$main,ActionGroup::make($more)->label('More')->button()->color('gray')];
    }
    /** "Check now" for the page notice and Missing hints; hidden unless the domain is monitored. */
    public static function check(WatchName $w,int $revision): Action
    {return self::action('watch',(int)$w->id,$revision,'read','Check now')->name('check')->visible(fn()=>$w->state==='active' && Staff::can(Writes::VIEW)&&Staff::can(Writes::MANAGE));}
    public static function enable(string $kind,WatchProfile|WatchProductMap $row,int $revision): Action
    {
        $mapping=$kind==='mapping';
        return self::action($kind,(int)$row->id,$revision,'enable',$mapping?'Turn on monitoring':'Turn on')->requiresConfirmation()->modalSubmitActionLabel($mapping?'Turn on monitoring':'Turn on')
            ->modalDescription($mapping?'Turn on monitoring for '.($row->product?->name??'Product #'.$row->product_id).'? Matched domains start being checked.':'Turn on '.$row->name.'? Monitoring checks start using it.')
            ->visible(fn()=>!$row->enabled && Staff::can(Writes::VIEW)&&Staff::can(Writes::MANAGE));
    }
    /** Add (no row) or Edit. Saving turns the row off until staff turn it on again. */
    public static function configuration(string $kind,WatchProfile|WatchProductMap|null $row,int $revision=0): Action
    {
        $fields=[];$lines=fn(string $name,string $label,array $value)=>Textarea::make($name)->label($label)->default(implode("\n",$value))->helperText('One per line.')->maxLength(8192)->rows(2);
        if($kind==='mapping'){
            if(!$row){$fields[]=Select::make('product_id')->label('Hosting product')->options(fn()=>\App\Models\Product::whereHas('server',fn($q)=>$q->where('extension','<>','Domains'))->pluck('name','id')->all())->required();}
            $fields[]=TextInput::make('property_key')->label('Domain field')->default($row?->property_key??'domain')->helperText('The service property that holds the hosting domain.')->required()->maxLength(64);
            $fields[]=Select::make('expected_ns_profile_id')->label('DNS template')->options(fn()=>self::templates())->default($row?->expected_ns_profile_id);
            $note='Saving turns monitoring off until you turn it on again.';
        }else{
            $fields[]=TextInput::make('name')->label('Name')->default($row?->name)->required()->maxLength(128);
            $fields[]=$lines('nameservers','Nameservers',$row?->nameservers??[]);
            foreach(['website_endpoints'=>'Website','email_endpoints'=>'Email'] as $key=>$label){$value=$row?->$key??[];
                $fields[]=Fieldset::make($label.' records')->columns(1)->schema([$lines($key,$label.' hostnames',$value['exact']??[]),$lines($key.'_suffix',$label.' hostname suffixes',$value['suffix']??[]),$lines($key.'_ips',$label.' IP addresses',$value['ips']??[])]);}
            $note='Saving turns this template off until you turn it on again.'.($row && WatchProductMap::where('expected_ns_profile_id',$row->id)->where('enabled',true)->exists()?' Turn off monitoring on its hosting products before editing.':'');
        }
        $action=self::action($kind,(int)($row?->id??0),$row?$revision:0,'save',$row?'Edit':($kind==='mapping'?'Add hosting product':'Add DNS template'),$fields)->modalSubmitActionLabel('Save');
        return $row?$action->color('gray')->modalDescription($note):$action;
    }
}
