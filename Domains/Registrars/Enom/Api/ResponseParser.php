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
            $document = new \DOMDocument;
            if (!$document->loadXML($body, LIBXML_NONET)
                || $document->doctype !== null || !$document->documentElement || strtolower($document->documentElement->tagName) !== 'interface-response') {
                return new ProtocolResult('unknown', 'invalid_response');
            }
            $count = 0;
            $data = $this->node($document->documentElement, 0, $count, $command === ReadCommand::Tlds ? 400000 : 20000);
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
        $code = $data['rrpcode'] ?? $data['responsecode'] ?? null;
        $code = is_string($code) && preg_match('/^[0-9]{1,6}$/D', $code) ? $code : null;
        if ($command === ReadCommand::HostStatus && $data['errcount'] === '1' && ($data['errors']??null) === ['err1'=>'Error 545 - Name server does not exist']
            && !array_key_exists('nschecksuccess',$data) && !array_key_exists('checknsstatus',$data)
            && $code === '545' && !isset($data['responsecode']) && !array_filter(array_keys($data),fn($k)=>preg_match('/^err[0-9]+$/D',$k))) { return new ProtocolResult('success',$code,['host_absent'=>true]); }
        if (trim($data['errcount'], '0') !== '') { return new ProtocolResult($command instanceof ReadCommand||$command instanceof ManagementCommand&&$this->refused($command,$data) ? 'rejected' : 'unknown', $code); }
        if (isset($data['errors']) && $data['errors'] !== '') { return new ProtocolResult('unknown', 'contradictory_errors'); }
        foreach ($data as $key => $value) {
            if (preg_match('/^err[0-9]+$/D', $key) && $value !== '') { return new ProtocolResult('unknown', 'contradictory_errors'); }
        }
        if (isset($data['responsecode']) && (!is_string($data['responsecode']) || !preg_match('/^1[0-9]{5}$/D', $data['responsecode']))) {
            return new ProtocolResult('unknown', $code);
        }
        $codes = $command === ReadCommand::Check ? ['200', '210', '211'] : ($command instanceof RegistrationCommand && in_array($command, [RegistrationCommand::Purchase, RegistrationCommand::Renew, RegistrationCommand::GraceRenew], true) ? ['200', '1300'] : ['200']);
        if (isset($data['rrpcode']) && !in_array($data['rrpcode'], $codes, true)) {
            return new ProtocolResult('unknown', $code);
        }
        if (in_array($command, [ReadCommand::Orders, ReadCommand::Order], true)) {
            $payload = $command === ReadCommand::Orders ? ['orderlist', 'ordercount'] : ['order'];
            $data['history_complete'] = !array_diff(array_keys($data), ['command', 'errcount', 'done', 'rrpcode', 'responsecode', 'errors', ...$payload]);
        }
        // Payload-specific validation belongs to each named read normalizer, never to acknowledgement alone.
        return new ProtocolResult('success', $code, $data);
    }

    /** Frozen Enom documented refusals; never a definitely-unsent or refund proof. */
    private function refused(ManagementCommand $command, #[\SensitiveParameter] array $d): bool
    {
        foreach(['orderid','transferorderid','registryexpdate'] as $k){if(isset($d[$k])&&$d[$k]!==''){return false;}}
        if(isset($d['transferorder']['transferorderid'])&&$d['transferorder']['transferorderid']!==''){return false;}
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
        if (strtolower($node->parentNode->tagName) === 'host') { return true; }
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
