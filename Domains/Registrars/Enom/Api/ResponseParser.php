<?php
// Adapted from autonomiceng/paymenter-enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api;

final class ResponseParser
{
    public function parse(ReadCommand $command, #[\SensitiveParameter] string $body): ProtocolResult
    {
        if (strlen($body) > $command->bodyLimit() || preg_match('/<!DOCTYPE|<!ENTITY/i', $body)) {
            return new ProtocolResult('unknown', 'invalid_response');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument;
            if (!$document->loadXML($body, LIBXML_NONET | LIBXML_NOBLANKS)
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
        if (trim($data['errcount'], '0') !== '') { return new ProtocolResult('rejected', $code); }
        if (isset($data['errors']) && $data['errors'] !== '') { return new ProtocolResult('unknown', 'contradictory_errors'); }
        foreach ($data as $key => $value) {
            if (preg_match('/^err[0-9]+$/D', $key) && $value !== '') { return new ProtocolResult('unknown', 'contradictory_errors'); }
        }
        if (isset($data['responsecode']) && (!is_string($data['responsecode']) || !preg_match('/^1[0-9]{5}$/D', $data['responsecode']))) {
            return new ProtocolResult('unknown', $code);
        }
        if (isset($data['rrpcode']) && !in_array($data['rrpcode'], $command === ReadCommand::Check ? ['200', '210', '211'] : ['200'], true)) {
            return new ProtocolResult('unknown', $code);
        }
        // Payload-specific validation belongs to each named read normalizer, never to acknowledgement alone.
        return new ProtocolResult('success', $code, $data);
    }

    private function node(\DOMElement $node, int $depth, int &$count, int $limit): array|string
    {
        if ($depth > 16 || ++$count > $limit) { throw new \RuntimeException('Registrar response exceeds limits.'); }
        $data = []; $text = '';
        foreach ($node->attributes as $attribute) { $data['@attributes'][strtolower($attribute->name)] = $attribute->value; }
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
        if (!$data) { return trim($text); }
        if (trim($text) !== '') { $data['#text'] = trim($text); }
        return $data;
    }
}
