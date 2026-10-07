<?php

   
                             
  
                                                                    
                                                                        
                                                                         
                                                                        
                                                                        
                                                             
                                                                         
                                                                       
                                
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                                       
                                                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DOMDocument;
use Psr\Log\LoggerInterface;
use RuntimeException;

   
                                                                
  
                                                             
   
class TemplateRenderer {
	   
                                       
   
            
    
	public const MAX_SUBJECT_CHARS = 200;

	   
                
   
                                          
    
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                      
   
                                                                        
                                                         
                                                   
   
                                                                   
   
                                                                       
                                                              
    
	public function render(array $template, array $variables): array {
		$deepLink = null;
		$requires = (bool)($template['requiresDeepLink'] ?? false);
		if ($requires === true) {
			$deepLink = $this->buildDeepLink(template: $template, variables: $variables);
			if ($deepLink !== null) {
				$variables['deepLink'] = $deepLink;
			}
		}

		$subject = $this->renderString(source: (string)($template['subject'] ?? ''), variables: $variables);
		if (mb_strlen($subject) > self::MAX_SUBJECT_CHARS) {
			$subject = mb_substr($subject, 0, self::MAX_SUBJECT_CHARS);
		}

		$body = $this->renderString(source: (string)($template['body'] ?? ''), variables: $variables);

		$this->assertValidXhtml(body: $body);

		return [
			'subject' => $subject,
			'body' => $body,
			'deepLink' => $deepLink,
		];
	}              

	   
                                                                   
                                     
   
                                            
                                                                               
   
                       
   
                                           
    
	public function buildDeepLink(array $template, array $variables): ?string {
		$base = (string)($template['deepLinkBase'] ?? '');
		if ($base === '') {
			$this->logger->warning(
				'BerichtenboxTemplate requiresDeepLink=true but deepLinkBase is empty.'
			);
			return null;
		}

		$query = http_build_query(
			[
				                                                                 
				                                                                   
				                                                              
				                                       
				'zaakId' => (string)($variables['caseId'] ?? ''),
				'status' => (string)($variables['status'] ?? ''),
				'ref' => (string)($variables['messageId'] ?? ''),
			]
		);
		$sep = '?';
		if (str_contains($base, '?') === true) {
			$sep = '&';
		}

		return $base . $sep . $query;
	}                     

	   
                                                                     
   
                                          
                                 
   
                  
    
	private function renderString(string $source, array $variables): string {
		                                                                    
		$result = preg_replace_callback(
			'/\{\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}\}/',
			static function (array $matches) use ($variables): string {
				return (string)($variables[$matches[1]] ?? '');
			},
			$source
		);
		if ($result === null) {
			$result = $source;
		}

		$result = preg_replace_callback(
			'/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/',
			static function (array $matches) use ($variables): string {
				$value = (string)($variables[$matches[1]] ?? '');
				return htmlspecialchars($value, ENT_XHTML | ENT_QUOTES, 'UTF-8');
			},
			$result
		);

		return ($result ?? $source);
	}                    

	   
                                                       
   
                                     
   
                
   
                                              
    
	private function assertValidXhtml(string $body): void {
		$previous = libxml_use_internal_errors(true);
		try {
			$doc = new DOMDocument();
			$wrapped = '<?xml version="1.0" encoding="UTF-8"?><root>' . $body . '</root>';
			if ($doc->loadXML($wrapped) === false) {
				throw new RuntimeException('Rendered body is not valid XHTML strict.');
			}
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
	}                        
}           
