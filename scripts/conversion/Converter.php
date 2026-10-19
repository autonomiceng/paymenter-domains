<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace DomainsConversion;
use App\Models\{Server,Product};
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Services\Installation;
final class Converter
{
    private const TARGET=['domains_accounts','domains_domains','domains_operations','domains_tlds','domains_prices','domains_sales_policies','domains_contact_profiles','domains_read_budgets','domains_observation_queues','domains_observation_work',
            'domains_reminder_schedules','domains_reminder_deliveries','domains_obligation_alerts','domains_runtime_turns','domains_runtime_operations'];
    private const ENCRYPTED=['domains_accounts'=>['stable_identity'],'domains_domains'=>['snapshot','renewal_policy','observation'],'domains_operations'=>['policy','cost','attempt','funding']];
    public function __construct(private array $evidence,private \Closure $maintenance) {}
    public function run(bool $apply=false,?string $backup=null,?\Closure $checkpoint=null): array
    {
        ($this->maintenance)();self::target();SourceSchema::transactional();
        if(Schema::hasTable('domains_conversion_runs')){
            // Resume partial DDL explicitly; never rely on its rollback.
            if($apply){Journal::install();}else{Journal::verify();}
            $run=DB::table('domains_conversion_runs')->where('id',1)->first();
            if($run?->state==='complete'){
                if(Planner::sourceDigest()!==$run->source_digest||self::resultDigest()!==$run->result_digest){throw new \DomainException('Completed conversion changed; do not replay.');}
                return [...json_decode($run->summary,true,64,JSON_THROW_ON_ERROR),'complete'=>true,'already_complete'=>true];
            }
        }
        $this->emptyTarget();
        $planner=new Planner($this->evidence);$summary=$planner->plan();
        if(!$apply){return [...$summary,'complete'=>false,'dry_run'=>true];}
        if($summary['unsupported_history']){throw new \DomainException('Unsupported terminal history requires reviewed prerequisite.');}
        if($planner->payable){throw new \DomainException('Legacy holdings have payable invoices; resolve before conversion.');}
        Inputs::backup($backup,$summary['source_digest']);$audit=$this->audit($planner);Journal::stage($planner,$summary,$audit,$checkpoint);$checkpoint?->__invoke('staged');
        ($this->maintenance)();
        DB::transaction(function()use($planner,$summary,$checkpoint,$audit):void {
            DB::table('domains_conversion_runs')->where('id',1)->lockForUpdate()->firstOrFail();
            $this->emptyTarget();if(Planner::sourceDigest()!==$summary['source_digest']){throw new \DomainException('Source changed during staging.');}
            if($this->audit($planner)!==$audit){throw new \DomainException('Native source configuration changed during staging.');}
            foreach($planner->rows as$table=>$rows){foreach($rows as$id=>$row){foreach(self::ENCRYPTED[$table]??[]as$key){
                    if(is_array($row[$key]??null)){$row[$key]=Inputs::encrypt($row[$key]);}
                    elseif($table==='domains_accounts'&&$key==='stable_identity'){$row[$key]=encrypt($row[$key],false);}}
                if($table==='domains_reminder_schedules'){$row['audit']=encrypt($row['audit']);}DB::table($table)->insert($row);}$checkpoint?->__invoke('copy:'.$table);}
            foreach(['servers','products','roles','notification_templates']as$table){foreach($planner->changes[$table]??[]as$id=>$values){DB::table($table==='servers'?(new Server)->getTable():$table)->where('id',$id)->update($values);}}
            foreach($planner->changes['settings']??[]as$id=>$settings){$s=Server::findOrFail($id);foreach($settings as$key=>$v){
                if($s->settings()->where('key',$key)->exists()){throw new \DomainException('Neutral server setting collision.');}
                $setting=$s->settings()->make(['key'=>$key]);$setting->encrypted=$v['encrypted'];$setting->type=$v['type'];$setting->value=$v['value'];$setting->save();}}
            foreach($planner->changes['product_settings']??[]as$id=>$settings){$p=Product::findOrFail($id);foreach($settings as$key=>$value){
                if($p->settings()->where('key',$key)->exists()){throw new \DomainException('Neutral product marker collision.');}$p->settings()->create(['key'=>$key,'value'=>$value,'type'=>'string','encrypted'=>false]);}}
            foreach($planner->changes['jobs']??[]as$id=>$job){$q=DB::table('jobs')->where('id',$id);$actual=$q->lockForUpdate()->first();
                if(!$actual||$actual->reserved_at!==null||hash('sha256',$actual->payload)!==$job['payload_sha256']){throw new \DomainException('Queue job changed after audit.');}$q->delete();}
            if(Planner::sourceDigest()!==$summary['source_digest']){throw new \DomainException('Retained source/native money changed.');}
            $checkpoint?->__invoke('native_written');$this->nativeReadback($planner);
            foreach($planner->rows as$table=>$rows){foreach($rows as$id=>$expected){$actual=(array)DB::table($table)->where('id',$id)->firstOrFail();
                foreach($expected as$key=>$value){$got=$actual[$key];if($table==='domains_reminder_schedules'&&$key==='audit'){$got=decrypt($got);}if(in_array($key,self::ENCRYPTED[$table]??[],true)&&$got!==null){$got=$table==='domains_accounts'?decrypt($got,false):Inputs::decrypt($got);$value=is_string($value)&&$table!=='domains_accounts'?Inputs::decrypt($value):$value;}
                    if(is_bool($value)){$got=(bool)$got;}$got=self::stored($table,$key,$got);$value=self::stored($table,$key,$value);if(($got===null)!==($value===null)||((is_array($got)||is_array($value))?$got!==$value:(string)$got!==(string)$value)){throw new \DomainException('Target readback mismatch: '.$table.'.'.$key);}}}}
            $checkpoint?->__invoke('verified');DB::table('domains_conversion_runs')->where('id',1)->update(['state'=>'complete','result_digest'=>self::resultDigest()]);
        });
        return [...$summary,'complete'=>true,'already_complete'=>false];
    }
    private function nativeReadback(Planner $plan): void
    {
        foreach(['servers','products','roles','notification_templates']as$table){foreach($plan->changes[$table]??[]as$id=>$values){
            $row=(array)DB::table($table==='servers'?(new Server)->getTable():$table)->where('id',$id)->firstOrFail();
            foreach($values as$key=>$value){$got=$row[$key];if($key==='permissions'){$got=json_decode($got,true,128,JSON_THROW_ON_ERROR);$value=json_decode($value,true,128,JSON_THROW_ON_ERROR);}
                if($got!==$value){throw new \DomainException('Native target readback mismatch.');}}
        }}
        foreach(['settings'=>Server::class,'product_settings'=>Product::class]as$kind=>$model){foreach($plan->changes[$kind]??[]as$id=>$values){
            foreach($values as$key=>$expected){$row=$model::findOrFail($id)->settings()->where('key',$key)->sole();
                if($kind==='product_settings'){$expected=['value'=>$expected,'type'=>'string','encrypted'=>false];}
                if((bool)$row->encrypted!==$expected['encrypted']||$row->type!==$expected['type']||$row->value!==$expected['value']){throw new \DomainException('Native setting readback mismatch.');}
            }
        }}
    }
    /** Compare native SQL representations without rounding or changing opaque encrypted content. */
    public static function stored(string $table,string $key,mixed $value): mixed
    {
        if($value===null){return null;}
        if(in_array($table,['domains_prices','domains_tlds','domains_operations'],true)&&in_array($key,['wholesale','retail','amount','cost_renew','cost_transfer','renew_price','markup','fixed_register','fixed_renew','fixed_transfer'],true)){
            $text=(string)$value;if(str_contains($text,'.')){$text=rtrim(rtrim($text,'0'),'.');}
            return \Paymenter\Extensions\Servers\Domains\Services\PricingRule::units($text,4);
        }
        $json=['domains_tlds'=>['cost_register_terms','sale_holds','registry_fields'],'domains_prices'=>['eligibility'],'domains_accounts'=>['capabilities']];
        if(in_array($key,$json[$table]??[],true)){
            $sort=static function($v)use(&$sort){if(!is_array($v)){return $v;}if(!array_is_list($v)){ksort($v);}return array_map($sort,$v);};
            return $sort(json_decode((string)$value,true,128,JSON_THROW_ON_ERROR));
        }
        return $value;
    }
    private static function target(): void
    {
        (new Installation)->verify();$ran=app(\Illuminate\Database\Migrations\Migrator::class)->getRepository()->getRan();
        foreach(glob(dirname(__DIR__,2).'/Domains/database/migrations/*.php')as$file){if(!in_array(basename($file,'.php'),$ran,true)){throw new \DomainException('Target migration history incomplete.');}}
    }
    public static function stageSchema(): void
    {
        $migrator=app(\Illuminate\Database\Migrations\Migrator::class);$ran=$migrator->getRepository()->getRan();
        $tables=array_filter([...self::TARGET,'domains_conversion_runs','domains_conversion_map'],fn($t)=>Schema::hasTable($t));
        $records=array_intersect($ran,array_map(fn($file)=>basename($file,'.php'),glob(dirname(__DIR__,2).'/Domains/database/migrations/*.php')));
        // Only a wholly new or fully verified installation is accepted; no partial foundation repair.
        if($tables||$records){self::target();return;}
        $migrator->run([dirname(__DIR__,2).'/Domains/database/migrations'],['force'=>true]);self::target();
    }
    private function emptyTarget(): void
    {
        foreach(self::TARGET as $t){if(DB::table($t)->exists()){throw new \DomainException('Target ledger must be empty.');}}
    }
    private function audit(Planner $p): array
    {
        $audit=['changes'=>$p->changes,'source'=>[],'before'=>[],'evidence'=>$this->evidence];foreach(['enom_domains','enom_operations','enom_tlds']as$t){$audit['source'][$t]=DB::table($t)->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();}
        foreach(['servers','products','roles','notification_templates']as$t){$audit['before'][$t]=DB::table($t==='servers'?(new Server)->getTable():$t)->whereIn('id',array_keys($p->changes[$t]??[]))->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();}foreach(['server_settings'=>[Server::class,'settings'],'product_settings'=>[Product::class,'product_settings']]as$key=>[$model,$kind]){foreach(array_keys($p->changes[$kind]??[])as$id){$audit['before'][$key][$id]=$model::findOrFail($id)->settings()->orderBy('id')->get()->map(fn($row)=>$row->getRawOriginal())->all();}}return $audit;
    }
    public static function resultDigest(): string
    {
        $rows=[];foreach([...self::TARGET,(new Server)->getTable(),'products','settings','roles','notification_templates']as$t){$rows[$t]=DB::table($t)->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();}return Inputs::digest($rows);
    }
}
