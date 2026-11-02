<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Server,Setting};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation,WatchProfile};
/** Account default for registration and public DNS monitoring. Never creates a DNS zone. */
final class NewDomainDns
{
    public const KEY='domains_new_domain_dns_template';
    public const HELP='New registrations get these nameservers. Managed domains use this template for DNS monitoring unless assigned their own. Transfers keep their nameservers, and customer choices take priority. Records are checked only for domains with hosting.';
    public static function profile(int $server): ?WatchProfile
    {
        $id=Server::find($server)?->settings()->where('key',self::KEY)->first()?->value;
        $profile=$id?WatchProfile::find($id):null;
        return ManagedDnsSetup::usable($profile)?$profile:null;
    }
    public static function nameservers(int $server,string $action): array
    { return $action==='register'?(self::profile($server)?->nameservers??[]):[]; }
    /** Captured choices survive account changes; legacy editable orders may use the current template. */
    public static function forOperation(Operation $op): array
    {
        if($op->action!=='register'){return [];}
        return ($op->payload['nameservers']??[])?:self::nameservers((int)$op->server_id,'register');
    }
    public static function accounts(int $profile,bool $lock=false): array
    {
        $query=Setting::where('settingable_type',(new Server)->getMorphClass())->where('key',self::KEY)
            ->where('value',(string)$profile)->whereIn('settingable_id',Server::where('extension','Domains')->select('id'));
        if($lock){$query->lockForUpdate();}
        return $query->pluck('settingable_id')->map(fn($id)=>(int)$id)->all();
    }
    /** The settings review already holds the server mutex. Lock the template before checking or assigning it. */
    public static function saveLocked(Account $account,mixed $id): void
    {
        if(DB::transactionLevel()!==1){throw new \LogicException('Outer settings transaction required.');}
        $profile=$id?WatchProfile::whereKey($id)->lockForUpdate()->first():null;
        if($id && !ManagedDnsSetup::usable($profile)){throw new \DomainException('Choose an enabled DNS template. Create or turn on a template under DNS templates first.');}
        if($profile){
            try{self::validate($account->registrar_key,$profile->nameservers);}
            catch(\InvalidArgumentException){throw new \DomainException('This DNS template needs at least two valid nameservers supported by this registrar. Edit it under DNS templates, then try again.');}
        }
        Server::findOrFail($account->server_id)->settings()->updateOrCreate(['key'=>self::KEY],['type'=>'string','encrypted'=>false,'value'=>$profile?(string)$profile->id:'']);
    }
    public static function validate(string $registrar,array $servers): void
    {
        if($registrar==='enom'){\Paymenter\Extensions\Servers\Domains\Registrars\Enom\Registration::nameservers($servers);}
        else{\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\PurchaseMapping::nameservers($servers);}
    }
}
