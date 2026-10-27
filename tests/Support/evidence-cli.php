<?php
// Synthetic CLI transport and disposable source seed. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace DomainsConversion {
    function curl_init(){return new \stdClass;}
    function curl_setopt_array($handle,$options){$GLOBALS['evidence_cli_options']=$options;return true;}
    function curl_exec($handle){
        $o=$GLOBALS['evidence_cli_options'];parse_str($o[CURLOPT_POSTFIELDS],$fields);
        file_put_contents(getenv('EVIDENCE_WIRE'),json_encode([$o[CURLOPT_URL],$fields['Command'],$fields['SLD']])."\n",FILE_APPEND);
        $body='<interface-response><Command>GetDomainInfo</Command><Done>true</Done><ErrCount>0</ErrCount><GetDomainInfo><DomainName DomainNameID="121">purchased-placeholder.com</DomainName><status><belongs-to>syntheticuid</belongs-to><expiration>2028-10-18</expiration></status></GetDomainInfo></interface-response>';
        return $o[CURLOPT_WRITEFUNCTION]($handle,$body)===strlen($body);
    }
    function curl_getinfo($handle,$option){return 200;}
    function curl_close($handle){}
}
namespace {
if(realpath($_SERVER['SCRIPT_FILENAME'])===__FILE__){
    require __DIR__.'/conversion.php';
    $pdo=\Illuminate\Support\Facades\DB::connection()->getPdo();
    $pdo->exec('VACUUM INTO '.$pdo->quote($argv[1].'/test.sqlite'));
    $binding=hash('sha256','live:'.strtolower($uid));
    foreach(['environment'=>'live','enom_account_binding'=>$binding,'enom_live_url'=>rtrim(config('app.url'),'/')]as$key=>$value){
        $s=$server->settings()->firstOrNew(['key'=>$key]);$s->encrypted=false;$s->type='string';$s->value=$value;$s->save();}
    foreach(\Illuminate\Support\Facades\DB::table('enom_operations')->get()as$op){
        $p=\DomainsConversion\Inputs::decrypt($op->policy);$p['account_fingerprint']=$binding;
        $c=\DomainsConversion\Inputs::decrypt($op->cost);if(isset($c['quote'])){$c['quote']['account_fingerprint']=$binding;}
        \Illuminate\Support\Facades\DB::table('enom_operations')->where('id',$op->id)->update(['policy'=>\DomainsConversion\Inputs::encrypt($p),'cost'=>\DomainsConversion\Inputs::encrypt($c)]);}
    file_put_contents($argv[1].'/staff.json',json_encode(['staff'=>$staff->id]));
    $pdo->exec('VACUUM INTO '.$pdo->quote($argv[1].'/live.sqlite'));$nativeSuccessful=true;
}
}
