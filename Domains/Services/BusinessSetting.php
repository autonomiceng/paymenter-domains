<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
/** Default pricing and the card fee: one Paymenter global row each, shared by every account. */
final class BusinessSetting
{
    public static function row(string $key,bool $lock=false): ?Setting
    {
        $q=self::query()->where('key',$key);if($lock){$q->lockForUpdate();}
        return $q->first();
    }
    /** The caller holds the account mutex, which also keeps a first save from making two rows. */
    public static function saveLocked(string $key,array $value): void
    {
        $s=self::row($key,true)??new Setting(['key'=>$key,'settingable_type'=>null,'settingable_id'=>null]);
        $s->type='array';$s->encrypted=false;$s->value=$value;$s->save();
    }
    /** What every staff review stamp reads: a change here makes open reviews stale. */
    public static function stamp(bool $lock=false): array
    {
        $q=DB::table('settings')->whereNull('settingable_type')->whereNull('settingable_id')->whereIn('key',[PricingRules::KEY,CardFee::KEY])->orderBy('key');
        return ($lock?$q->lockForUpdate():$q)->get(['key','value'])->all();
    }
    private static function query()
    {return Setting::whereNull('settingable_type')->whereNull('settingable_id');}
}
