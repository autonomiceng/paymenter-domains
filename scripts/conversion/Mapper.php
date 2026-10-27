<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace DomainsConversion;
use App\Models\{Category,Server,Product,NotificationTemplate,Role};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{ReminderTemplates,ReminderSchedules};
final class Mapper
{
    /** Remove only independently checked embedded bindings; original ciphertext remains in source audit. */
    public static function embedded(array &$v,array $d,string $old,bool $historical=false): void
    {
        $expected=['account_fingerprint'=>$old,'server_id'=>(int)$d['server_id'],'authority_generation'=>(int)$d['authority_generation'],'provider_domain_id'=>$d['provider_domain_id'],'confirmed_revision'=>(int)$d['confirmed_revision']];
        foreach($v as$key=>&$value){if(array_key_exists($key,$expected)){
                $valid=$value===$expected[$key]||($historical&&$key==='confirmed_revision'&&is_int($value)&&$value>=0&&$value<=$expected[$key]);
                if(!$valid){throw new \DomainException('Contradictory embedded source authority.');}unset($v[$key]);
            }elseif(is_array($value)){self::embedded($value,$d,$old,$historical);}}
        unset($value);
    }
    public static function operation(array $o,array $d,array $a,int $generation): array
    {
        if((int)$o['service_id']!==(int)$d['service_id']){throw new \DomainException('Operation service reference mismatch.');}
        foreach(['invoice_id'=>'invoices','invoice_item_id'=>'invoice_items','authorized_by'=>'users']as$key=>$table){if($o[$key]!==null&&!DB::table($table)->where('id',$o[$key])->exists()){throw new \DomainException('Missing operation native reference.');}}
        if($o['invoice_item_id']!==null&&DB::table('invoice_items')->where('id',$o['invoice_item_id'])->value('invoice_id')!==$o['invoice_id']){throw new \DomainException('Operation invoice/item mismatch.');}
        $p=Inputs::decrypt($o['policy']);$c=Inputs::decrypt($o['cost']);$attempt=[];$funding=[];
        $known=array_key_exists('account_fingerprint',$p)&&$p['account_fingerprint']===$a['old']&&array_key_exists('authority_generation',$p)&&$p['authority_generation']===(int)$d['authority_generation'];
        self::embedded($p,$d,$a['old'],true);
        foreach(['dispatch_attempted_at','dispatch_claim_token']as$key){if(array_key_exists($key,$p)){$attempt[$key]=$p[$key];unset($p[$key]);}}
        if($o['action']==='renew'&&$o['state']==='succeeded'&&($authorized=self::renewalCost($o,$d,$a))!==null){$attempt['authorized_cost']=$authorized;}
        if(array_key_exists('execution',$c)){$attempt['execution']=$c['execution'];unset($c['execution']);}
        if(array_key_exists('refund',$c)){$funding['refund']=$c['refund'];unset($c['refund']);}
        // A legacy row without neutral admission/seal/funding proof is never reclassified definitely unsent.
        $attempt['legacy_retained']=true;
        return [...$o,'server_id'=>$d['server_id'],'registrar_key'=>'enom','account_fingerprint'=>$known?$a['binding']->fingerprint:null,
            'authority_generation'=>$known?$generation:null,'policy'=>$p,'cost'=>$c,'attempt'=>$attempt,'funding'=>$funding];
    }
    public static function renewalCost(array $o,array $d,array $a): ?array
    {
        $c=Inputs::decrypt($o['cost']);$q=$c['quote']??[];$actual=$c['actual']??[];
        if(($q['complete']??null)!==true||($q['currency']??null)!=='USD'||($actual['currency']??null)!=='USD'||!is_string($q['amount']??null)){return null;}
        $amount=\Paymenter\Extensions\Servers\Domains\Services\PricingRule::wholesale($q['amount']);
        if(\Paymenter\Extensions\Servers\Domains\Services\PricingRule::units($amount,4)<=0||\Paymenter\Extensions\Servers\Domains\Services\PricingRule::units($amount,4)!==\Paymenter\Extensions\Servers\Domains\Services\PricingRule::units($actual['amount']??'',4)){return null;}
        if(($q['state']??null)!=='quoted'||($q['source']??null)!=='Check+GetExtendInfo'||($q['command']??null)!=='Extend'||($q['base_expires_at']??null)!==$o['base_expires_at']||!is_int($q['confirmed_revision']??null)||$q['confirmed_revision']<0||$q['confirmed_revision']>(int)$d['confirmed_revision']||($q['domain']??null)!==$d['domain']||($q['action']??null)!=='renew'||($q['term']??null)!==(int)$o['term']||($q['account_fingerprint']??null)!==$a['old']||($q['authority_generation']??null)!==(int)$d['authority_generation']||($q['provider_domain_id']??null)!==$d['provider_domain_id']||(int)($q['server_id']??0)!==(int)$d['server_id']){throw new \DomainException('Retained renewal quote binding mismatch.');}
        return ['amount'=>$amount,'currency'=>'USD'];
    }
    public static function native(Planner $p): void
    {
        foreach($p->bindings as$id=>$a){$s=Server::findOrFail($id);$p->changes['servers'][$id]=['extension'=>'Domains'];$v=$s->settings->pluck('value','key');
            // Keep original settings/credentials as audit. Add neutral keys without guessing activation acceptance.
            foreach(['issue_days','due_days','credits_auto_use','default_nameservers','dns_tools','privacy_price','complimentary_cost_cap','observer_requests_per_minute']as$key){
                if($v->has($key)){$p->changes['settings'][$id]['domains_'.$key]=['value'=>$v->get($key),'type'=>$s->settings->firstWhere('key',$key)->type,'encrypted'=>false];}}
            foreach(['billing_enabled','observations_enabled','manual_renewals_enabled']as$key){$p->changes['settings'][$id]['domains_'.$key]=['value'=>false,'type'=>'boolean','encrypted'=>false];}
            $credentials=['uid'=>$v->get('uid'),'token'=>$v->get('token')];$b=$a['binding'];
            $p->changes['settings'][$id]['domains_credentials']=['value'=>json_encode($credentials,JSON_THROW_ON_ERROR),'type'=>'string','encrypted'=>true];
            $p->changes['settings'][$id]['domains_verified_credentials']=['value'=>hash_hmac('sha256',$b->fingerprint.json_encode($credentials,JSON_THROW_ON_ERROR),(string)config('app.key')),'type'=>'string','encrypted'=>true];
            if($v->has('pricing_default_rule')){$p->changes['settings'][$id]['domains_pricing_default_rule']=['value'=>$v->get('pricing_default_rule'),'type'=>'array','encrypted'=>false];}
            $p->put('domains_reminder_schedules',$id,['id'=>$id,'server_id'=>$id,'account_fingerprint'=>$b->fingerprint,'enabled'=>false,
                'before_days'=>json_encode(ReminderSchedules::offsets((string)$v->get('reminder_days','60,30,7,1'))),'after_days'=>json_encode(ReminderSchedules::offsets((string)$v->get('expired_reminder_days','3'))),
                'revision'=>1,'audit'=>[['source'=>'enom_05_conversion','enabled_before'=>true,'enabled_after'=>false]]]);
        }
        foreach(Product::whereIn('server_id',array_keys($p->bindings))->orderBy('id')->get()as$product){$v=$product->settings->pluck('value','key');
            if(!$v->has('enom_catalogue')&&!$v->has('enom_tld_id')&&!$v->has('enom_action')){
                if(!self::retiredProduct($product)){throw new \DomainException('Unreviewed native product on legacy server.');}
                $p->changes['products'][$product->id]=['server_id'=>null];continue;
            }
            $id=$v->get('enom_tld_id');$action=$v->get('enom_action');$t=DB::table('enom_tlds')->where('id',$id)->first();
            if($v->get('enom_catalogue')!=='1'||!$t||(int)$t->server_id!==(int)$product->server_id||!in_array($action,['register','transfer'],true)
                ||!$product->hidden||$product->allow_quantity!=='separated'){throw new \DomainException('Unreviewed native product on legacy server.');}
            $slug='domains-enom-s'.$product->server_id.'-'.$t->tld.'-'.($action==='register'?'registration':'transfer');
            if(Product::where('slug',$slug)->where('id','!=',$product->id)->exists()){throw new \DomainException('Neutral product slug collision.');}
            $p->changes['products'][$product->id]=['slug'=>$slug];$p->changes['product_settings'][$product->id]=['domains_catalogue'=>'1','domains_tld_id'=>(string)$id,'domains_action'=>$action,'domains_registrar'=>'enom'];
        }
        foreach(NotificationTemplate::where('key','like','enom_%')->orderBy('id')->get()as$t){$key='domains_'.substr($t->key,5);$definitions=ReminderTemplates::definitions();
            if(!isset($definitions[$key])){throw new \DomainException('Unknown legacy notification template.');}
            $equivalent=NotificationTemplate::where('key',$key)->first();
            foreach(['subject','body','in_app_title','in_app_body','in_app_url']as$field){$text=(string)$t->$field;preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)/',$text,$vars);
                $invoiceSafe=preg_replace('/@(if|elseif|unless)\s*\(\s*!?\s*\$invoice\s*(?:(?:===|!==|==|!=)\s*null\s*)?\)|@isset\s*\(\s*\$invoice\s*\)/','',$text);
                if(preg_match('/\$invoice\b/',$invoiceSafe)||array_diff($vars[1],['domain','expires','days','invoice','due','invoice_on','overdue','complimentary','years','amount','invoice_url','domain_url','deadline','action','renews_automatically','currency'])||str_contains($text,'->')||preg_match('/\$[A-Za-z_][A-Za-z0-9_]*\s*[)\s]*\[/',$text)||preg_match('/enom[.\\\\]/i',$text)){throw new \DomainException('Retained template requires unsupported data or legacy route.');}}
            if($equivalent){
                $p->changes['notification_templates'][$t->id]=['enabled'=>false];
                $p->changes['notification_template_collisions'][$t->id]=['legacy_key'=>$t->key,'equivalent_id'=>(int)$equivalent->id,'equivalent_key'=>$key];
            }else{$p->changes['notification_templates'][$t->id]=['key'=>$key];}
        }
        foreach($p->changes['settings']??[]as$id=>$values){foreach($values as$key=>$value){if(Server::findOrFail($id)->settings()->where('key',$key)->exists()){throw new \DomainException('Neutral server setting collision.');}}}
        foreach($p->changes['product_settings']??[]as$id=>$values){foreach($values as$key=>$value){if(Product::findOrFail($id)->settings()->where('key',$key)->exists()){throw new \DomainException('Neutral product marker collision.');}}}
        $allowed=['view','costs','secrets-metadata','manage-policy','manage'];
        foreach(Role::orderBy('id')->get()as$r){$permissions=$r->permissions??[];$new=$permissions;
            foreach($permissions as$permission){if(str_starts_with($permission,'admin.enom.')){$suffix=substr($permission,11);if(in_array($suffix,$allowed,true)){$new[]='admin.domains.'.$suffix;}}}
            if($new!==$permissions){$p->changes['roles'][$r->id]=['permissions'=>json_encode(array_values(array_unique($new)),JSON_THROW_ON_ERROR)];}}
    }
    /** The hidden generic 0.4 product after its Enom marker and sale prices were retired. */
    private static function retiredProduct(Product $product): bool
    {
        $serverId=(int)$product->server_id;$category=Category::find($product->category_id);
        if(!$serverId||$product->name!=='Domain name'||!in_array($product->slug,['domain-name','domain-name-'.$serverId],true)
            ||$product->description!=='Ordered and managed on the Domains page.'||!$product->hidden||$product->allow_quantity!=='disabled'
            ||$product->image!==null||$product->stock!==null||$product->per_user_limit!==null||$product->sort!==null||$product->email_template!==null
            ||$category?->slug!=='domains'||$product->settings()->exists()
            ||DB::table('config_option_products')->where('product_id',$product->id)->exists()
            ||DB::table('product_upgrades')->where('product_id',$product->id)->orWhere('upgrade_id',$product->id)->exists()) {return false;}
        $plans=DB::table('plans')->where('priceable_type',$product->getMorphClass())->where('priceable_id',$product->id)->get();
        if($plans->count()!==1){return false;}$plan=$plans->first();$planId=(int)$plan->id;
        if($plan->name!=='Yearly'||$plan->type!=='recurring'||(int)$plan->billing_period!==1||$plan->billing_unit!=='year'||$plan->sort!==null
            ||DB::table('settings')->where('settingable_type',(new \App\Models\Plan)->getMorphClass())->where('settingable_id',$planId)->exists()
            ||DB::table('prices')->where('plan_id',$planId)->exists()
            ||DB::table('services')->where('product_id',$product->id)->orWhere('plan_id',$planId)->exists()) {return false;}
        return true;
    }
    public static function jobs(Planner $p,bool $allowSync=false): void
    {
        $queue=config('queue.connections.'.config('queue.default'));
        if($allowSync&&is_array($queue)&&($queue['driver']??null)==='sync'){return;}
        Inputs::databaseQueue();
        $prefix='Paymenter\\Extensions\\Servers\\Enom\\Jobs\\';$known=['BillDomain'=>['domainId','domains_domains'],'ExecuteOperation'=>['operationId','domains_operations'],
            'ObserveDomain'=>['domainId','domains_domains'],'ReconcileOperation'=>['operationId','domains_operations'],'RefreshDomain'=>['domainId','domains_domains']];
        foreach(DB::table('jobs')->orderBy('id')->get()as$j){$data=json_decode($j->payload,true,64,JSON_THROW_ON_ERROR);$class=$data['data']['commandName']??'';
            if(!str_starts_with($class,$prefix)){if(str_contains($j->payload,'Enom')){throw new \DomainException('Unknown legacy queue payload.');}continue;}
            $kind=substr($class,strlen($prefix));if(!isset($known[$kind])||$j->reserved_at!==null){throw new \DomainException('Unknown/reserved legacy queue job.');}
            [$field,$table]=$known[$kind];$command=$data['data']['command']??'';
            if(!is_string($command)||!str_starts_with($command,'O:'.strlen($class).':"'.$class.'":')||preg_match_all('/s:'.strlen($field).':"'.$field.'";i:([1-9][0-9]*);/D',$command,$m)!==1||!isset($p->rows[$table][(int)$m[1][0]])){throw new \DomainException('Legacy job has no audited ID mapping.');}
            $p->changes['jobs'][$j->id]=['payload_sha256'=>hash('sha256',$j->payload),'mapped_table'=>$table,'mapped_id'=>(int)$m[1][0],'original'=>(array)$j];
        }
    }
}
