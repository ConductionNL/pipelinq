<?php

   
                               
  
                                                                         
                                                                           
                                                                        
                                                                         
                         
  
                                                                            
                                                                      
                                                                           
                                                
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Marketing\ListObjectStore;

   
                                                              
  
                                                                                                                          
   
class MailingListService {
	   
                                                                    
   
               
    
	public const OPT_IN_DOUBLE = 'double';

	   
                                                                  
                                    
   
               
    
	public const OPT_IN_SOFT = 'soft';

	   
                                                                    
   
               
    
	private const DEFAULT_MAILING_LIST_SCHEMA_SLUG = 'mailingList';

	   
                                    
   
                           
    
	private const OPT_IN_MODES = [self::OPT_IN_DOUBLE, self::OPT_IN_SOFT];

	   
                             
   
                           
    
	private const STATUSES = ['active', 'paused', 'archived'];

	   
                
   
                                                                              
   
                                                                                                                           
    
	public function __construct(
		private ListObjectStore $store,
	) {
	}                   

	   
                                                  
   
                                                           
                                                 
   
                                                                                                                           
   
                                                                                                                           
    
	public function listMailingLists(int $page, int $limit): array {
		$page = max(1, $page);
		$limit = min(100, max(1, $limit));
		$all = $this->loadAll();
		$total = count($all);

		return [
			'data' => array_slice($all, (($page - 1) * $limit), $limit),
			'pagination' => [
				'page' => $page,
				'limit' => $limit,
				'total' => $total,
				'pages' => $this->computePages(total: $total, limit: $limit),
			],
		];
	}                        

	   
                                           
   
                                                   
   
                                                                       
                                                     
   
                                                                                                                           
    
	public function getMailingListById(string $listId): ?array {
		return $this->store->find(schemaSlug: $this->getMailingListSchemaSlug(), id: $listId);
	}                          

	   
                                           
   
                                                            
                                               
   
                                                         
                                                      
   
                                                              
   
                                                                                                                           
    
	public function createMailingList(array $payload, string $createdByUid): array {
		$error = $this->validatePayload(payload: $payload);
		if ($error !== null) {
			return ['error' => $error];
		}

		$now = $this->nowIso();
		$record = [
			'name' => trim((string)$payload['name']),
			'description' => (string)($payload['description'] ?? ''),
			'optInMode' => $this->normaliseOptInMode(value: ($payload['optInMode'] ?? '')),
			'senderName' => trim((string)$payload['senderName']),
			'senderEmail' => trim((string)$payload['senderEmail']),
			'replyTo' => trim((string)($payload['replyTo'] ?? '')),
			'publicSignup' => (bool)($payload['publicSignup'] ?? false),
			'footerAddress' => trim((string)$payload['footerAddress']),
			'status' => $this->normaliseStatus(value: ($payload['status'] ?? '')),
			'createdBy' => $createdByUid,
			'createdAt' => $now,
			'updatedAt' => $now,
		];

		$saved = $this->save(payload: $record);
		if ($saved === null) {
			return ['error' => 'The mailing list could not be saved'];
		}

		return ['list' => $saved];
	}                         

	   
                                           
   
                                                                       
                                                          
   
                                                   
                                                        
   
                                                              
   
                                                                                                                           
    
	public function updateMailingList(string $listId, array $patch): array {
		$existing = $this->getMailingListById(listId: $listId);
		if ($existing === null) {
			return ['error' => 'Not found'];
		}

		$merged = $existing;
		foreach (['name', 'description', 'senderName', 'senderEmail', 'replyTo', 'footerAddress'] as $key) {
			if (isset($patch[$key]) === true) {
				$merged[$key] = trim((string)$patch[$key]);
			}
		}

		if (isset($patch['publicSignup']) === true) {
			$merged['publicSignup'] = (bool)$patch['publicSignup'];
		}

		if (isset($patch['optInMode']) === true) {
			$merged['optInMode'] = $this->normaliseOptInMode(value: $patch['optInMode']);
		}

		if (isset($patch['status']) === true) {
			$merged['status'] = $this->normaliseStatus(value: $patch['status']);
		}

		$error = $this->validatePayload(payload: $merged);
		if ($error !== null) {
			return ['error' => $error];
		}

		$merged['updatedAt'] = $this->nowIso();

		$saved = $this->save(payload: $merged, id: $this->extractObjectId(payload: $existing));
		if ($saved === null) {
			return ['error' => 'The mailing list could not be saved'];
		}

		return ['list' => $saved];
	}                         

	   
                                                   
   
                                                                      
                                                
   
                                                            
   
                                                                    
   
                                                                                                                           
    
	public function acceptsPublicSignup(?array $list): bool {
		if ($list === null) {
			return false;
		}

		if ((bool)($list['publicSignup'] ?? false) === false) {
			return false;
		}

		return (string)($list['status'] ?? 'active') === 'active';
	}                           

	   
                                                     
   
                                                                    
                                                                       
                                        
   
                                                            
   
                                                                
   
                                                                                                                      
    
	public function publicProjection(array $list): array {
		return [
			'id' => $this->extractObjectId(payload: $list),
			'name' => (string)($list['name'] ?? ''),
			'description' => (string)($list['description'] ?? ''),
		];
	}                        

	   
                                                             
   
                                                                
                                                                    
                                                                       
                                                                
                                                      
   
                                                              
   
                                                          
   
                                                                                                                           
    
	public function validatePayload(array $payload): ?string {
		if (trim((string)($payload['name'] ?? '')) === '') {
			return 'A mailing list needs a name';
		}

		if (trim((string)($payload['senderName'] ?? '')) === '') {
			return 'A mailing list needs a sender name';
		}

		$senderEmail = trim((string)($payload['senderEmail'] ?? ''));
		if (filter_var($senderEmail, FILTER_VALIDATE_EMAIL) === false) {
			return 'A mailing list needs a valid sender address';
		}

		$replyTo = trim((string)($payload['replyTo'] ?? ''));
		if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false) {
			return 'The reply-to address is not a valid email address';
		}

		if (trim((string)($payload['footerAddress'] ?? '')) === '') {
			return 'A mailing list needs a postal address for the footer';
		}

		return null;
	}                       

	   
                                                        
   
                               
   
                                                                                                                           
    
	public function getMailingListSchemaSlug(): string {
		return $this->store->schemaSlug(
			configKey: 'mailing_list_schema',
			default: self::DEFAULT_MAILING_LIST_SCHEMA_SLUG,
		);
	}                                

	   
                                                           
   
                                                                    
                                                                        
                      
   
                                          
   
                                             
    
	private function normaliseOptInMode(mixed $value): string {
		$mode = strtolower(trim((string)$value));
		if (in_array($mode, self::OPT_IN_MODES, true) === true) {
			return $mode;
		}

		return self::OPT_IN_DOUBLE;
	}                          

	   
                                                      
   
                                          
   
                                                
    
	private function normaliseStatus(mixed $value): string {
		$status = strtolower(trim((string)$value));
		if (in_array($status, self::STATUSES, true) === true) {
			return $status;
		}

		return 'active';
	}                       

	   
                                                  
   
                                                            
    
	private function loadAll(): array {
		return $this->store->findAll(schemaSlug: $this->getMailingListSchemaSlug());
	}               

	   
                                                 
   
                                                              
                                                     
   
                                                                    
    
	private function save(array $payload, ?string $id = null): ?array {
		return $this->store->save(
			schemaSlug: $this->getMailingListSchemaSlug(),
			payload: $payload,
			id: $id,
		);
	}            

	   
                                                        
   
                                      
                                
   
                                                  
    
	private function computePages(int $total, int $limit): int {
		if ($total <= 0 || $limit <= 0) {
			return 0;
		}

		return (int)ceil($total / $limit);
	}                    

	   
                                                            
   
                                      
    
	private function nowIso(): string {
		return gmdate('Y-m-d\TH:i:s\Z');
	}              



	   
                                                                 
   
                                                        
   
                                                  
   
                                                                                                                           
    
	public function extractObjectId(array $payload): string {
		return $this->store->idOf(payload: $payload);
	}                       
}           
