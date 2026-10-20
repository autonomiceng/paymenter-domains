<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Forms\Components\{Hidden,Select,Textarea,TextInput,Checkbox};
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites as Writes;
final class WatchActions
{
    public static function action(string $kind,int $id,int $revision,string $action,string $label,array $fields=[]): Action
    {
        return Action::make($action)->label($label)->visible(fn()=>Staff::can(Writes::VIEW)&&Staff::can(Writes::MANAGE))
            ->color($action==='retire'?'danger':'primary')->requiresConfirmation()->modalDescription('Saved revision '.$revision.'. '.match($action){'pause'=>'Checks and notices pause until resumed. Saved details stay visible.','resume'=>'Checks and notices resume through their ordinary schedule and feature gates.','profile'=>'This changes the expected DNS and routing profile for future checks.','retire'=>'Ending this watch archives its source links and suppresses rediscovery.','read'=>'Only eligible checks may wake earlier. Claimed, delayed and unsupported checks retain their schedule. Feature gates remain unchanged.','note'=>'This adds an audit note without changing checks.','save'=>'Configuration saves disabled. Review the saved settings before enabling.','enable'=>'Enable the saved configuration shown below. Default feature gates remain unchanged.',default=>'This changes only the saved watch state or profile.'})
            ->schema([Hidden::make('ticket')->default(fn()=>Writes::ticket((int)Auth::id(),$kind,$id,$revision,$action)),...$fields,
                Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500)->rows(2)->helperText('One line. Do not include credentials or customer contact details.')])
            ->action(function(array $data,$livewire)use($kind,$id,$action):void {
                try{(new Writes())->run(Auth::user(),$kind,$id,$action,(string)($data['ticket']??''),(string)($data['reason']??''),$data);
                    Notification::make()->success()->title($action==='read'?'Check request recorded. Eligible checks retain their ordinary limits.':'Change recorded. Reload before making another change.')->send();
                }catch(\DomainException|\InvalidArgumentException $e){Notification::make()->warning()->title($e->getMessage())->send();}catch(\Illuminate\Database\QueryException|\Illuminate\Database\Eloquent\ModelNotFoundException $e){Notification::make()->warning()->title('Saved details changed. Reload and try again.')->send();}
            });
    }
    public static function watch(WatchName $w): array
    {
        $actions=[];
        foreach(['pause'=>'Pause this watch','resume'=>'Resume this watch','profile'=>'Edit profile','retire'=>'End this watch','read'=>'Request check','note'=>'Record note'] as $name=>$label){
            if(($name==='pause' && $w->state!=='active') || ($name==='resume' && $w->state!=='paused') || (in_array($name,['profile','retire','read'],true) && !in_array($w->state,$name==='read'?['active']:['active','paused'],true))){continue;}
            $fields=$name==='profile'?[Select::make('profile')->label('DNS and routing profile')->options(fn()=>WatchProfile::where('enabled',true)->pluck('name','id')->all())->default($w->ns_profile_id)->required(),Select::make('expected_dns')->label('Expected DNS')->options(['platform'=>'Platform DNS','external'=>'External DNS'])->default($w->expected_dns)]:[];
            $actions[]=self::action('watch',(int)$w->id,$w->revision,$name,$label,$fields);
        }
        return $actions;
    }
    public static function enable(string $kind,object $row): Action
    {return self::action($kind,(int)$row->id,(int)$row->revision,'enable',$kind==='mapping'?'Enable reviewed mapping':'Enable profile')->modalDescription('Saved revision '.$row->revision.'. '.($kind==='mapping'?'Product #'.$row->product_id.'; primary property '.$row->property_key.'; default profile '.($row->expected_ns_profile_id??'none').'. Preview sample: '.implode('; ',WatchFacts::preview($row)):'Profile '.$row->name.'; nameservers '.implode(', ',$row->nameservers).'. '.implode(' ',WatchFacts::impact((int)$row->id))))->visible(fn()=>!$row->enabled && Staff::can(Writes::VIEW)&&Staff::can(Writes::MANAGE));}
    public static function configuration(string $kind,?object $row): Action
    {
        $fields=[];
        if($kind==='mapping'){
            if(!$row){$fields[]=Select::make('product_id')->label('Hosting product')->options(fn()=>\App\Models\Product::whereHas('server',fn($q)=>$q->where('extension','<>','Domains'))->pluck('name','id')->all())->required();}
            $fields[]=TextInput::make('property_key')->label('Primary domain property key')->default($row?->property_key??'domain')->helperText('Use the single service property that contains its primary hosting domain. Missing or ambiguous values are held from discovery.')->required()->maxLength(64);
            $fields[]=Select::make('expected_ns_profile_id')->label('Default nameserver profile')->options(fn()=>WatchProfile::where('enabled',true)->pluck('name','id')->all())->default($row?->expected_ns_profile_id);
        }else{
            $fields[]=TextInput::make('name')->label('Profile name')->default($row?->name)->required()->maxLength(128);
            foreach(['nameservers'=>'Nameservers','website_endpoints'=>'Website DNS targets','email_endpoints'=>'Email DNS targets'] as $key=>$label){$value=$row?->$key??[];$fields[]=Textarea::make($key)->label($label.' · one hostname per line')->default(implode("\n",$key==='nameservers'?$value:($value['exact']??[])))->maxLength(8192);if($key!=='nameservers'){$fields[]=Textarea::make($key.'_suffix')->label($label.' · allowed hostname suffixes, one per line')->default(implode("\n",$value['suffix']??[]))->maxLength(8192);$fields[]=Textarea::make($key.'_ips')->label($label.' · one IP address per line')->default(implode("\n",$value['ips']??[]))->maxLength(8192);}}
        }
        $review=$kind==='profile' && $row?' Before editing, open referencing product mappings and choose Edit (saves disabled). Disable those mappings first.':'';
        return self::action($kind,(int)($row?->id??0),(int)($row?->revision??0),'save',$row?'Edit (saves disabled)':'Add disabled configuration',$fields)->modalDescription(($row?'Saved revision '.$row->revision.'. ':'').'Configuration saves disabled. Review the saved settings before enabling.'.$review);
    }
}
