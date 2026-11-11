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
        $t->enum('id',['1'])->primary();$t->boolean('imported')->default(false);
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
            foreach(BusinessSetting::COLUMNS as $key=>$column){
                $rows=$globals->filter(fn($r)=>$r->key===$key&&$r->settingable_type===null&&$r->settingable_id===null)
                    ->sort(static fn($a,$b)=>strcmp((string)$b->updated_at,(string)$a->updated_at)?:($b->id<=>$a->id))->values();
                if($rows->isEmpty()){continue;}
                $shape=static fn($r)=>['type'=>$r->type,'encrypted'=>(bool)$r->encrypted,'value'=>$r->value];
                $chosen=$shape($rows[0]);$s->$column=$chosen;
                if($rows->contains(fn($r)=>$shape($r)!==$chosen)){
                    foreach($rows->slice(1) as $other){
                        $source=['id'=>(int)$other->id,'updated_at'=>$other->updated_at,...$shape($other)];
                        $label=match($key){PricingRules::KEY=>'Default pricing',CardFee::KEY=>'Card fee',SiteDnsDefault::KEY=>'Site DNS template'};
                        $reason=$label.': kept '.self::describe($key,$chosen).'; replaced '.self::describe($key,$source).'. Keep the most recently updated global setting';
                        // Large or heavily escaped values fit as ordered byte fragments in the existing audit store.
                        $parts=[$source];
                        if(strlen(json_encode($source,JSON_THROW_ON_ERROR))>24000){
                            $bytes=str_split((string)$source['value'],16000);unset($source['value']);$parts=[];
                            foreach($bytes as $i=>$bytesPart){$parts[]=[...$source,'part'=>$i,'parts'=>count($bytes),'value_base64'=>base64_encode($bytesPart)];}
                        }
                        foreach($parts as $part){
                            $entry=['key'=>'business-settings-import:'.$key.':'.$other->id.':'.($part['part']??0),
                                'actor_id'=>null,'at'=>gmdate(DATE_ATOM),'action'=>'Import global domain settings',
                                'reason'=>$reason,'phase'=>'completed','input'=>['setting'=>$key],
                                'before'=>['discarded'=>[$part]],'after'=>['selected'=>['id'=>(int)$rows[0]->id,'updated_at'=>$rows[0]->updated_at]]];
                            AuditArchives::append(0,'staff_settings',json_encode([$entry],JSON_THROW_ON_ERROR));
                        }
                    }
                }
            }
            $s->imported=true;$s->save();
            DB::table('settings')->whereNull('settingable_type')->whereNull('settingable_id')->whereIn('key',array_keys(BusinessSetting::COLUMNS))->delete();
        });
        self::verify();
    }
    /** Keep staff summaries small; full source bytes remain in the encrypted evidence. */
    private static function describe(string $key,array $source): string
    {
        if($source['encrypted']){return 'an encrypted value';}
        $raw=(string)$source['value'];if(strlen($raw)>1024){return 'a large saved value';}
        $value=$source['type']==='array'?json_decode($raw,true):$raw;
        try{
            if($key===CardFee::KEY&&is_array($value)){return CardFee::describe($value);}
            if($key===PricingRules::KEY&&is_array($value)){return PricingRule::describe($value);}
        }catch(\InvalidArgumentException){} // Invalid legacy choices still import without choosing a replacement.
        if($key===SiteDnsDefault::KEY&&is_string($value)){return 'DNS template #'.$value;}
        return 'saved value '.$raw;
    }
    public static function verify(): void
    {
        SchemaVerifier::verify(self::TABLE,self::definition(...));
        if(DB::table(self::TABLE)->count()!==1||!BusinessSettings::whereKey(1)->value('imported')){throw new \RuntimeException('Global domain choices are incomplete.');}
    }
}
