<?php

   
                                 
  
                                                                             
                                                                         
                                                                             
                                                             
  
                                                                             
                                                                         
                                                                             
                                                                       
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                        
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

   
                                                          
  
                                                                                                                        
   
class SocialPublishRequest {
	   
                
   
                                                            
                                                                                
                                                                                            
                                                                              
                                                                                         
                                                                                                         
                                                                                                 
                                                                                           
                                                                                                           
   
                
    
	public function __construct(
		public readonly string $network,
		public readonly string $body,
		public readonly string $link = '',
		public readonly array $media = [],
		public readonly string $credentialRef = '',
		public readonly string $externalAccountId = '',
		public readonly string $accountKind = 'organisation',
		public readonly string $handle = '',
		public readonly ?string $actingUserId = null,
	) {
	}                   

	   
                                                                            
                                             
   
                                                                       
                                                                 
   
                                       
   
                                                                                                                     
    
	public function bodyWithLink(): string {
		$text = trim($this->body);
		if ($this->link === '' || str_contains($text, $this->link) === true) {
			return $text;
		}

		if ($text === '') {
			return $this->link;
		}

		return $text . "\n\n" . $this->link;
	}                    

	   
                                                                           
   
                                                                          
                                                                      
   
                                                                                 
   
                                                                                                                                                
    
	public function firstPublicMedia(): ?array {
		foreach ($this->media as $item) {
			if (is_array($item) === true && trim((string)($item['url'] ?? '')) !== '') {
				return $item;
			}
		}

		return null;
	}                        
}           
