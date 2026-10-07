<?php

   
                                 
  
                                                                              
                                                                           
                           
  
                                                                              
                                                                              
                                                                               
                                                                              
                                                                            
  
                                                                               
                                                                              
                                                             
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Search;

use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use UnexpectedValueException;

   
                                                                            
  
                                                                                                                                                                                   
   
class KeywordTargetService {

	   
                                                   
   
               
    
	public const SCHEMA_SLUG = 'keywordTarget';

	   
                                                     
   
               
    
	public const SCHEMA_CONFIG_KEY = 'keywordTarget_schema';

	   
                                     
   
                           
    
	public const STATUSES = ['use-more', 'use-less', 'watch'];

	   
                                                     
   
                           
    
	public const PROPOSAL_KINDS = ['striking-distance', 'cannibalisation', 'content-gap', 'manual'];

	   
                                                  
   
                           
    
	public const INTENTS = ['informational', 'navigational', 'commercial', 'transactional'];

	   
                
   
                                                                
                                                                      
   
                                                                                                                                                                                    
    
	public function __construct(
		private ListObjectStore $store,
		private ITimeFactory $time,
	) {
	}                   

	   
                         
   
                                                         
   
                                                                                                                                                                                    
    
	public function all(): array {
		return $this->store->findAll(schemaSlug: $this->schemaSlug());
	}           

	   
                                                                         
                                     
   
                                                   
   
                                                                                                                                                                                    
    
	public function confirmedTerms(): array {
		$terms = [];
		foreach ($this->all() as $target) {
			$term = mb_strtolower(trim((string)($target['term'] ?? '')), 'UTF-8');
			if ($term !== '') {
				$terms[$term] = true;
			}
		}

		return array_keys($terms);
	}                      

	   
                                             
   
                                                                                                                                                
                                              
   
                                                                                              
   
                                                                                             
   
                                                                                                                                                                                    
    
	public function confirm(array $payload, string $uid): ?array {
		$term = trim((string)($payload['term'] ?? ''));
		if ($term === '') {
			throw new UnexpectedValueException('A keyword target needs a term.');
		}

		$record = [
			'term' => $term,
			'status' => $this->oneOf(value: (string)($payload['status'] ?? 'watch'), allowed: self::STATUSES, field: 'status'),
			'proposalKind' => $this->oneOf(
				value: (string)($payload['proposalKind'] ?? 'manual'),
				allowed: self::PROPOSAL_KINDS,
				field: 'proposalKind'
			),
			'createdBy' => $uid,
			'createdAt' => gmdate('Y-m-d\TH:i:s\Z', $this->time->getTime()),
		];

		$intent = trim((string)($payload['intent'] ?? ''));
		if ($intent !== '') {
			$record['intent'] = $this->oneOf(value: $intent, allowed: self::INTENTS, field: 'intent');
		}

		foreach (['targetPageRef', 'property', 'notes'] as $optional) {
			$value = trim((string)($payload[$optional] ?? ''));
			if ($value !== '') {
				$record[$optional] = $value;
			}
		}

		return $this->store->save(schemaSlug: $this->schemaSlug(), payload: $record);
	}               

	   
                           
   
                  
   
                                                                                                                                                                                    
    
	public function schemaSlug(): string {
		return $this->store->schemaSlug(configKey: self::SCHEMA_CONFIG_KEY, default: self::SCHEMA_SLUG);
	}                  

	   
                                                                         
   
                                   
                                                      
                                                         
   
                             
   
                                                               
    
	private function oneOf(string $value, array $allowed, string $field): string {
		$trimmed = trim($value);
		if (in_array($trimmed, $allowed, true) === false) {
			throw new UnexpectedValueException(
				'The ' . $field . ' must be one of ' . implode(', ', $allowed) . '.'
			);
		}

		return $trimmed;
	}             
}           
