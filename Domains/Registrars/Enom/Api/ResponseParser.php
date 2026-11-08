<?php
// Adapted from autonomiceng/paymenter-enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api;

final class ResponseParser
{
    public function parse(ReadCommand|RegistrationCommand|ManagementCommand $command, #[\SensitiveParameter] string $body): ProtocolResult
    {
        if (strlen($body) > $command->bodyLimit() || str_contains($body, "\0") || preg_match('//u', $body) !== 1 || preg_match('/<!DOCTYPE|<!ENTITY/i', $body)) {
            return new ProtocolResult('unknown', 'invalid_response');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            if ($command instanceof ReadCommand && $command->bulkPricing() && !str_starts_with(ltrim($body), '<')) {
                $data = $this->bulkText($body);
            } else {
                $document = new \DOMDocument;
                if (!$document->loadXML($body, LIBXML_NONET)
                    || $document->doctype !== null || !$document->documentElement || strtolower($document->documentElement->tagName) !== 'interface-response') {
                    return new ProtocolResult('unknown', 'invalid_response');
                }
                $count = 0;
                $data = $this->node($document->documentElement, 0, $count, $command === ReadCommand::Tlds ? 400000 : 20000);
            }
        } catch (\Throwable) {
            return new ProtocolResult('unknown', 'invalid_response');
        } finally {
            libxml_clear_errors(); libxml_use_internal_errors($previous);
        }
        if (!is_array($data) || !is_string($data['command'] ?? null)
            || strcasecmp($data['command'], $command->value) !== 0
            || !is_string($data['errcount'] ?? null) || !preg_match('/^[0-9]+$/D', $data['errcount'])
            || !is_string($data['done'] ?? null) || strtolower($data['done']) !== 'true') {
            return new ProtocolResult('unknown', 'invalid_envelope');
        }
        if ($command === ReadCommand::Balance && $data['errcount'] === '1'
            && (($data['errors']['err1'] ?? null) === 'Bad User name or Password'
                || ($data['responses']['response']['responsenumber'] ?? null) === '304155')) {
            return new ProtocolResult('rejected', 'identity_credentials_refused');
        }
        $code = $data['rrpcode'] ?? $data['responsecode'] ?? null;
        $code = is_string($code) && preg_match('/^[0-9]{1,6}$/D', $code) ? $code : null;
        if ($command === ReadCommand::HostStatus && $data['errcount'] === '1' && ($data['errors']??null) === ['err1'=>'Error 545 - Name server does not exist']
            && !array_key_exists('nschecksuccess',$data) && !array_key_exists('checknsstatus',$data)
            && $code === '545' && !isset($data['responsecode']) && !array_filter(array_keys($data),fn($k)=>preg_match('/^err[0-9]+$/D',$k))) { return new ProtocolResult('success',$code,['host_absent'=>true]); }
        $reply=null;
        if($command===RegistrationCommand::Purchase){
            $number=$data['responses']['response']['responsenumber']??null; $replyCode=$code;
            if($replyCode===null&&is_string($number)&&preg_match('/^[0-9]{1,6}$/D',$number)){$replyCode=$number;}
            $reply=\Paymenter\Extensions\Servers\Domains\Data\RegistrarReply::capture($replyCode,
                $data['errors']['err1']??$data['err1']??$data['responses']['response']['responsestring']??null);
        }
        if (trim($data['errcount'], '0') !== '') {
            $refusal=$command===RegistrationCommand::Purchase&&$this->purchaseRefused($data)
                ? ['purchase_refusal'=>['err_count'=>(int)$data['errcount'],'rrp_code'=>$data['rrpcode'],
                    'text'=>$data['errors']['err1']??$data['responses']['response']['responsestring'],'order_id'=>null,'done'=>true]]:[];
            return new ProtocolResult($command instanceof ReadCommand||$command instanceof ManagementCommand&&$this->refused($command,$data)||$refusal ? 'rejected' : 'unknown', $code, $refusal, registrarReply:$reply);
        }
        if (isset($data['errors']) && $data['errors'] !== '') { return new ProtocolResult('unknown', 'contradictory_errors', registrarReply:$reply); }
        foreach ($data as $key => $value) {
            if (preg_match('/^err[0-9]+$/D', $key) && $value !== '') { return new ProtocolResult('unknown', 'contradictory_errors', registrarReply:$reply); }
        }
        if (isset($data['responsecode']) && (!is_string($data['responsecode']) || !preg_match('/^1[0-9]{5}$/D', $data['responsecode']))) {
            return new ProtocolResult('unknown', $code, registrarReply:$reply);
        }
        $codes = $command === ReadCommand::Check ? ['200', '210', '211'] : ($command instanceof RegistrationCommand && in_array($command, [RegistrationCommand::Purchase, RegistrationCommand::Renew, RegistrationCommand::GraceRenew], true) ? ['200', '1300'] : ['200']);
        if (isset($data['rrpcode']) && !in_array($data['rrpcode'], $codes, true)) {
            return new ProtocolResult('unknown', $code, registrarReply:$reply);
        }
        if (in_array($command, [ReadCommand::Orders, ReadCommand::Order], true)) {
            $payload = $command === ReadCommand::Orders ? ['orderlist', 'ordercount'] : ['order'];
            $data['history_complete'] = !array_diff(array_keys($data), ['command', 'errcount', 'done', 'rrpcode', 'responsecode', 'errors', ...$payload]);
        }
        // Payload-specific validation belongs to each named read normalizer, never to acknowledgement alone.
        return new ProtocolResult('success', $code, $data, strlen($body));
    }

    /** The observed order-creation refusal only. A registry error without this text is still uncertain. */
    private function purchaseRefused(#[\SensitiveParameter] array $data): bool
    {
        if(!preg_match('/^(?:[1-9][0-9]?|100)$/D',$data['errcount'])||!is_string($data['rrpcode']??null)||$data['rrpcode']!=='541'
            ||isset($data['responsecode'])||!$this->noPurchaseFacts($data)){return false;}
        $metadata=['rrpcode','rrptext','command','apitype','language','errcount','responsecount','minperiod','maxperiod',
            'server','site','islockable','isrealtimetld','timedifference','exectime','done','trackingkey','requestdatetime','debug'];
        if(array_diff(array_keys($data),[...$metadata,'contacts','errors','responses'])){return false;}
        foreach($metadata as $key){if(isset($data[$key])&&!is_string($data[$key])){return false;}}
        $messages=['Validation error. Failed to create order.','Validation error; failed to add; order(s)'];
        $found=false; $errors=$data['errors']??[];
        if($errors!==''&&$errors!==[]){
            if(!is_array($errors)||array_is_list($errors)||!array_key_exists('err1',$errors)||count($errors)!==(int)$data['errcount']){return false;}
            foreach($errors as $key=>$text){
                if(!preg_match('/^err[1-9][0-9]*$/D',(string)$key)||!in_array($text,$messages,true)){return false;}
                $found=true;
            }
        }elseif($data['errcount']!=='1'){return false;}
        // The recorded refusal explicitly counts its responses. Missing or
        // contradictory count evidence cannot authorize a payment return.
        if(!isset($data['responsecount'])||!in_array($data['responsecount'],['0','1'],true)
            ||$data['responsecount']!==(isset($data['responses'])&&$data['responses']!==''?'1':'0')){return false;}
        if(isset($data['responses'])&&$data['responses']!==''){
            $responses=$data['responses'];$response=is_array($responses)?($responses['response']??null):null;
            if(!is_array($responses)||array_keys($responses)!==['response']||!is_array($response)
                ||array_diff(array_keys($response),['responsenumber','responsestring'])
                ||!is_string($response['responsenumber']??null)||!preg_match('/^[347][0-9]{5}$/D',$response['responsenumber'])
                ||!in_array($response['responsestring']??null,$messages,true)){return false;}
            $found=true;
        }
        return $found;
    }
    /** Order, expiry, charge and status fields contradict a refusal even when nested or empty. */
    private function noPurchaseFacts(#[\SensitiveParameter] array $data): bool
    {
        foreach($data as $key=>$value){
            if(preg_match('/order|domainid|domainnameid|expir|expdate|charge|amount|owned|belong|processing|pending|queued/i',(string)$key)
                ||preg_match('/^err[0-9]+$/D',(string)$key)&&!in_array($value,['Validation error. Failed to create order.','Validation error; failed to add; order(s)'],true)) {return false;}
            if(is_string($value)&&preg_match('/^(?:rrptext|responsestring|err[0-9]+)$/D',(string)$key)
                &&preg_match('/\b(?:success|successful|successfully|pending|processing|queued)\b/i',$value)){return false;}
            if(is_array($value)&&!$this->noPurchaseFacts($value)){return false;}
        }
        return true;
    }

    /** Indexed Text rows use the same internal shape as the documented XML products. */
    private function bulkText(string $body): array
    {
        $data = []; $rows = [];
        foreach (preg_split('/\r\n|\n|\r/', $body) as $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), ';')) { continue; }
            if (!preg_match('/^([a-z][a-z0-9_-]*)\s*[:=]\s*(.*)$/iD', $line, $parts)) { throw new \RuntimeException('Invalid price response.'); }
            $key = strtolower($parts[1]); $value = trim($parts[2]);
            if (preg_match('/^(currency|tld|tldid|minimumregistration|registerprice|resellerpricereg|registerenabled|renewprice|resellerpricerenew|renewenabled|transferprice|resellerpricetran|transferenabled|rgpprice|resellerpricergp|rgpenabled|idncategory)([1-9][0-9]{0,3})$/D', $key, $field)) {
                $index = (int) $field[2];
                if (isset($rows[$index][$field[1]])) { throw new \RuntimeException('Duplicate price field.'); }
                $rows[$index][$field[1]] = $value;
            } else {
                if (array_key_exists($key, $data)) { throw new \RuntimeException('Duplicate response field.'); }
                $data[$key] = $value;
            }
        }
        ksort($rows); $data['pricestructure'] = ['product' => array_values($rows)];
        return $data;
    }

    /** Frozen Enom documented refusals; never a definitely-unsent or refund proof. */
    private function refused(ManagementCommand $command, #[\SensitiveParameter] array $d): bool
    {
        foreach(['orderid','transferorderid','registryexpdate'] as $k){if(isset($d[$k])&&$d[$k]!==''){return false;}}
        if(isset($d['transferorder'])&&$d['transferorder']!==''&&(!is_array($d['transferorder'])||array_is_list($d['transferorder'])||isset($d['transferorder']['transferorderid'])&&$d['transferorder']['transferorderid']!=='')){return false;}
        foreach(['domaininfo','reactivatedomainname'] as $k){if(isset($d[$k])&&$d[$k]!==''&&(!is_array($d[$k])||array_is_list($d[$k])||isset($d[$k]['registryexpdate'])||isset($d[$k]['orderid']))){return false;}}
        $top=$d['nssuccess']??null;$nested=$d[strtolower($command->value)]['nssuccess']??null;
        if($top!==null&&$nested!==null&&$top!==$nested){return false;}
        if(in_array($command,[ManagementCommand::GlueAdd,ManagementCommand::GlueUpdate,ManagementCommand::GlueDelete],true)&&is_string($top??$nested)&&in_array($top??$nested,['0','false'],true)){return true;}
        $codes=[];foreach(['rrpcode','responsecode'] as $k){if(isset($d[$k])&&$d[$k]!==''){$codes[]=$d[$k];}}
        if(!$codes){$errors=[];foreach((is_array($d['errors']??null)?$d['errors']:[])+$d as $k=>$v){if(preg_match('/^err[0-9]+$/D',(string)$k)&&is_string($v)&&trim($v)!==''){$errors[]=trim($v);}}
            if(!$errors||count($errors)!==(int)$d['errcount']){return false;}
            foreach($errors as $v){if(!preg_match('/^(?:Invalid [A-Za-z ]+ information\.?|Parameter value policy error;.*|.*\bis (?:not a valid|required|invalid)\b.*|Invalid (?:phone|email|country|postal code|state|nameserver)\b.*)$/i',$v)){return false;}}return true;}
        foreach($codes as $c){if(!is_string($c)||(!in_array($c,['500','501','502','503','504','505','506','507','508','509','530','531','535','541','542','543','544','545','546','547','548','550','551','552','556','524181'],true)&&!preg_match('/^[347](?:01|03|04|05|06|12|13|15|16|17)[0-9]{3}$/D',$c))){return false;}}return true;
    }

    private function recordAddress(\DOMElement $node): bool
    {
        if (strtolower($node->tagName) !== 'address' || !$node->parentNode instanceof \DOMElement) { return false; }
        foreach ($node->parentNode->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->tagName) === 'type' && strtoupper(trim($child->textContent)) === 'TXT') { return true; }
        }
        return false;
    }
    private function node(\DOMElement $node, int $depth, int &$count, int $limit): array|string
    {
        if ($depth > 16 || ++$count > $limit) { throw new \RuntimeException('Registrar response exceeds limits.'); }
        $data = []; $text = '';
        foreach ($node->attributes as $attribute) {
            $key = strtolower($attribute->name);
            if (isset($data['@attributes']) && array_key_exists($key, $data['@attributes'])) { throw new \RuntimeException('Duplicate normalized attribute.'); }
            $data['@attributes'][$key] = $attribute->value;
        }
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $key = strtolower($child->tagName);
                $value = $this->node($child, $depth + 1, $count, $limit);
                if (array_key_exists($key, $data)) {
                    if (!is_array($data[$key]) || !array_is_list($data[$key])) { $data[$key] = [$data[$key]]; }
                    $data[$key][] = $value;
                } else { $data[$key] = $value; }
            } elseif ($child instanceof \DOMText || $child instanceof \DOMCdataSection) { $text .= $child->textContent; }
        }
        if (!$data) { return $this->recordAddress($node) ? $text : trim($text); }
        if (trim($text) !== '') { $data['#text'] = trim($text); }
        return $data;
    }
}
