<?php
/**
 * Custom SOAP Envelope Builder for Matrikkel API
 * 
 * Bygger SOAP-enveloper manuelt for å omgå PHP SOAP-klientens begrensninger
 * med serialisering av komplekse nested objekter som MatrikkelBubbleId.
 * 
 * @author AI Assistant
 * @date 2025-10-14
 */

namespace Iaasen\Matrikkel\Client;

class SoapEnvelopeBuilder
{
    private string $namespace_soap = 'http://schemas.xmlsoap.org/soap/envelope/';
    private string $namespace_domain = 'http://matrikkel.statkart.no/matrikkelapi/wsapi/v1/domain';
    private string $namespace_service = 'http://matrikkel.statkart.no/matrikkelapi/wsapi/v1/service/nedlastning';
    
    /**
     * Build complete SOAP envelope for findObjekterEtterId with proper MatrikkelBubbleId serialization
     */
    public function buildFindObjekterEtterIdEnvelope(
        ?array $matrikkelBubbleId,
        string $domainklasse,
        ?string $filter,
        int $maksAntall,
        array $matrikkelContext
    ): string {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<SOAP-ENV:Envelope';
        $xml .= ' xmlns:SOAP-ENV="' . $this->namespace_soap . '"';
        $xml .= ' xmlns:ns1="' . $this->namespace_domain . '"';
        $xml .= ' xmlns:ns2="' . $this->namespace_service . '">';
        
        $xml .= '<SOAP-ENV:Body>';
        $xml .= '<ns2:findObjekterEtterId>';
        
        // MatrikkelBubbleId with proper snapshotVersion serialization (MUST be first parameter)
        if ($matrikkelBubbleId !== null) {
            $xml .= '<ns2:matrikkelBubbleId>';
            $xml .= '<ns1:value>' . htmlspecialchars((string)$matrikkelBubbleId['value']) . '</ns1:value>';
            if (isset($matrikkelBubbleId['snapshotVersion'])) {
                $xml .= '<ns1:snapshotVersion>';
                $xml .= '<ns1:timestamp>' . htmlspecialchars($matrikkelBubbleId['snapshotVersion']['timestamp']) . '</ns1:timestamp>';
                $xml .= '</ns1:snapshotVersion>';
            }
            $xml .= '</ns2:matrikkelBubbleId>';
        } else {
            // Send null matrikkelBubbleId
            $xml .= '<ns2:matrikkelBubbleId xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:nil="true"/>';
        }
        
        $xml .= '<ns2:domainklasse>' . htmlspecialchars($domainklasse) . '</ns2:domainklasse>';
        
        if ($filter !== null) {
            $xml .= '<ns2:filter>' . htmlspecialchars($filter) . '</ns2:filter>';
        }
        
        $xml .= '<ns2:maksAntall>' . $maksAntall . '</ns2:maksAntall>';
        
        // MatrikkelContext
        $xml .= '<ns2:matrikkelContext>';
        $xml .= '<ns1:locale>' . htmlspecialchars($matrikkelContext['locale']) . '</ns1:locale>';
        $xml .= '<ns1:brukOriginaleKoordinater>' . ($matrikkelContext['brukOriginaleKoordinater'] ? 'true' : 'false') . '</ns1:brukOriginaleKoordinater>';
        if (isset($matrikkelContext['koordinatsystemKodeId'])) {
            $xml .= '<ns1:koordinatsystemKodeId>';
            $xml .= '<ns1:value>' . $matrikkelContext['koordinatsystemKodeId']['value'] . '</ns1:value>';
            $xml .= '</ns1:koordinatsystemKodeId>';
        }
        $xml .= '<ns1:systemVersion>' . htmlspecialchars($matrikkelContext['systemVersion']) . '</ns1:systemVersion>';
        if (isset($matrikkelContext['klientIdentifikasjon'])) {
            $xml .= '<ns1:klientIdentifikasjon>' . htmlspecialchars($matrikkelContext['klientIdentifikasjon']) . '</ns1:klientIdentifikasjon>';
        }
        if (isset($matrikkelContext['snapshotVersion'])) {
            $xml .= '<ns1:snapshotVersion>';
            $xml .= '<ns1:timestamp>' . htmlspecialchars($matrikkelContext['snapshotVersion']['timestamp']) . '</ns1:timestamp>';
            $xml .= '</ns1:snapshotVersion>';
        }
        $xml .= '</ns2:matrikkelContext>';
        
        $xml .= '</ns2:findObjekterEtterId>';
        $xml .= '</SOAP-ENV:Body>';
        $xml .= '</SOAP-ENV:Envelope>';
        
        return $xml;
    }
    
    /**
     * Parse SOAP response XML and extract items
     */
    public function parseFindObjekterEtterIdResponse(string $responseXml): array {
        // Create a DOMDocument to parse the response
        $doc = new \DOMDocument();
        $doc->loadXML($responseXml);
        
        // Check for SOAP fault first
        $faultNodes = $doc->getElementsByTagName('Fault');
        if ($faultNodes->length > 0) {
            $faultNode = $faultNodes->item(0);
            $faultCode = $faultNode->getElementsByTagName('faultcode')->item(0)?->textContent ?? 'Unknown';
            $faultString = $faultNode->getElementsByTagName('faultstring')->item(0)?->textContent ?? 'Unknown error';
            throw new \SoapFault($faultCode, $faultString);
        }
        
        // Find return elements
        $returnNodes = $doc->getElementsByTagName('return');
        if ($returnNodes->length === 0) {
            return [];
        }
        
        $items = [];
        $returnNode = $returnNodes->item(0);
        
        // Get all item elements
        $itemNodes = $returnNode->getElementsByTagName('item');
        foreach ($itemNodes as $itemNode) {
            // Convert XML node to stdClass object (similar to what SOAP client would do)
            $item = $this->xmlNodeToObject($itemNode);
            $items[] = $item;
        }
        
        return $items;
    }
    
    /**
     * Convert XML node to stdClass object recursively
     */
    private function xmlNodeToObject(\DOMNode $node): \stdClass {
        $obj = new \stdClass();
        
        // Handle child nodes
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $childName = $child->localName;
                
                if ($child->hasChildNodes() && $child->firstChild->nodeType === XML_ELEMENT_NODE) {
                    // Has child elements - recurse
                    $obj->$childName = $this->xmlNodeToObject($child);
                } else {
                    // Text content
                    $obj->$childName = $child->textContent;
                }
            }
        }
        
        return $obj;
    }
}