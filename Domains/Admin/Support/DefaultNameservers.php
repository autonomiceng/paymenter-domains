<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource;
use Paymenter\Extensions\Servers\Domains\Models\WatchProfile;
use Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault;
/** The default nameservers for new domains, shown and changed at the top of Domains > DNS templates, beside the templates they come from.
 * Hosting-plan DNS zones are served by the hosting control panel (Plesk), provisioned by the hosting server
 * extension and never seen by this extension. DNS templates only name those nameservers.
 * Registrar DNS (Enom DNS, NameSilo DNSOwl) is used only while a domain points at the registrar's nameservers
 * and stops when the domain transfers out. */
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
            if(!$p->enabled || !$p->readProfile() || !SiteDnsDefault::fits($p->nameservers)){continue;}
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
        return Sheet::button(Action::make('siteDefault')->label('Change'))->modalHeading('Change template for new domains')
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
}
