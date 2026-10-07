<?php

   
                                       
  
                                                                         
                                                                            
                                                                           
                                                                              
  
                                                           
                                                                             
                                                                        
                                                                      
                                                                           
                                                                             
                                                          
                                   
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                    
                                               
  
                         
  
                                                 
  
                                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                
  
                         
  
                                                                          
                                                                          
                                                                             
                                                                   
                                                           
                                                  
                                                                        
                                                     
                                                                 
                                                                          
                                                
  
                                                                              
                                                                           
                                                            
                                                                           
                                                                            
  
                                                               
   
class KassakoppelingAuditService {
	   
                                       
   
               
    
	public const SCHEMA_KEY = 'kassakoppelingAuditLog_schema';

	   
                                                    
   
                           
    
	public const ALLOWED_ACTIONS = ['sale', 'void', 'refund', 'no-sale'];

	   
                
   
                                                
                                                                             
                                                                     
                                              
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private KassakoppelingSignatureService $signature,
		private BelastingdienstExportService $exporter,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                             
   
                                                                         
                                                                          
                                                                        
                                                                            
                                                                          
                                         
   
                                                                 
   
                                                                     
   
                                                         
   
                                                                
    
	public function createEntry(array $data): array {
		$entry = $this->sanitiseInput(data: $data);
		$this->validateInput(entry: $entry);

		$registerNumber = (string)$entry['registerNumber'];
		$previousHash = $this->getLastCurrentHash(registerNumber: $registerNumber);
		$entry['previousHash'] = $previousHash;
		$entry['signature'] = $this->signature->generateSignature(entryData: $entry);
		$entry['currentHash'] = $this->signature->generateHash(entryData: $entry, previousHash: $previousHash);
		$entry['verified'] = null;
		$entry['exportedAt'] = null;

		$persisted = $this->saveEntry(id: '', object: $entry);

		$this->logger->info(
			'Pipelinq: kassakoppeling audit entry created',
			[
				'registerNumber' => $registerNumber,
				'action' => (string)$entry['action'],
				'operatorId' => (string)$entry['operatorId'],
				'previousHash' => $previousHash,
			]
		);

		return $persisted;
	}                   

	   
                                             
   
                                                                   
                                                                    
                                                           
   
                                                          
   
                                                                         
                                                                    
   
                                                                
    
	public function listEntries(array $filters = []): array {
		[$register, $schema] = $this->config();

		try {
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: kassakoppeling audit listing failed; returning empty',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$rows = [];
		foreach (($results ?? []) as $result) {
			$rows[] = $this->toArray(object: $result);
		}

		$filtered = $this->applyFilters(entries: $rows, filters: $filters);

		usort(
			$filtered,
			function (array $left, array $right): int {
				return strcmp((string)($left['timestamp'] ?? ''), (string)($right['timestamp'] ?? ''));
			}
		);

		return $filtered;
	}                   

	   
                                     
   
                                     
   
                                           
   
                                                             
   
                                                                
    
	public function getEntry(string $id): array {
		[$register, $schema] = $this->config();

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new OCSNotFoundException('Audit entry niet gevonden.');
		}

		return $this->toArray(object: $object);
	}                

	   
                                                               
   
                                                                         
                                                                          
                                                                      
                                                                         
                                                          
   
                                     
   
                                                                                                     
   
                                                             
   
                                                                
    
	public function verifyEntry(string $id): array {
		$entry = $this->getEntry(id: $id);
		$signatureValid = $this->signature->verifySignature(
			entryData: $entry,
			signature: (string)($entry['signature'] ?? '')
		);

		$previousHash = (string)($entry['previousHash'] ?? '');
		$recomputedCurrent = $this->signature->generateHash(entryData: $entry, previousHash: $previousHash);
		$storedCurrent = (string)($entry['currentHash'] ?? '');
		$hashValid = $storedCurrent !== '' && hash_equals($recomputedCurrent, $storedCurrent);

		$verified = ($signatureValid === true && $hashValid === true);
		$entry['verified'] = $verified;
		$entryId = (string)($entry['id'] ?? $entry['uuid'] ?? $id);
		$persisted = $this->saveEntry(id: $entryId, object: $entry);

		$this->logger->info(
			'Pipelinq: kassakoppeling audit entry verified',
			[
				'id' => $entryId,
				'verified' => $verified,
				'signatureValid' => $signatureValid,
				'hashValid' => $hashValid,
			]
		);

		return [
			'verified' => $verified,
			'signatureValid' => $signatureValid,
			'hashValid' => $hashValid,
			'entry' => $persisted,
		];
	}                   

	   
                                                                       
   
                                                                       
                                                        
                                                                           
                                                                         
                                                                       
                                                                     
                    
   
                                                                 
                                                               
                                                          
   
                                                                                       
   
                                                                    
   
                                                                
    
	public function exportForBelastingdienst(string $fromDate, string $toDate, string $format = 'xml'): array {
		$from = $this->parseDate(value: $fromDate, lower: true);
		$to = $this->parseDate(value: $toDate, lower: false);
		if ($from === null || $to === null || $from > $to) {
			throw new OCSBadRequestException('Ongeldige datum range voor de Belastingdienst export.');
		}

		$entries = $this->listEntries(
			filters: [
				'from' => $from->format(DateTimeInterface::ATOM),
				'to' => $to->format(DateTimeInterface::ATOM),
			]
		);

		$manifest = $this->exporter->buildManifest(
			entries: $entries,
			fromDate: $from->format('Y-m-d'),
			toDate: $to->format('Y-m-d')
		);

		$normalizedFormat = strtolower($format);
		$body = $this->exporter->exportAsXml(entries: $entries, manifest: $manifest);
		$contentType = 'application/xml';
		$extension = 'xml';
		if ($normalizedFormat === 'json') {
			$body = $this->exporter->exportAsJson(entries: $entries, manifest: $manifest);
			$contentType = 'application/json';
			$extension = 'json';
		}

		$stamp = $this->now();
		foreach ($entries as $entry) {
			if (($entry['exportedAt'] ?? null) !== null) {
				continue;
			}

			$entry['exportedAt'] = $stamp;
			$entryId = (string)($entry['id'] ?? $entry['uuid'] ?? '');
			if ($entryId === '') {
				continue;
			}

			try {
				$this->saveEntry(id: $entryId, object: $entry);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Pipelinq: kassakoppeling exportedAt stamp failed (export still served)',
					['id' => $entryId, 'exception' => $e->getMessage()]
				);
			}
		}

		$filename = sprintf(
			'kassakoppeling-export-%s-to-%s.%s',
			$from->format('Y-m-d'),
			$to->format('Y-m-d'),
			$extension
		);

		return [
			'body' => $body,
			'contentType' => $contentType,
			'filename' => $filename,
			'entryCount' => count($entries),
		];
	}                                

	   
                                                                      
   
                                                                        
                                                                                
   
                                                          
   
                                                                
   
                                                                
    
	public function getLastEntry(string $registerNumber): ?array {
		if ($registerNumber === '') {
			return null;
		}

		$entries = $this->listEntries(filters: ['registerNumber' => $registerNumber]);
		if ($entries === []) {
			return null;
		}

		return $entries[count($entries) - 1];
	}                    

	   
                                                                    
   
                                                       
                                                                         
                       
   
                                                          
   
                                                                       
    
	private function getLastCurrentHash(string $registerNumber): string {
		$last = $this->getLastEntry(registerNumber: $registerNumber);
		if ($last === null) {
			return KassakoppelingSignatureService::GENESIS_HASH;
		}

		$hash = (string)($last['currentHash'] ?? '');
		if ($hash === '') {
			return KassakoppelingSignatureService::GENESIS_HASH;
		}

		return $hash;
	}                          

	   
                                                                        
   
                                                                   
                                                                         
                                                                         
   
                                                         
   
                                                     
    
	private function sanitiseInput(array $data): array {
		$allowed = [
			'operatorId',
			'registerNumber',
			'action',
			'amount',
			'itemCount',
			'taxAmount',
			'timestamp',
			'transactionUuid',
			'description',
		];

		$entry = [];
		foreach ($allowed as $key) {
			if (array_key_exists($key, $data) === true) {
				$entry[$key] = $data[$key];
			}
		}

		if (isset($entry['timestamp']) === false || $entry['timestamp'] === '') {
			$entry['timestamp'] = $this->now();
		}

		if (isset($entry['amount']) === true) {
			$entry['amount'] = (int)$entry['amount'];
		}

		if (isset($entry['itemCount']) === true) {
			$entry['itemCount'] = (int)$entry['itemCount'];
		}

		if (isset($entry['taxAmount']) === true) {
			$entry['taxAmount'] = (int)$entry['taxAmount'];
		}

		return $entry;
	}                     

	   
                                              
   
                                                           
   
                
   
                                                         
    
	private function validateInput(array $entry): void {
		foreach (['operatorId', 'registerNumber', 'action'] as $field) {
			$value = (string)($entry[$field] ?? '');
			if ($value === '') {
				throw new OCSBadRequestException('Veld "' . $field . '" is verplicht.');
			}
		}

		if (in_array((string)$entry['action'], self::ALLOWED_ACTIONS, true) === false) {
			throw new OCSBadRequestException('Onbekende action; verwacht een van: ' . implode(', ', self::ALLOWED_ACTIONS) . '.');
		}

		if (array_key_exists('amount', $entry) === false) {
			throw new OCSBadRequestException('Veld "amount" is verplicht (cents).');
		}
	}                     

	   
                                                                 
   
                                                                           
                                                                 
   
                                                                  
   
                                                                                                                             
                                                                                                                             
    
	private function applyFilters(array $entries, array $filters): array {
		$register = (string)($filters['registerNumber'] ?? '');
		$operator = (string)($filters['operatorId'] ?? '');
		$action = (string)($filters['action'] ?? '');
		$from = (string)($filters['from'] ?? '');
		$to = (string)($filters['to'] ?? '');

		$kept = [];
		foreach ($entries as $entry) {
			if ($register !== '' && (string)($entry['registerNumber'] ?? '') !== $register) {
				continue;
			}

			if ($operator !== '' && stripos((string)($entry['operatorId'] ?? ''), $operator) === false) {
				continue;
			}

			if ($action !== '' && (string)($entry['action'] ?? '') !== $action) {
				continue;
			}

			$timestamp = (string)($entry['timestamp'] ?? '');
			if ($from !== '' && $timestamp !== '' && strcmp($timestamp, $from) < 0) {
				continue;
			}

			if ($to !== '' && $timestamp !== '' && strcmp($timestamp, $to) > 0) {
				continue;
			}

			$kept[] = $entry;
		}             

		return $kept;
	}                    

	   
                                                                     
   
                                                                   
                                                   
   
                                       
                                                                      
   
                                                                        
    
	private function parseDate(string $value, bool $lower): ?DateTimeImmutable {
		if ($value === '') {
			return null;
		}

		try {
			$parsed = new DateTimeImmutable($value, new DateTimeZone('UTC'));
		} catch (\Throwable $exception) {
			return null;
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
			if ($lower === true) {
				return $parsed->setTime(0, 0, 0);
			}

			return $parsed->setTime(23, 59, 59);
		}

		return $parsed;
	}                 

	   
                                                
   
                                                 
                                                             
   
                                                     
    
	private function saveEntry(string $id, array $object): array {
		[$register, $schema] = $this->config();

		unset($object['@self']);

		$saved = $this->getObjectService()->saveObject(
			object: $object,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $id
		);

		return $this->toArray(object: $saved);
	}                 

	   
                                                       
   
                                                                   
   
                                                                      
    
	private function config(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, self::SCHEMA_KEY, '');

		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('Kassakoppeling register of schema is niet geconfigureerd.');
		}

		return [$register, $schema];
	}              

	   
                                                       
   
                                      
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        

	   
                                                               
   
                                       
   
                                                        
    
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return (array)$object;
	}               

	   
                                   
   
                                 
    
	private function now(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
	}           
}           
