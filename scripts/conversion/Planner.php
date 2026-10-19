<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace DomainsConversion;
use App\Models\{Server,Service,Invoice,InvoiceItem,Product,User};
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,CanonicalName,Environment};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{ReadCommand,ResponseParser};
use Paymenter\Extensions\Servers\Domains\Services\{PricingRule,ReminderSchedules};
/** Builds a deterministic cleartext plan in memory; only aggregate counts leave this process. */
final class Planner
{
    public array $rows=[];public array $changes=[];public array $bindings=[];public array $unknown=[];public array $pending=[];public array $payable=[];public array $unsupported=[];
    public function __construct(private array $evidence,private string $hostProfile='dedicated') {}
    public static function sourceDigest(): string
    {
        $rows=[];foreach(['enom_domains','enom_operations','enom_tlds','services','plans','prices','invoices','invoice_items','invoice_transactions','credits','properties','service_cancellations','notification_preferences','failed_jobs']as$t){
            if(Schema::hasTable($t)){$rows[$t]=DB::table($t)->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();}}
        return Inputs::digest($rows);
    }
    public function plan(): array
    {
        SourceSchema::verify();$digest=self::sourceDigest();$names=[];$domains=DB::table('enom_domains')->orderBy('id')->get()->keyBy('id');
        foreach(Server::where('extension','Enom')->orderBy('id')->get()as$s){$this->account($s,$domains);}
        foreach(DB::table('enom_tlds')->orderBy('id')->get()as$t){$t=(array)$t;$b=$this->bindings[$t['server_id']]['binding']??throw new \DomainException('Unbound catalogue.');
            $this->put('domains_tlds',$t['id'],[...$t,'registrar_key'=>'enom','account_fingerprint'=>$b->fingerprint,'enabled'=>false,'register_enabled'=>false,'transfer_enabled'=>false]);
            if(isset($names['tld:'.$t['tld']])){throw new \DomainException('Choose one legacy seller per ending before conversion.');}$names['tld:'.$t['tld']]=true;
            $this->put('domains_sales_policies',$t['id'],['id'=>$t['id'],'tld'=>$t['tld'],'server_id'=>$t['server_id'],'registrar_key'=>'enom','account_fingerprint'=>$b->fingerprint,'revision'=>1,'register_enabled'=>false,'transfer_enabled'=>false]);
            $this->prices($t,$b);
        }
        foreach($domains as $d){$d=(array)$d;$canonical=CanonicalName::parse($d['domain'],[$d['tld']]);
            if($canonical['domain']!==$d['domain']||$canonical['sld']!==$d['sld']||isset($names[$canonical['domain']])||($d['reservation_name']!==null&&$d['reservation_name']!==$d['domain'])){throw new \DomainException('Duplicate or invalid canonical reservation.');}$names[$d['domain']]=true;
            $a=$this->bindings[$d['server_id']]??throw new \DomainException('Unbound holding.');$b=$a['binding'];
            $service=Service::findOrFail($d['service_id']);$p=$service->product;$plan=$service->plan;
            if(!$p||!$plan||(int)$service->user_id!==(int)$d['user_id']||!User::find($d['user_id'])||(int)$p->server_id!==(int)$d['server_id']
                ||(int)$service->order?->user_id!==(int)$d['user_id']||$service->expires_at!==null||$service->currency_code!=='USD'||(string)$service->quantity!=='1'||$plan->priceable_type!==$p->getMorphClass()
                ||(int)$plan->priceable_id!==(int)$p->id||$plan->type!=='recurring'||$plan->billing_unit!=='year'||(int)$plan->billing_period!==1){throw new \DomainException('Native holding reference/annual plan mismatch.');}
            $tld=DB::table('enom_tlds')->where('server_id',$d['server_id'])->where('tld',$d['tld'])->first();$markers=$p->settings->pluck('value','key');
            if(!$tld||$markers->get('enom_catalogue')!=='1'||(string)$markers->get('enom_tld_id')!==(string)$tld->id||!in_array($markers->get('enom_action'),['register','transfer'],true)){throw new \DomainException('Holding product ending/action mismatch.');}
            if($service->configs()->exists()||DB::table('product_upgrades')->where('product_id',$p->id)->orWhere('upgrade_id',$p->id)->exists()||DB::table('config_option_products')->where('product_id',$p->id)->exists()||$service->properties()->where('key','domain')->count()!==1||$service->properties()->where('key','domain')->value('value')!==$d['domain']){throw new \DomainException('Native holding configuration/property mismatch.');}
            $policy=Inputs::decrypt($d['renewal_policy']);$settings=$service->product->server->settings->pluck('value','key');
            foreach(['issue_days'=>21,'due_days'=>7,'credits_auto_use'=>false]as$key=>$default){if(array_key_exists($key,$policy)&&(string)$policy[$key]!==(string)$settings->get($key,$default)){throw new \DomainException('Saved per-domain billing policy needs a reviewed prerequisite.');}}
            if(($policy['enabled']??null)===false){$policy['automatic_spend_paused']=true;}$obs=Inputs::decrypt($d['observation']);$snapshot=Inputs::decrypt($d['snapshot']);
            $ops=DB::table('enom_operations')->where('domain_id',$d['id'])->orderBy('id')->get();$generation=(int)$d['authority_generation']+1;
            if((int)$d['authority_generation']!==0){throw new \DomainException('Unsupported source authority epoch.');}
            Mapper::embedded($policy,$d,$a['old']);Mapper::embedded($obs,$d,$a['old']);
            $acquisition=$ops->first(fn($o)=>$o->action==='register'&&$this->purchase((array)$o,$d,$a));
            $managed=(bool)$d['billing_managed']&&$acquisition!==null&&$markers->get('enom_action')==='register';$latest=null;$mapped=[];
            foreach($ops as$o){$o=(array)$o;$row=Mapper::operation($o,$d,$a,$generation);$mapped[$o['id']]=$row;
                if($managed&&in_array($o['state'],['succeeded','rejected'],true)&&!$this->completion($o,[...$d,'provider_expires_at'=>$o['confirmed_expires_at']],$a)){$this->unsupported[]=['id'=>$o['id'],'state'=>$o['state'],'action'=>$o['action']];}
                if(!in_array($o['state'],['succeeded','rejected'],true)){$this->unknown[]=['id'=>$o['id'],'state'=>$o['state'],'action'=>$o['action']];}
                if($managed&&$this->completion($o,$d,$a)){$latest=$o;}}
            if($managed&&!$latest){$managed=false;}
            if($managed&&$d['reservation_name']!==$d['domain']){throw new \DomainException('Managed purchase has no retained reservation.');}
            if($managed){$read=$this->read($d,$a);if($read['id']!==$d['provider_domain_id']||$read['expiry']!==$d['provider_expires_at']){throw new \DomainException('Accepted holding read contradicts confirmed source anchor.');}
                $snapshot['checkout']=['user_id'=>(int)$d['user_id'],'service_id'=>(int)$service->id,'order_id'=>(int)$service->order_id,
                    'product_id'=>(int)$p->id,'annual_plan_id'=>(int)$plan->id,'name'=>$d['domain'],'action'=>'register'];
                $policy['billing_anchor']=['operation_key'=>$latest['operation_key'],'expiry'=>$d['provider_expires_at']];
                foreach($ops as$o){$o=(array)$o;if(!$this->completion($o,[...$d,'provider_expires_at'=>$o['confirmed_expires_at']],$a)){continue;}
                    $data=['revision'=>Inputs::selectedRevision(),'source_digest'=>$digest,'accepted_read_sha256'=>hash('sha256',$this->evidence['domains'][(string)$d['id']]['xml']),
                        'projection'=>[(int)$o['id'],$o['operation_key'],$o['action'],(int)$o['term'],(int)$d['id'],$d['domain'],(int)$d['service_id'],(int)$d['server_id'],
                            $b->fingerprint,$generation,$d['provider_domain_id'],$o['provider_order_id'],$o['confirmed_expires_at'],Inputs::decrypt($o['cost'])['actual']??null,$mapped[$o['id']]['attempt']['authorized_cost']??null]];
                    $mapped[$o['id']]['attempt']['converted_completion']=['data'=>$data,'mac'=>hash_hmac('sha256','domains-conversion-v1:'.json_encode($data,JSON_THROW_ON_ERROR),(string)config('app.key'))];}
            }else{$this->pending[]=$d['id'];
                $policy['conversion_source_expiry']=$d['provider_expires_at'];$policy['conversion_hold_reason']=!(bool)$d['billing_managed']?'billing_not_managed':'purchase_unverified';$d['status']='adoption_pending';$d['provider_expires_at']=null;$d['confirmed_revision']=0;unset($policy['billing_anchor']);}
                foreach(Invoice::where('status','pending')->where(fn($q)=>$q->whereIn('id',$ops->pluck('invoice_id')->filter())->orWhereIn('id',InvoiceItem::where('reference_type',$service->getMorphClass())->where('reference_id',$service->id)->select('invoice_id')))->orderBy('id')->pluck('id')as$id){$this->payable[]=['domain_id'=>$d['id'],'invoice_id'=>$id];}
            foreach($mapped as$id=>$row){$this->put('domains_operations',$id,$row);}
            $sent=json_decode($d['reminders']??'{}',true,32,JSON_THROW_ON_ERROR);if(!is_array($sent)){throw new \DomainException('Malformed retained reminder claims.');}
            foreach($sent as$expiry=>$offsets){$date=\DateTimeImmutable::createFromFormat('!Y-m-d',(string)$expiry);if(!$date||$date->format('Y-m-d')!==$expiry||!is_array($offsets)){throw new \DomainException('Malformed retained reminder claims.');}
                foreach(array_unique($offsets)as$offset){if(!is_int($offset)||$offset===0||abs($offset)>365){throw new \DomainException('Malformed retained reminder offset.');}$id=count($this->rows['domains_reminder_deliveries']??[])+1;
                    $this->put('domains_reminder_deliveries',$id,['id'=>$id,'domain_id'=>$d['id'],'delivery_key'=>hash('sha256',$d['id'].':renew:'.$generation.':'.$expiry.':'.$offset),'template'=>$offset<0?'domains_domain_expired':'domains_domain_expiring','state'=>'suppressed','created_at'=>0,'accepted_at'=>null]);}}
            $this->put('domains_domains',$d['id'],[...$d,'registrar_key'=>'enom','account_fingerprint'=>$b->fingerprint,'authority_generation'=>$generation,'billing_managed'=>$managed,
                'provenance'=>$managed?'converted_purchase':'adoption_pending','renewal_policy'=>$policy,'observation'=>$obs,'snapshot'=>$managed?$snapshot:$d['snapshot']]);
        }
        // Orphan operations must not disappear behind domain iteration.
        if(count($this->rows['domains_operations']??[])!==DB::table('enom_operations')->count()){throw new \DomainException('Orphan source operation.');}
        Mapper::native($this);Mapper::jobs($this,$this->hostProfile==='app');ksort($this->rows);$counts=array_map('count',$this->rows);
        $expiryReview=array_map(fn($id)=>['domain_id'=>$id,'recorded_expiry'=>$domains[$id]->provider_expires_at,'within_60_days'=>$domains[$id]->provider_expires_at===null||$domains[$id]->provider_expires_at<=(new \DateTimeImmutable('+60 days'))->format('Y-m-d')],$this->pending);
        usort($expiryReview,fn($a,$b)=>[$a['recorded_expiry']??'',$a['domain_id']]<=>[$b['recorded_expiry']??'',$b['domain_id']]);
        return ['source_revision'=>Inputs::selectedRevision(),'source_digest'=>$digest,'plan_digest'=>Inputs::digest([$this->rows,$this->changes]),'counts'=>$counts,'adoption_pending'=>$this->pending,'unknown_operations'=>$this->unknown,'unsupported_history'=>$this->unsupported,'pending_payable_invoices'=>$this->payable,'pending_expiry_review'=>$expiryReview,'queue_removals'=>count($this->changes['jobs']??[])];
    }
    private function account(Server $s,$domains): void
    {
        $settings=$s->settings;$v=$settings->pluck('value','key');$uid=$v->get('uid');$token=$v->get('token');$env=$v->get('environment','test');
        if(!is_string($uid)||trim($uid)!==$uid||$uid===''||!is_string($token)||$token===''||!$settings->firstWhere('key','uid')?->encrypted||!$settings->firstWhere('key','token')?->encrypted||!in_array($env,['test','live'],true)){throw new \DomainException('Encrypted bound source credentials required.');}
        $old=hash('sha256',$env.':'.strtolower($uid));if($v->get('enom_account_binding')!==$old){throw new \DomainException('Source account binding mismatch.');}
        if($env==='live'&&$v->get('enom_live_url')!==rtrim((string)config('app.url'),'/')){throw new \DomainException('Source live-site binding mismatch.');}
        $a=$this->evidence['accounts'][(string)$s->id]??[];
        $actor=User::find($a['accepted_by']??0);
        if(!$actor||!$actor->role||!$actor->hasPermission('admin.enom.view')||!$actor->hasPermission('admin.enom.manage-policy')||($a['source']??null)!=='authenticated_provider_read'||($a['uid']??null)!==strtolower($uid)||($a['environment']??null)!==$env
            ||($a['credential_digest']??null)!==Inputs::digest([$uid,$token])||!preg_match('/^[a-z][a-z0-9_]{4,63}$/D',$a['reason']??'')
            ||!is_int($a['accepted_at']??null)||$a['accepted_at']>time()||$a['accepted_at']<time()-86400){throw new \DomainException('Independent accepted provider account evidence required.');}
        $d=$domains->first(fn($d)=>(int)$d->server_id===(int)$s->id&&(int)$d->id===($a['domain_id']??null));
        if(!$d){throw new \DomainException('Accepted account read has no source holding.');}
        $b=new AccountBinding('enom',(int)$s->id,Environment::from($env),$uid);$entry=['binding'=>$b,'old'=>$old,'uid'=>strtolower($uid)];$this->bindings[$s->id]=$entry;$this->read((array)$d,$entry);
        $this->put('domains_accounts',$s->id,['id'=>$s->id,'server_id'=>$s->id,'registrar_key'=>'enom','account_fingerprint'=>$b->fingerprint,'identity_fingerprint'=>Inputs::digest(['enom',$env,strtolower($uid)]),
            'environment'=>$env,'stable_identity'=>strtolower($uid),'live_site'=>$env==='live'?rtrim((string)config('app.url'),'/'):null,'processing_enabled'=>false]);
    }
    private function read(array $d,array $a): array
    {
        $e=$this->evidence['domains'][(string)$d['id']]??[];$xml=$e['xml']??'';
        $r=(new ResponseParser)->parse(ReadCommand::Info,is_string($xml)?$xml:'');$i=$r->value('getdomaininfo',[]);$n=$i['domainname']??null;$s=$i['status']??[];
        $name=is_array($n)?($n['#text']??null):$n;$id=is_array($n)?($n['@attributes']['domainnameid']??null):($i['domainnameid']??null);$owner=$s['belongs-to']??null;
        if(is_array($owner)){$owner=$owner['#text']??null;}$expiry=$s['expiration']??null;
        if($r->status!=='success'||$name!==$d['domain']||!is_string($owner)||strtolower($owner)!==$a['uid']||!is_string($id)||!preg_match('/^[1-9][0-9]{0,19}$/D',$id)
            ||(isset($i['domainnameid'])&&$i['domainnameid']!==$id)||!is_string($expiry)||!preg_match('/^\d{4}-\d\d-\d\d$/D',$expiry)
            ||($e['account_fingerprint']??null)!==$a['old']){throw new \DomainException('Positive offline ownership/identity read required.');}
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$expiry);if(!$date||$date->format('Y-m-d')!==$expiry){throw new \DomainException('Precise accepted expiry required.');}
        return ['id'=>$id,'expiry'=>$expiry];
    }
    private function purchase(array $o,array $d,array $a): bool
    {
        $p=Inputs::decrypt($o['policy']);$i=Invoice::find($o['invoice_id']);$item=InvoiceItem::find($o['invoice_item_id']);$s=Service::find($d['service_id']);$plan=\App\Models\Plan::find($p['checkout_plan_id']??0);
        return $o['action']==='register'&&$this->completion($o,[...$d,'provider_expires_at'=>$o['confirmed_expires_at']],$a)&&$i&&$item&&$i->status==='paid'&&$i->currency_code==='USD'
            &&(int)$i->user_id===(int)$d['user_id']&&(int)$item->invoice_id===(int)$i->id&&$item->reference_type===$s->getMorphClass()&&(int)$item->reference_id===(int)$s->id
            &&$o['operation_key']==='invoice-item:'.$item->id&&(string)$item->quantity==='1'&&PricingRule::minor($item->price)>0&&PricingRule::minor($item->price)===PricingRule::minor($o['amount'])
            &&($p['billing_managed']??null)===true&&($p['account_fingerprint']??null)===$a['old']&&($p['checkout_product_id']??null)===(int)$s->product_id
            &&$plan&&$plan->priceable_type===$s->product->getMorphClass()&&(int)$plan->priceable_id===(int)$s->product_id&&$plan->type==='recurring'&&$plan->billing_unit==='year'&&(int)$plan->billing_period===(int)$o['term']&&($p['retail_minor']??null)===PricingRule::minor($o['amount'])&&($p['checkout_years']??null)===(int)$o['term']&&$this->paid($i);
    }
    private function paid(Invoice $invoice): bool
    {
        $total=0;$paid=0;foreach($invoice->items as $item){$total+=PricingRule::minor($item->price)*(int)$item->quantity;}
        foreach($invoice->transactions as $tx){if($tx->status===\App\Enums\InvoiceTransactionStatus::Succeeded){$paid+=PricingRule::minor($tx->amount);}}
        return $total>0&&$paid>=$total;
    }
    private function completion(array $o,array $d,array $a): bool
    {
        $p=Inputs::decrypt($o['policy']);$cost=Inputs::decrypt($o['cost']);$c=$cost['actual']??[];$x=$cost['execution']??[];
        return ($o['action']!=='renew'||Mapper::renewalCost($o,$d,$a)!==null)&&$o['state']==='succeeded'&&in_array($o['action'],['register','renew'],true)&&$o['confirmed_expires_at']!==null&&$o['confirmed_expires_at']===$d['provider_expires_at']
            &&is_string($d['provider_domain_id'])&&preg_match('/^[1-9][0-9]{0,19}$/D',$d['provider_domain_id'])&&$o['provider_order_id']!==null
            &&($p['account_fingerprint']??null)===$a['old']&&($p['authority_generation']??null)===(int)$d['authority_generation']
            &&($c['state']??null)==='confirmed'&&($c['source']??null)==='GetOrderDetail'&&($c['provider_order_id']??null)===$o['provider_order_id']
            &&($c['domain']??null)===$d['domain']&&($c['action']??null)===$o['action']&&($c['term']??null)===(int)$o['term']&&($c['currency']??null)==='USD'
            &&($c['currency_source']??null)==='documented_orderbillamount_usd'&&is_string($c['amount']??null)&&PricingRule::units($c['amount'],4)>0
            &&($x['status']??null)==='success'&&($x['command']??null)===($o['action']==='register'?'Purchase':'Extend')&&($x['orderid']??null)===$o['provider_order_id']
            &&is_string($p['dispatch_attempted_at']??null)&&$o['started_at']!==null&&$o['completed_at']!==null&&(int)$o['service_id']===(int)$d['service_id'];
    }
    private function prices(array $t,AccountBinding $b): void
    {
        $v=Server::findOrFail($t['server_id'])->settings->pluck('value','key');$rule=$t['use_default_rule']?($v->get('pricing_default_rule',PricingRule::DEFAULT)):
            ['mode'=>$t['pricing_mode'],'markup'=>$t['markup']??'0','round_up'=>$t['round_up'],'fixed'=>['register'=>$t['fixed_register'],'renew'=>$t['fixed_renew'],'transfer'=>$t['fixed_transfer']]];
        $rule=PricingRule::validate($rule);$terms=json_decode($t['cost_register_terms']??'{}',true,32,JSON_THROW_ON_ERROR);
        foreach(['register','renew','transfer']as$action){foreach($action==='register'?$terms:[1=>$t['cost_'.$action]]as$term=>$cost){
            if(!is_int($term)||$term<1||$term>10){throw new \DomainException('Invalid source exact price term.');}$cost=$cost===null?null:PricingRule::wholesale((string)$cost);
            $retail=$action==='renew'?$t['renew_price']:($cost===null?null:PricingRule::price($cost,$rule,$action));$wholesale=$cost===null?null:PricingRule::decimal(PricingRule::units($cost,4)*$term,4);
            $id=count($this->rows['domains_prices']??[])+1;$this->put('domains_prices',$id,['id'=>$id,'server_id'=>$t['server_id'],'registrar_key'=>'enom','account_fingerprint'=>$b->fingerprint,
                'tld'=>$t['tld'],'action'=>$action,'term'=>$term,'currency'=>'USD','revision'=>1,'wholesale'=>$wholesale,'retail'=>$retail===null?null:PricingRule::total((string)$retail,$term),
                'unit'=>'term_total','source'=>'enom_05_conversion','observed_at'=>$t['costs_synced_at']??'1970-01-01 00:00:00','fresh_until'=>null,
                'eligibility'=>json_encode(['converted'=>true]),'hold_reason'=>'conversion_review']);}}
    }
    public function put(string $table,int $id,array $row): void {$this->rows[$table][$id]=$row;}
}
