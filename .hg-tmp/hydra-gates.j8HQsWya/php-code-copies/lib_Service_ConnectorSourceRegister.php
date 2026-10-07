<?php

   
                                    
  
                                                                         
                                                                            
                                          
  
                                                                               
                                                                              
                                                                               
                                                                    
  
                                                                               
                                                                            
                                                                             
                                                                              
                                                                              
                                                                             
  
                                                                              
                                                                       
                          
  
                                    
                                               
  
                    
                                 
  
                                                             
                                  
                                                                                    
  
                         
  
                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use Psr\Log\LoggerInterface;

   
                                                                                
  
                                                                                
                                                                               
                                                                                 
                                                                       
                                                                             
  
                                                                                  
                                                                 
                                               
   
class ConnectorSourceRegister {

	   
                                                                               
   
                                                                       
                                                                             
                          
   
               
    
	public const CANONICAL_REGISTER = 'integriq';

	   
                                             
   
                                                                             
                    
   
               
    
	public const SOURCE_SCHEMA = 'source';

	   
                
   
                                                                                           
                                                                                            
    
	public function __construct(
		private readonly RegisterSlugResolverInterface $slugResolver,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                                                     
   
                                                                                
                                                                               
                                                                           
   
                                                                          
                                                                       
                                                     
   
                                                                          
                                                                           
   
                                                                        
                                                                              
   
                                                                                      
                                                                                   
                                                            
    
	public function slugOrNull(string $operation, string $sourceId=''): ?string {
		$resolution = $this->slugResolver->resolve(canonical: self::CANONICAL_REGISTER);
		if ($resolution->isResolved() === true) {
			return $resolution->slug;
		}

		$this->logger->warning(
			'Pipelinq: the connector source register is not on this instance under any of its known slugs '
			. '(' . implode(', ', $resolution->candidates) . ') — the read was skipped rather than run against '
			. 'a slug nothing answers to, which would have returned zero rows and read as "no source".',
			['operation' => $operation, 'sourceId' => $sourceId]
		);

		return null;
	}                  
}           
