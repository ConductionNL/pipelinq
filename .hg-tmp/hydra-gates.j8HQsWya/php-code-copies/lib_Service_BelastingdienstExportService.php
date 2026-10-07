<?php

   
                                         
  
                                                                           
                                                                         
                                                                           
                                                                        
                                                                           
                          
  
                                                                            
                                                                           
                                                                      
                                                               
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                    
                                               
  
                         
  
                                                 
  
                                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DOMDocument;

   
                                                                            
  
                      
  
         
                         
               
                                                         
                                  
                                                     
                                                    
                                             
                                                           
                
              
                       
               
                          
      
  
                       
  
          
                                              
      
  
                                                               
   
class BelastingdienstExportService {
	   
                                                          
   
               
    
	public const SIGNATURE_ALGORITHM = 'HMAC-SHA256';

	   
                
   
                                                                            
                                                                                
                                                                     
    
	public function __construct(
		private KassakoppelingSignatureService $signature,
	) {
	}                   

	   
                                  
   
                                                                          
                                                                       
                                                                
   
                                                                 
                                                                    
                                                                             
   
                                
   
                                                                
    
	public function exportAsXml(array $entries, ?array $manifest = null): string {
		if ($manifest === null) {
			$manifest = $this->buildManifest(entries: $entries, fromDate: '', toDate: '');
		}

		$document = new DOMDocument('1.0', 'UTF-8');
		$document->formatOutput = true;

		$root = $document->createElement('KassakoppelingExport');
		$document->appendChild($root);

		$metadata = $document->createElement('Metadata');
		$metadata->appendChild($document->createElement('ExportDate', (string)$manifest['exportDate']));
		$metadata->appendChild($document->createElement('EntryCount', (string)$manifest['entryCount']));

		$dateRange = $document->createElement('DateRange');
		$dateRange->setAttribute('from', (string)$manifest['dateRange']['from']);
		$dateRange->setAttribute('to', (string)$manifest['dateRange']['to']);
		$metadata->appendChild($dateRange);

		$registerList = $document->createElement('RegisterList');
		$registerList->appendChild(
			$document->createTextNode(implode(', ', (array)($manifest['registerList'] ?? [])))
		);
		$metadata->appendChild($registerList);

		$metadata->appendChild($document->createElement('ChainIntegrity', (string)$manifest['chainIntegrity']));
		if (($manifest['chainStatus'] ?? '') !== '') {
			$metadata->appendChild($document->createElement('ChainStatus', (string)$manifest['chainStatus']));
		}

		$metadata->appendChild($document->createElement('SignatureAlgorithm', self::SIGNATURE_ALGORITHM));
		$root->appendChild($metadata);

		$entriesNode = $document->createElement('Entries');
		foreach ($entries as $entry) {
			$entryNode = $document->createElement('Entry');
			foreach ($this->canonicalEntry(entry: $entry) as $field => $value) {
				$childValue = $value;
				if (is_bool($childValue) === true) {
					$boolAsString = 'false';
					if ($childValue === true) {
						$boolAsString = 'true';
					}

					$childValue = $boolAsString;
				}

				if ($childValue === null) {
					$childValue = '';
				}

				$child = $document->createElement($field);
				$child->appendChild($document->createTextNode((string)$childValue));
				$entryNode->appendChild($child);
			}

			$entriesNode->appendChild($entryNode);
		}             

		$root->appendChild($entriesNode);

		return (string)$document->saveXML();
	}                   

	   
                                                  
   
                                                                 
                                                                    
                                                                             
   
                                 
   
                                                                
    
	public function exportAsJson(array $entries, ?array $manifest = null): string {
		if ($manifest === null) {
			$manifest = $this->buildManifest(entries: $entries, fromDate: '', toDate: '');
		}

		$entriesPayload = [];
		foreach ($entries as $entry) {
			$entriesPayload[] = $this->canonicalEntry(entry: $entry);
		}

		$payload = [
			'exportMetadata' => [
				'exportDate' => (string)$manifest['exportDate'],
				'entryCount' => (int)$manifest['entryCount'],
				'dateRange' => $manifest['dateRange'],
				'registerList' => array_values((array)($manifest['registerList'] ?? [])),
				'chainIntegrity' => (string)$manifest['chainIntegrity'],
				'chainStatus' => (string)($manifest['chainStatus'] ?? ''),
				'signatureAlgorithm' => self::SIGNATURE_ALGORITHM,
			],
			'entries' => $entriesPayload,
		];

		$encoded = json_encode($payload, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		if ($encoded === false) {
			return '{}';
		}

		return $encoded;
	}                    

	   
                                       
   
                                                                       
                                                    
                                                                         
                                                 
   
                                                                 
                                                         
                                                       
   
                                              
   
                                                                
    
	public function buildManifest(array $entries, string $fromDate, string $toDate): array {
		$registers = [];
		foreach ($entries as $entry) {
			$register = (string)($entry['registerNumber'] ?? '');
			if ($register !== '' && in_array($register, $registers, true) === false) {
				$registers[] = $register;
			}
		}

		sort($registers);

		$perRegisterStatus = [];
		$chainIntegrity = 'valid';
		$chainStatus = '';

		foreach ($registers as $register) {
			$forRegister = array_values(
				array_filter(
					$entries,
					fn (array $row): bool => (string)($row['registerNumber'] ?? '') === $register
				)
			);

			usort(
				$forRegister,
				fn (array $left, array $right): int => strcmp(
					(string)($left['timestamp'] ?? ''),
					(string)($right['timestamp'] ?? '')
				)
			);

			$result = $this->signature->verifyHashChain(entries: $forRegister);
			$perRegisterStatus[$register] = $result;
			if ($result['chainValid'] === false) {
				$chainIntegrity = 'invalid';
				if ($chainStatus === '') {
					$chainStatus = 'Broken at entry ' . ((int)($result['brokenAt'] ?? 0)) . ' on register ' . $register;
				}
			}
		}             

		return [
			'exportDate' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
			'entryCount' => count($entries),
			'dateRange' => [
				'from' => $fromDate,
				'to' => $toDate,
			],
			'registerList' => $registers,
			'chainIntegrity' => $chainIntegrity,
			'chainStatus' => $chainStatus,
			'perRegisterStatus' => $perRegisterStatus,
			'signatureAlgorithm' => self::SIGNATURE_ALGORITHM,
		];
	}                     

	   
                                                           
   
                                                 
   
                                                          
    
	private function canonicalEntry(array $entry): array {
		return [
			'id' => (string)($entry['id'] ?? $entry['uuid'] ?? ''),
			'timestamp' => (string)($entry['timestamp'] ?? ''),
			'operatorId' => (string)($entry['operatorId'] ?? ''),
			'registerNumber' => (string)($entry['registerNumber'] ?? ''),
			'action' => (string)($entry['action'] ?? ''),
			'amount' => (int)($entry['amount'] ?? 0),
			'itemCount' => (int)($entry['itemCount'] ?? 0),
			'taxAmount' => (int)($entry['taxAmount'] ?? 0),
			'transactionUuid' => (string)($entry['transactionUuid'] ?? ''),
			'description' => (string)($entry['description'] ?? ''),
			'signature' => (string)($entry['signature'] ?? ''),
			'previousHash' => (string)($entry['previousHash'] ?? ''),
			'currentHash' => (string)($entry['currentHash'] ?? ''),
			'verified' => $entry['verified'] ?? null,
			'exportedAt' => (string)($entry['exportedAt'] ?? ''),
		];
	}                      
}           
