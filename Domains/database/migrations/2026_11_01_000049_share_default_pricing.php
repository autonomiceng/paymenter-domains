<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\Server;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    /** What 000048 writes, and also a rule someone may have saved. Only a row added after the mark is proven to be 000048's, so only that one never wins. */
    private const PINNED=['mode'=>'markup_percent','markup'=>'30.00','round_up'=>'none','fixed'=>['register'=>null,'renew'=>null,'transfer'=>null]];
    public function up(): void
    {
        DB::transaction(function():void {
            $mark=DB::table('settings')->whereNull('settingable_type')->whereNull('settingable_id')->where('key','domains_pricing_saved_through');
            // No mark proves nothing, and a row nobody can place stays at the price someone may have chosen.
            $through=(int)((clone $mark)->value('value')??PHP_INT_MAX);
            $this->move('domains_pricing_default_rule',static fn(object $row)=>$row->id<=$through||json_decode((string)$row->value,true)!=self::PINNED);
            $this->move('domains_card_fee',static fn()=>true);
            $mark->delete();
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
