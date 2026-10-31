<?php
// Enom 444f31c RegistrationSetupController/TransferCodeController/DomainController; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Http\Controllers;
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Http\{FormKeys,RawPost};
use Paymenter\Extensions\Servers\Domains\Services\{ContactRecovery, DomainManagement, OwnedDomains, OwnerPorts, OwnerSetup, OwnerNameservers, OwnerFormValidation, TransferInputs};
/** Dormant owner POST boundary. Never calls a driver write or queues a purchase. */
final readonly class OwnerActionController
{
    // Shown on the auth code page once a code is saved, so the redirect carries no flash.
    public const CODE_SAVED='Auth code saved.';
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
            return redirect()->route('domains.domains.code',$domain)->withErrors(['transfer_code'=>'Enter a valid auth code.']);
        }
        FormKeys::consume($request,$context);
        try {
            $body=Request::create('/','POST',['transfer_code'=>$code]); $body->setUserResolver(fn()=>$request->user());
            $this->inputs->submitCode($body,$op?->id??0);
            return redirect()->route('domains.domains.code',$domain);
        } catch (\Throwable) { return redirect()->route('domains.domains.code',$domain)->withErrors(['transfer_code'=>'We couldn’t save the auth code. Try again.']); }
    }
    public function setup(Request $request,int $domain)
    {
        return $this->submit($request,$domain,'setup',function()use($request,$domain):void {
            $d=$this->owners->owned($request->user(),$domain);
            $servers=OwnerNameservers::parse(($request->request->all()['nameservers']??null),$d->registrar_key==='enom',$d->registrar_key,$d->domain);
            if (!in_array($request->request->get('privacy','0'),['0','1'],true)) { throw new \Paymenter\Extensions\Servers\Domains\Services\SetupValidation(['privacy']); }
            $this->setup->save($request->user(),$domain,['nameservers'=>$servers,'privacy'=>$request->request->get('privacy')==='1']);
        },'Setup saved.');
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
            (new \Paymenter\Extensions\Servers\Domains\Services\ManagementForms($this->management,$this->ports))->submit($request->user(),$d,$action,$p,$nonce);
        },\Paymenter\Extensions\Servers\Domains\Services\ManagementForms::REQUESTED);
    }
    private function submit(Request $request,int $domain,string $action,\Closure $work,string $message)
    {
        $owned=$this->owners->owned($request->user(),$domain); $context='owner:'.$domain.':'.$action;
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
                $messages=in_array($action,['ds_delete','glue_delete'],true)?['owner_action'=>'We couldn’t save this change. Reload the page and try again.']:$error->messages($dto->name,$request->request->all());
                $errors=(new \Illuminate\Support\ViewErrorBag)->put('default',new \Illuminate\Support\MessageBag($messages));
                return response($view->with(['domain'=>$dto,'errors'=>$errors]),422)->header('Cache-Control','no-store, private');
            }
            return $this->failed($request,$domain,$action,$error->messages($owned->domain,$request->request->all(),$owned->registrar_key));
        } catch (\Paymenter\Extensions\Servers\Domains\Services\SetupValidation $error) {
            return $this->failed($request,$domain,$action,array_intersect_key([
                'nameservers'=>$owned->registrar_key==='enom'&&$this->owners->operation($owned)?->action==='transfer'?'Keep your current nameservers during this transfer. You can change them after the transfer finishes.':'These nameservers cannot be used for this order. Contact support.','privacy'=>'WHOIS privacy isn’t available for this domain.'],array_flip($error->fields)));
        } catch (\Paymenter\Extensions\Servers\Domains\Services\ContactValidation $error) {
            return $this->failed($request,$domain,$action,OwnerFormValidation::contact($error)->messages($owned->domain,registrar:$owned->registrar_key));
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
}
