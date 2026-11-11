<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Server;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\BusinessSettings;
/** Copy once under the old settings locks, then retire only the three extension-wide native keys. */
final class BusinessSettingsSchema
{
    public const TABLE='domains_business_settings';
    public const MIGRATION='2026_11_11_000061_create_business_settings';
    private static function definition(Blueprint $t): void
    {
        $t->unsignedTinyInteger('id')->primary();$t->boolean('imported')->default(false);
        $t->longText('pricing_rule')->nullable();$t->longText('card_fee')->nullable();$t->longText('dns_template')->nullable();
    }
    public static function install(): void
    {
        if(!Schema::hasTable(self::TABLE)){Schema::create(self::TABLE,self::definition(...));}
        SchemaVerifier::verify(self::TABLE,self::definition(...));
        if(BusinessSettings::whereKey(1)->value('imported')){self::verify();return;}
        DB::transaction(static function(): void {
            // Keep the old account/settings lock order for the one-time import and its staff history.
            Server::where('extension','Domains')->orderBy('id')->lockForUpdate()->get();
            DB::table('domains_accounts')->orderBy('server_id')->lockForUpdate()->get();
            $globals=DB::table('settings')->orderBy('id')->lockForUpdate()->get();
            DB::table(self::TABLE)->insertOrIgnore(['id'=>1]);
            $s=BusinessSetting::singleton(true);if($s->imported){return;}
            $history=[];
            foreach(BusinessSetting::COLUMNS as $key=>$column){
                $rows=$globals->filter(fn($r)=>$r->key===$key&&$r->settingable_type===null&&$r->settingable_id===null)
                    ->sort(static fn($a,$b)=>strcmp((string)$b->updated_at,(string)$a->updated_at)?:($b->id<=>$a->id))->values();
                if($rows->isEmpty()){continue;}
                $shape=static fn($r)=>['type'=>$r->type,'encrypted'=>(bool)$r->encrypted,'value'=>$r->value];
                $chosen=$shape($rows[0]);$s->$column=$chosen;
                if($rows->contains(fn($r)=>$shape($r)!==$chosen)){
                    $source=static fn($r)=>['id'=>(int)$r->id,'updated_at'=>$r->updated_at,...$shape($r)];
                    $history[]=['key'=>'business-settings-import:'.$key,'actor_id'=>0,'at'=>gmdate(DATE_ATOM),
                        'action'=>'Import global domain settings','reason'=>'Keep the most recently updated global setting','phase'=>'completed',
                        'input'=>['setting'=>$key],'before'=>['discarded'=>$rows->slice(1)->map($source)->values()->all()],
                        'after'=>['selected'=>$source($rows[0])]];
                }
            }
            // Server 0 is the global staff history, also included in each account's history reader.
            if($history){AuditArchives::append(0,'staff_settings',json_encode($history,JSON_THROW_ON_ERROR));}
            $s->imported=true;$s->save();
            DB::table('settings')->whereNull('settingable_type')->whereNull('settingable_id')->whereIn('key',array_keys(BusinessSetting::COLUMNS))->delete();
        });
        self::verify();
    }
    public static function verify(): void
    {
        SchemaVerifier::verify(self::TABLE,self::definition(...));
        if(DB::table(self::TABLE)->count()!==1||!BusinessSettings::whereKey(1)->value('imported')){throw new \RuntimeException('Global domain choices are incomplete.');}
    }
}
