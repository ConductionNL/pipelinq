<?php

   
                            
  
                                                                              
                                                                            
                                                                             
                                   
  
                    
                                 
  
                                                              
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                                                                                                  
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IL10N;
use OCP\Mail\IMessage;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                      
  
                                                                                                                                                                  
   
class UnsubscribeMail {

	   
                                                                               
    
	public const HEADER_HELPER = 'OCA\\OpenRegister\\Service\\Notification\\UnsubscribeHeaders';

	   
                
   
                                                                        
                                                                  
                                                                       
    
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                                                       
   
                                                                     
   
                            
   
                                                                                                                                                                   
    
	public function bodyLine(?array $unsubscribe): string {
		$url = trim((string)($unsubscribe['url'] ?? ''));
		if ($url === '') {
			return '';
		}

		return $this->l10n->t('Wilt u deze berichten niet meer ontvangen? Meld u af: %s', [$url]);
	}                

	   
                                                                                          
   
                                                          
                                                                     
   
                            
   
                                                                                                                                                                   
    
	public function appendLine(string $body, ?array $unsubscribe): string {
		$line = $this->bodyLine(unsubscribe: $unsubscribe);
		if ($line === '') {
			return $body;
		}

		return rtrim($body)."\n\n".$line."\n";
	}                  

	   
                                                   
   
                                                             
                                                                                                         
   
                                                 
   
                                                                                                                                                                   
    
	public function applyHeaders(IMessage $message, ?array $unsubscribe): bool {
		if ($unsubscribe === null || $unsubscribe === []) {
			return false;
		}

		try {
			$helper = $this->container->get(self::HEADER_HELPER);
		} catch (Throwable $e) {
			$this->logger->info('Pipelinq: OpenRegister has no UnsubscribeHeaders helper; the mail carries the body link only');
			return false;
		}

		if (is_object($helper) === false || method_exists($helper, 'apply') === false) {
			return false;
		}

		try {
			return ($helper->apply($message, $unsubscribe) === true);
		} catch (Throwable $e) {
			return false;
		}
	}                    
}           
