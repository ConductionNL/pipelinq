<?php

   
                                   
  
                                                                             
                                                          
  
                                                                              
                                                                              
                                                                             
                                                                           
                
  
                                                                          
                                                                             
                                                     
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

use OCA\Pipelinq\Service\Marketing\ListObjectStore;

   
                                    
  
                                                                                                                                       
   
class SocialPublicationStore {
	   
                                                                
   
               
    
	public const SCHEMA = 'socialPublication';

	   
                                                     
   
               
    
	public const SCHEMA_CONFIG_KEY = 'social_publication_schema';

	   
                              
   
               
    
	public const PENDING = 'pending';

	   
                            
   
               
    
	public const PUBLISHED = 'published';

	   
                                                  
   
               
    
	public const FAILED = 'failed';

	   
                                                              
   
               
    
	public const AWAITING_SHARE = 'awaiting_share';

	   
                                                  
   
               
    
	public const SHARED = 'shared';

	   
                
   
                                                                      
   
                
    
	public function __construct(private readonly ListObjectStore $store) {
	}                   

	   
                                                      
   
                                   
   
                                                                                                                                        
    
	public function schema(): string {
		return $this->store->schemaSlug(configKey: self::SCHEMA_CONFIG_KEY, default: self::SCHEMA);
	}              

	   
                                   
   
                                   
   
                                                              
   
                                                                                                                                        
    
	public function forPost(string $postId): array {
		return $this->store->findAll(schemaSlug: $this->schema(), filters: ['postId' => $postId]);
	}               

	   
                                                            
   
                                                            
   
                                                              
   
                                                                                                                                                
    
	public function findAll(array $filters = []): array {
		return $this->store->findAll(schemaSlug: $this->schema(), filters: $filters);
	}               

	   
                             
   
                                                 
   
                                                               
   
                                                                                                                                        
    
	public function find(string $publicationId): ?array {
		return $this->store->find(schemaSlug: $this->schema(), id: $publicationId);
	}            

	   
                                                                           
                                                   
   
                                                                         
                                                              
   
                                   
                                         
                                                                                                  
   
                                                                                    
   
                                                                                                                                        
    
	public function open(string $postId, string $accountId, string $network): ?array {
		foreach ($this->forPost(postId: $postId) as $row) {
			if ((string)($row['accountId'] ?? '') === $accountId) {
				return $row;
			}
		}

		return $this->store->save(
			schemaSlug: $this->schema(),
			payload: [
				'postId' => $postId,
				'accountId' => $accountId,
				'network' => $network,
				'status' => self::PENDING,
				'metrics' => ['views' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'clicks' => 0],
				'cost' => 0,
				'attempts' => 0,
				'failureCode' => '',
				'failureReason' => '',
			],
		);
	}            

	   
                                      
   
                                                                  
   
                                                             
   
                                                                                                                                        
    
	public function save(array $publication): ?array {
		return $this->store->save(
			schemaSlug: $this->schema(),
			payload: $publication,
			id: $this->store->idOf(payload: $publication),
		);
	}            

	   
                                          
   
                                                          
   
                                              
   
                                                                                                                                        
    
	public function idOf(?array $publication): string {
		return $this->store->idOf(payload: $publication);
	}            

	   
                                        
   
                                                             
                                                               
   
                                                             
   
                                                                                                                                        
    
	public function record(array $publication, SocialPublishOutcome $outcome): ?array {
		$publication['attempts'] = ((int)($publication['attempts'] ?? 0) + 1);
		$publication['cost'] = ((float)($publication['cost'] ?? 0) + $outcome->cost);

		if ($outcome->accepted === false) {
			$publication['status'] = self::FAILED;
			$publication['failureCode'] = $outcome->failureCode;
			$publication['failureReason'] = $outcome->failureReason;

			return $this->save(publication: $publication);
		}

		$publication['status'] = self::PUBLISHED;
		$publication['externalId'] = $outcome->externalId;
		$publication['url'] = $outcome->url;
		$publication['publishedAt'] = gmdate('Y-m-d\TH:i:s\Z');
		$publication['failureCode'] = '';
		$publication['failureReason'] = '';

		return $this->save(publication: $publication);
	}              
}           
