<?php

   
                               
  
                                                                               
  
                                                                               
                                                                              
                                                                                
                                                                                
                                                                                
                                      
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                            
  
                                    
                                                                    
  
                                                                         
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Psr\Log\LoggerInterface;
use Throwable;

   
                                                            
  
                                                                         
   
class LeadClientResolver {

	   
                
   
                                                                                                      
                                                      
    
	public function __construct(
		private readonly ContactSyncService $contactSync,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                                              
   
                                                                         
                                       
   
                                                                  
                                                                  
   
                                                                              
   
                                                                          
    
	public function match(string $title, array $clients): ?string {
		$haystack = mb_strtolower($title);
		$best = null;
		$bestLength = 0;

		foreach ($clients as $client) {
			$name = trim((string)($client['name'] ?? ''));
			$id = (string)($client['id'] ?? '');
			if ($name === '' || $id === '' || mb_strlen($name) <= $bestLength) {
				continue;
			}

			if (str_contains($haystack, mb_strtolower($name)) === true) {
				$best = $id;
				$bestLength = mb_strlen($name);
			}
		}

		return $best;
	}             

	   
                                                 
   
                                                                              
                                                                            
                                                               
   
                                                                   
   
                                                                            
   
                                                                          
    
	public function create(string $name): ?string {
		try {
			$created = $this->contactSync->createWithContact(
				objectType: 'client',
				form: [
					'name' => $name,
					'type' => 'organization',
					'notes' => 'Created by the lead backfill because this lead had no '
						. 'client when client became required. The name is the lead '
						. 'title and probably needs shortening to the company name.',
				]
			);

			$id = (string)($created['id'] ?? '');
			if ($id === '') {
				return null;
			}

			return $id;
		} catch (Throwable $e) {
			$this->logger->warning(
				'LeadClientResolver: could not create a client',
				[
					'name' => $name,
					'error' => $e->getMessage(),
				]
			);

			return null;
		}         
	}              

	   
                                                              
   
                                                                          
                                                        
   
                                                                   
                                                                                     
   
                                                                             
   
                                                                          
    
	public function resolve(string $title, array &$clients): ?string {
		$matched = $this->match(title: $title, clients: $clients);
		if ($matched !== null) {
			return $matched;
		}

		                                                                       
		                                                                     
		                                                                    
		                                                                  
		                                                                     
		                                                                        
		                                                                    
		                                                                      
		                                                            
		$name = $title;
		if ($name === '') {
			$name = 'Unknown client';
		}

		$created = $this->create(name: $name);
		if ($created !== null) {
			$clients[] = [
				'id' => $created,
				'name' => $title,
			];
		}

		return $created;
	}               
}           
