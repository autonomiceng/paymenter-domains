<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Models\Account;
/** Whitelisted local facts only. Explicit deployment factories must be pure; never serialize saved settings. */
final class ActivationFacts
{
    public static function all(): array
    {
        $registry=app(RegistrarRegistry::class);$registered=$registry->keys();$out=[];
        // Also explain supported registrars when no deployment registry has been installed yet.
        foreach(array_unique([...$registered,'enom','namesilo']) as $key){
            $facts=[];
            if(!in_array($key,$registered,true)||!app(StaffSettingsWrites::class)->connectionAvailable($key)){
                $facts['No connection check available']=$key==='enom'
                    ?'Deployment: deliberately set domains.enom_identity.environment to test or live before extension boot to enable bounded identity and staff balance reads. This does not activate catalogue or processing.'
                    :'Provider-confirmed NameSilo identity checks are unavailable. If OTE enrollment is enabled, use Registrar setup → Enroll NameSilo OTE key on a fresh Domains server. NameSilo does not confirm account ownership; live NameSilo cannot be connected.';
            }
            if($key==='namesilo'){$facts['OTE enrollment']=NameSiloSandbox::enabled()
                ?'Enabled on this site. Use Registrar setup → Enroll NameSilo OTE key on a fresh Domains server. NameSilo does not confirm account ownership. Live NameSilo cannot be connected.'
                :'Off on this site. Ask the installation administrator whether OTE enrollment can be enabled. Live NameSilo cannot be connected.';}
            $accounts=Account::where('registrar_key',$key)->orderBy('server_id')->get();
            if($accounts->isEmpty()){$facts[$key==='namesilo'?'No enrolled account':'No verified account']=$key==='namesilo'?'No NameSilo OTE enrollment yet. See OTE enrollment above.':'Registrar setup: configure an enabled native Domains server after connection checks are available. Keep retained account identities unchanged.';}
            $out[$key]['Registrar checks']=$facts?:['Connection review required'=>'Connection checks are available locally; verify the original account in Registrar setup. This is not a successful provider check.'];
            foreach($accounts as $a){$out[$key]['Server #'.$a->server_id]=self::account($a);}
            $out[$key]['Deployment and request checks']=self::reviews();
        }
        return $out;
    }
    private static function account(Account $a): array
    {
        $facts=[];$status=app(AccountBindings::class)->configurationStatus((int)$a->server_id);
        if($status!=='matched'){$facts[match($status){
            'server_disabled'=>'Native server off','server_unavailable'=>'Native server unavailable',
            'site_invalid'=>'Live site URL invalid','site_unbound'=>'Live site not accepted','site_mismatch'=>'Live site mismatch',
            'not_configured'=>'Account not configured',default=>'Account configuration unavailable'}]=
                $a->registrar_key==='namesilo'?(NameSiloSandbox::enrolled($a)?'Servers / Registrar setup: re-enable this server if it is off. Restore its original OTE enrollment facts; OTE enrollment must stay enabled on this site (installation administrator). A new key requires a fresh Domains server. Account ownership is unconfirmed.':'No readable OTE enrollment for this NameSilo binding. Keep its retained facts unchanged; if it was enrolled, restore its original saved settings. A new key requires a fresh Domains server; this binding’s holdings, acceptances and operations do not carry over. Live NameSilo cannot be connected.'):'Servers / Registrar setup: restore the enabled server and original verified credentials. Live accounts also require trusted site acceptance for the exact canonical HTTPS application URL; staff cannot provision that acceptance.';}
        if(!$a->processing_enabled){$facts['Processing off']='Registrar setup → Processing: requires current staff review, complete account-bound runtime ports and shared workers. Enabling a native server alone does not enable processing.';}
        $s=Server::find($a->server_id);$settings=$s?->settings()->whereIn('key',['domains_billing_enabled','domains_manual_renewals_enabled'])->pluck('value','key');
        foreach(['domains_billing_enabled'=>'Billing off','domains_manual_renewals_enabled'=>'Manual renewal billing off'] as $setting=>$label){
            if(!filter_var($settings?->get($setting,false),FILTER_VALIDATE_BOOLEAN)){$facts[$label]='Deployment: independently approve issuance and set native server setting '.$setting.' to true (no staff form; default off). Display fixtures cannot authorize billing. A completed acquisition or adoption anchor, active native authority and the invoice calendar are still required.';}
        }
        try{CatalogueAcceptances::load(CatalogueAcceptances::binding($a));}
        catch(\Throwable){$facts['No accepted catalogue']='Registrar setup → Catalogue acceptance: independently verify exact USD terms, registry fields and safe annual renewal, then import prices. In TLDs, review action flags, pricing, selected seller and publication.';}
        $registered=app(RuntimePorts::class)->registered($a);
        $facts[$registered?'Runtime composition review required':'Runtime ports unavailable']='Deployment: supply account-bound acquisition, reconciliation, renewal execution/writer/completion and management engine/protocol. Registered factories alone do not prove provider acceptance.';
        foreach(app(RuntimePorts::class)->acquisitionGates($a) as $action=>$enabled){
            $label=$action==='register'?'Registration':'Transfer';
            $facts[$label.match($enabled){null=>' composition unavailable',false=>' feature off',true=>' composition review required'}]=match($enabled){
                null=>'Deployment: supply a complete account-bound runtime composition with all seven roles, including an action selector with separate registration and transfer preflights, bridges and protocols. Keep processing off until the composition and provider path have been independently reviewed.',
                false=>'Deployment: '.$label.' is off in this account’s WriteFeatures. Independently accept the '.strtolower($label).' provider path before enabling it; enabling it does not prove provider acceptance.',
                true=>'Local only: the account-bound '.strtolower($label).' composition resolves and its WriteFeatures flag is on. This is not provider acceptance or processing authority. Enabled processing, current catalogue acceptance, contact and nameserver setup, a fresh preflight, verified funding, current ownership, resolved prior outcomes and one-use dispatch authority are still required.'};
        }
        foreach(['Renewal','Management'] as $action){$facts[$action.($registered?' writes unverified':' writes off')]='Deployment: independently accept and enable the '.$action.' feature in this account’s WriteFeatures. No saved server switch proves this permission.';}
        return $facts;
    }
    private static function reviews(): array
    {
        return [
            'Installation and retained authority review'=>'Verify native installation and conversion history; pause legacy registrar processing. Restore retained ledgers rather than rebinding them.',
            'Shared worker review'=>'Use atomic Redis/database/memcached cache and sessions, an asynchronous Redis/database queue with retry_after ≥ 900 seconds, worker timeout > 840 and < retry_after, and a running scheduler. Review failed jobs before retrying.',
            'Write acceptance and transport review'=>'Verify USD balance, batch route, exact terms and a 25–86400 second account interval. Require cURL/TLS/CA support, TLS key logging disabled, fixed HTTPS origins and bounded deadlines and bodies.',
            'Contact and setup review'=>'Require approved contact creation with exact non-default profile readback, fresh proof shared by preflight and protocol, valid frozen contacts, registry fields and nameservers. Transfer contact recovery needs explicit review; stopped unsent input cannot be reopened implicitly.',
            'Checkout and funding checks'=>'Require a fresh eligibility token, current seller and native mapping, one name in USD with no options, and the exact fully paid native invoice line. Display fixtures and offline provenance cannot fund work.',
            'Transfer policy and code checks'=>'Verify accepted transfer endings, one-year terms, provider transferability and RDAP age/lock policy. The owner submits the code via POST. After 30 days, only definitely-unsent missing-code work can time out and credit once.',
            'Prior outcome and history checks'=>'Validate current claim, seal, funding and generation. Reconcile possibly sent work using accepted action-specific history, unique original charge and owned expiry; at most five covered polls. Never replay an unresolved purchase.',
            'Renewal and management acceptance review'=>'Independently accept renewal USD/route/interval and any grace capability. Require owned expiry, known cost and registrar balance. Management needs accepted engine/protocol, active authority and complete readback; DNS tools require their own setting. Unsupported capabilities remain unavailable.',
            'Reminder delivery review'=>'Registrar setup: independently approve the optional reminder schedule and verify native channels, templates and preferences. Required paid-code and account-credit notices remain active; uncertain handoffs need staff inspection.',
            'Public checks and watch delivery review'=>'Deployment: accept discovery, enabled profiles and native product mappings, valid public suffix baseline, and a shared asynchronous public-check queue with retry_after ≥ 180. Review DNS/registry helpers and watch notice planning/delivery separately. Config flags alone cannot enable these paths.',
            'Adoption and transfer watch review'=>'Deployment config domains.adoption.enabled and domains.watch_transfer.enabled are separate default-off features. Verify native prerequisites, ownership, provenance and reviewed adoption proof before enabling either.',
        ];
    }
}
