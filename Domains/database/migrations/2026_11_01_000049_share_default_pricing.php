<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\Server;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    /** What 000048 froze for accounts that never saved a rule. Nobody chose it, so it never wins. */
    private const PINNED=['mode'=>'markup_percent','markup'=>'30.00','round_up'=>'none','fixed'=>['register'=>null,'renew'=>null,'transfer'=>null]];
    public function up(): void
    {
        DB::transaction(function():void {
            $this->move('domains_pricing_default_rule',static fn(object $row)=>json_decode((string)$row->value,true)!=self::PINNED);
            $this->move('domains_card_fee',static fn()=>true);
        });
    }
    /** The most recently saved value moves to one global row; the rest go. No TLD or publication writes. */
    private function move(string $key,\Closure $chosen): void
    {
        $saved=DB::table('settings')->where('settingable_type',(new Server)->getMorphClass())->where('key',$key)->orderByDesc('updated_at')->orderByDesc('id');
        $latest=(clone $saved)->get()->first($chosen);
        $global=DB::table('settings')->whereNull('settingable_type')->whereNull('settingable_id')->where('key',$key)->exists();
        if($latest&&!$global){
            DB::table('settings')->insert(['key'=>$key,'value'=>$latest->value,'type'=>'array','encrypted'=>false,'settingable_type'=>null,'settingable_id'=>null,'created_at'=>$latest->updated_at,'updated_at'=>$latest->updated_at]);
        }
        $saved->reorder()->delete();
    }
    public function down(): void { /* Retain saved pricing choices. */ }
};
