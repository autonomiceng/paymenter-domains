<?php
// Enom 444f31c RegistrationSetupController/TransferCodeController/DomainController; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Http\Controllers;
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Http\{FormKeys,RawPost};
use Paymenter\Extensions\Servers\Domains\Services\{ContactRecovery, DomainManagement, OwnedDomains, OwnerPorts, OwnerSetup, TransferInputs};
/** Dormant owner POST boundary. Never calls a driver write or queues a purchase. */
final readonly class OwnerActionController
{
    public const ACTIONS=['nameservers','contacts','contact_profile','lock','privacy_enable','privacy_disable','privacy_purchase','privacy_renew','auto_renew_off','auth_code_email','dns','srv','ds_add','ds_delete','glue_add','glue_update','glue_delete'];
    public function __construct(private OwnedDomains $owners, private OwnerSetup $setup, private TransferInputs $inputs,
        private DomainManagement $management, private OwnerPorts $ports) {}
    public function code(#[\SensitiveParameter] Request $request,int $domain)
    {
        $query=$request->query->has('transfer_code');
        try { $code=RawPost::body($request)['transfer_code']??null; }
        catch (\DomainException) { $code=null; }
        $request->request->remove('transfer_code'); $request->query->remove('transfer_code');
        if ($request->isJson()) { $request->json()->remove('transfer_code'); }
        $request->session()->forget('_old_input.transfer_code');
        $d=$this->owners->owned($request->user(),$domain); $op=$this->owners->operation($d);
        $context='owner:'.$domain.':code'; FormKeys::verify($request,$context);
        if ($query||!TransferInputs::validCode($code)) {
            return redirect()->route('domains.domains.code',$domain)->withErrors(['transfer_code'=>'Enter a valid transfer code.']);
        }
        FormKeys::consume($request,$context);
        try {
            $body=Request::create('/','POST',['transfer_code'=>$code]); $body->setUserResolver(fn()=>$request->user());
            $this->inputs->submitCode($body,$op?->id??0);
            return redirect()->route('domains.domains.code',$domain)->with('domains_status','Code received. The transfer starts once payment and your details are confirmed.');
        } catch (\Throwable) { return redirect()->route('domains.domains.code',$domain)->withErrors(['transfer_code'=>'We couldn’t save the code. Try again.']); }
    }
    public function setup(Request $request,int $domain)
    {
        return $this->submit($request,$domain,'setup',function()use($request,$domain):void {
            if ($request->request->get('confirmed')!=='1') { throw new \DomainException; }
            $servers=$request->request->get('nameservers','');
            if (!is_string($servers)||!in_array($request->request->get('privacy','0'),['0','1'],true)) { throw new \DomainException; }
            $this->setup->save($request->user(),$domain,['nameservers'=>self::servers($servers),'privacy'=>$request->request->get('privacy')==='1']);
        },'Domain setup saved.');
    }
    public function contacts(Request $request,int $domain)
    {
        return $this->submit($request,$domain,'recovery',function()use($request,$domain):void {
            $op=$this->owners->operation($this->owners->owned($request->user(),$domain));
            ContactRecovery::replace($request->user(),$op?->id??0,'owner_updated_account_details');
        },'Account details updated.');
    }
    public function manage(Request $request,int $domain,string $action)
    {
        abort_unless(in_array($action,self::ACTIONS,true),404);
        return $this->submit($request,$domain,$action,function($nonce)use($request,$domain,$action):void {
            $d=$this->owners->owned($request->user(),$domain); $p=$request->request->all();
            if (in_array($action,['dns','srv'],true)) {
                $p=RawPost::body($request);
                // The fixed-field draft uses these same raw records after validation errors.
                $request->request->set('records',$p['records']??[]);
            }
            $current=in_array($action,['dns','srv'],true)?(\Paymenter\Extensions\Servers\Domains\Services\OwnerRecords::forDomain($d)[$action]??throw new \DomainException('Current records required.')):null;
            $payload=match($action) {
                'nameservers'=>($p['use_default']??null)==='1' && $d->registrar_key==='enom'
                    ? ['nameservers'=>[],'use_default'=>true] : ['nameservers'=>self::servers(is_string($p['nameservers']??null)?$p['nameservers']:'')],
                'lock'=>['locked'=>match($p['locked']??null){'1'=>true,'0'=>false,default=>throw new \DomainException}],
                'contacts','contact_profile'=>['role'=>$p['role']??'all','contact'=>$p['contact']??[]],
                'dns','srv','ds_add','ds_delete','glue_add','glue_update','glue_delete'=>\Paymenter\Extensions\Servers\Domains\Services\OwnerRecordInput::payload($action,$p,$d->domain),
                default=>[],
            };
            if ($action==='srv') { $payload=\Paymenter\Extensions\Servers\Domains\Services\OwnerRecords::restoreSrv($d,$payload); }
            if ($action==='nameservers') {
                try { $this->ports->forDomain($d)->validate($action,$payload,$d->domain); }
                catch (\Throwable) { throw new \Paymenter\Extensions\Servers\Domains\Services\OwnerFormValidation(['nameservers']); }
            }
            // Actions that can take a site offline or move the domain need the confirm step's field; plain edits do not.
            $risky=match($action) {
                'nameservers','auth_code_email','auto_renew_off','ds_delete','glue_delete'=>true,
                'lock'=>$payload['locked']===false,
                'dns','srv'=>count($payload['records'])<count($current),
                default=>false,
            };
            if ($risky&&($p['confirmed']??null)!=='1') { throw new \Paymenter\Extensions\Servers\Domains\Services\OwnerConfirmRequired; }
            \Paymenter\Extensions\Servers\Domains\Services\Runtime::management($d,$this->management)->request($request->user(),$domain,$action,$payload,$nonce,$this->ports->forDomain($d),recordIdentity:in_array($action,['dns','srv'],true)?\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::identity($d):null);
        },'Change requested. Check Activity for updates.');
    }
    private function submit(Request $request,int $domain,string $action,\Closure $work,string $message)
    {
        $this->owners->owned($request->user(),$domain); $context='owner:'.$domain.':'.$action;
        $nonce=FormKeys::verify($request,$context); FormKeys::consume($request,$context);
        try { $work($nonce); return redirect()->route('domains.domains.show',$domain)->with('domains_status',$message); }
        catch (\Paymenter\Extensions\Servers\Domains\Services\OwnerConfirmRequired) {
            // Without script the confirm step renders inline on the same page, with the submitted values kept.
            $view=(new OwnedDomainController($this->owners))->show($request,$domain);
            $dto=\Paymenter\Extensions\Servers\Domains\Services\OwnerRecordDraft::apply($view->getData()['domain'],$action,$request->request->all(),confirm:true);
            return response($view->with(['domain'=>$dto]),422)->header('Cache-Control','no-store, private');
        } catch (\Paymenter\Extensions\Servers\Domains\Services\OwnerFormValidation $error) {
            if (in_array($action,['dns','srv','ds_add','ds_delete','glue_add','glue_update','glue_delete'],true)) {
                $view=(new OwnedDomainController($this->owners))->show($request,$domain);
                $dto=\Paymenter\Extensions\Servers\Domains\Services\OwnerRecordDraft::apply($view->getData()['domain'],$action,$request->request->all());
                $messages=in_array($action,['ds_delete','glue_delete'],true)?['owner_action'=>'We couldn’t save this change. Reload the page and try again.']:$error->messages($dto->name);
                $errors=(new \Illuminate\Support\ViewErrorBag)->put('default',new \Illuminate\Support\MessageBag($messages));
                return response($view->with(['domain'=>$dto,'errors'=>$errors]),422)->header('Cache-Control','no-store, private');
            }
            return $this->failed($request,$domain,$action,$error->messages(''));
        } catch (\Paymenter\Extensions\Servers\Domains\Services\SetupValidation $error) {
            return $this->failed($request,$domain,$action,array_intersect_key([
                'nameservers'=>'Check the nameservers.','privacy'=>'Domain privacy is not available for this domain.'],array_flip($error->fields)));
        } catch (\Paymenter\Extensions\Servers\Domains\Services\ContactValidation $error) {
            return $this->failed($request,$domain,$action,array_fill_keys(array_unique(array_map(static fn($field)=>preg_replace('/^contacts\\.[^.]+\\./','contact.',$field),$error->fields)),'Check this contact detail.'));
        } catch (\Throwable) { return $this->failed($request,$domain,$action,['owner_action'=>'We couldn’t save this change. Check the details and try again. If it keeps happening, contact support.']); }
    }
    private function failed(Request $request,int $domain,string $action,array $errors)
    {
        // Never flash arbitrary input, confirmation, intent keys or transfer/authentication secrets.
        $input=[];$p=$request->request->all();
        if (in_array($action,['contacts','contact_profile'],true)) {
            $contact=is_array($p['contact']??null)?$p['contact']:[];$input['contact']=[];
            foreach (array_keys(\Paymenter\Extensions\Servers\Domains\Services\ContactDetails::limits('enom')) as $field) {
                if (array_key_exists($field,$contact) && ($contact[$field]===null || is_string($contact[$field]) && strlen($contact[$field])<=4096)) { $input['contact'][$field]=$contact[$field]??''; }
            }
            if (is_string($p['role']??null) && in_array($p['role'],['all',...\Paymenter\Extensions\Servers\Domains\Services\ContactDetails::ROLES],true)) { $input['role']=$p['role']; }
        }
        if (in_array($action,['nameservers','setup'],true) && is_string($p['nameservers']??null) && strlen($p['nameservers'])<=4096) { $input['nameservers']=$p['nameservers']; }
        if ($action==='setup' && in_array($p['privacy']??null,['0','1'],true)) { $input['privacy']=$p['privacy']; }
        if ($action==='lock' && in_array($p['locked']??null,['0','1'],true)) { $input['locked']=$p['locked']; }
        $form=route($action==='setup'?'domains.domains.setup-save':'domains.domains.manage',$action==='setup'?[$domain]:[$domain,$action]);
        return redirect()->route($action==='setup'?'domains.domains.setup':'domains.domains.show',$domain)
            ->withErrors($errors)->withInput($input)->with('domains_failed_form',$form);
    }
    private static function servers(string $text): array
    { return $text===''?[]:array_values(array_filter(array_map('trim',preg_split('/\r\n|\n|\r/',$text)),fn($v)=>$v!=='')); }
}
