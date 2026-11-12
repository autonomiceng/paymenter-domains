<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\{Callout,Group};
use Filament\Support\Enums\FontFamily;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource;
use Paymenter\Extensions\Servers\Domains\Models\WatchProfile;
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
use Paymenter\Extensions\Servers\Domains\Services\{ManagedDnsSetup,SiteDnsDefault};
/** The default nameservers for new domains, shown and changed at the top of Domains > DNS templates, beside the templates they come from. */
final class DefaultNameservers
{
    public const TITLE='Default nameservers';
    public const LINK='Change default nameservers';
    /** Names reserved for documentation and testing never resolve on the internet (RFC 2606, RFC 6761). */
    private const RESERVED=['test','example','invalid','localhost','example.com','example.net','example.org'];
    /** The page that shows and changes the default, for staff who can open it. */
    public static function url(): ?string
    {
        if(!RoutingProfileResource::canViewAny()){return null;}
        try{return RoutingProfileResource::getUrl();}catch(\Throwable){return null;}
    }
    /** Templates the default can use: on, readable and accepted by every connected registrar, so Save never refuses one.
     * Each shows its nameservers; one using reserved test names says so. @return array<int,string> */
    public static function options(): array
    {
        $out=[];
        foreach(WatchProfile::where('enabled',true)->orderBy('name')->get() as $p){
            if(!ManagedDnsSetup::usable($p) || !SiteDnsDefault::fits($p->nameservers)){continue;}
            $out[(int)$p->id]=$p->name.': '.implode(', ',$p->nameservers).(self::testNames($p->nameservers)?' (test names, not for real domains)':'');
        }
        return $out;
    }
    /** True when any nameserver is under a reserved test name. */
    public static function testNames(array $nameservers): bool
    {
        foreach($nameservers as $host){
            $host=strtolower(rtrim((string)$host,'.'));
            foreach(self::RESERVED as $name){if($host===$name || str_ends_with($host,'.'.$name)){return true;}}
        }
        return false;
    }
    /** Use another template. $saved runs after a save so the page can show the new default. */
    public static function choose(\Closure $saved): Action
    {
        return Sheet::button(Action::make('siteDefault')->label('Use another template'))->modalHeading(self::TITLE)
            ->modalDescription('New registrations get the nameservers of the template you choose. Transfers keep their own.')
            ->modalSubmitActionLabel('Save')
            ->schema([Select::make('profile')->label('DNS template')->required()
                ->default(fn()=>SiteDnsDefault::profile()?->id)->options(fn()=>self::options())
                ->helperText('Lists templates that are on and that every connected registrar accepts. To add one, use Add DNS template.')])
            ->visible(fn()=>Staff::canEditDefaultDns())->action(function(array $data,Action $action)use($saved):void {
                abort_unless(Staff::canEditDefaultDns(),403);
                try{SiteDnsDefault::save(Auth::user(),(int)$data['profile']);}
                catch(\DomainException $e){Notification::make()->warning()->title($e->getMessage())->send();$action->halt();return;}
                $saved();Notification::make()->title('Default nameservers saved')->success()->send();
            });
    }
    /** The card: the nameservers new domains get, the template they come from, and the two ways to change them.
     * $edit edits the default template in place; it is null when there is no usable default to edit. */
    public static function card(?Action $edit,Action $choose): Group
    {
        $default=SiteDnsDefault::profile();$canEdit=Staff::canEditDefaultDns();$refusal=SiteDnsDefault::refusal($canEdit);
        $problem=$refusal??($default?null:($canEdit?SiteDnsDefault::MISSING:'Default nameservers are Missing. New domains have no nameservers to use. '.SiteDnsDefault::ASK));
        // A default a registrar refuses still shows its nameservers and Edit, which is one of its two fixes.
        $names=TextEntry::make('default_nameservers')->label('Nameservers')->state($default?$default->nameservers:Missing::LABEL)
            ->listWithLineBreaks()->fontFamily($default?FontFamily::Mono:null)->extraAttributes(['class'=>'domains-wrap'])
            ->belowContent($default&&!$refusal?'New registrations get these. Transfers keep their own. Monitored domains without a template of their own are checked against them.':null);
        $template=TextEntry::make('default_template')->label('From template')->state($default?->name??Missing::LABEL)
            ->url($default?RoutingProfileResource::getUrl('view',['record'=>$default]):null);
        return Sheet::single([
            ...($problem?[Callout::make($problem)->status('danger')->columnSpanFull()]:[]),
            Sheet::section(self::TITLE,[Sheet::row(MissingEntry::apply($names),$edit&&$default?[$edit]:[]),Sheet::row(MissingEntry::apply($template),[$choose])]),
        ],'default-nameservers');
    }
}
