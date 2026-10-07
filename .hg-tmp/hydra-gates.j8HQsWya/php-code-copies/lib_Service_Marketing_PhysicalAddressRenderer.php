<?php

   
                                    
  
                                                                             
                                                                          
                                                             
  
                    
                                           
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

   
                                                              
  
                                                                                                               
   
class PhysicalAddressRenderer {
	   
                                                                      
                                                                    
                                                           
   
                           
    
	public const TOKENS = [
		'{{physical_address}}',
		'{{sender_address}}',
		'{{company_address}}',
		'{{address_block}}',
	];

	   
                                                                  
   
                                                                          
                                                                           
                                                                         
                                                                          
                                                                          
           
   
                                                    
                                                                  
                                                                                               
   
                                                      
   
                                                                                                                
    
	public function render(string $body, string $footerOverride, string $format): string {
		$address = trim($footerOverride);
		if ($address === '' || trim($body) === '') {
			return $body;
		}

		$rendered = $address;
		if ($format === 'html') {
			$rendered = nl2br(htmlspecialchars($address, ENT_QUOTES | ENT_HTML5, 'UTF-8'), false);
		}

		foreach (self::TOKENS as $token) {
			if (str_contains($body, $token) === true) {
				return strtr($body, array_fill_keys(self::TOKENS, $rendered));
			}
		}

		if ($format === 'html') {
			                                                       
			$paragraph = '<p>' . $rendered . '</p>';
			$close = strripos($body, '</body>');
			if ($close !== false) {
				return substr($body, 0, $close) . $paragraph . substr($body, $close);
			}

			return $body . $paragraph;
		}

		return rtrim($body) . "\n\n" . $rendered;
	}              
}           
