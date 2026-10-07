<?php

   
                              
  
                                                                              
                                                                            
                                                                                
                                                                             
                                                                       
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

   
                                     
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalMailService {
	   
                
   
                                                
                                                         
                                                
                                              
    
	public function __construct(
		private IMailer $mailer,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                          
   
                                                 
                                                                          
                                             
                                                 
                                                  
   
                                                                 
                                                                                         
                                                                            
                                                                            
                               
    
	public function sendTokenLink(
		string $recipient,
		string $route,
		string $token,
		string $subject,
		string $intro,
	): bool {
		$link = $this->urlGenerator->getAbsoluteURL($route) . '?token=' . rawurlencode($token);
		$body = $intro . "\n\n" . $link . "\n\n" . $this->l10n->t('If you did not request this, you can ignore this email.');

		return $this->send(recipient: $recipient, subject: $subject, body: $body);
	}                     

	   
                                       
   
                                                 
                                                 
                                                      
   
                                                 
                                                                                         
                                                                            
                                                                            
                               
    
	public function send(string $recipient, string $subject, string $body): bool {
		if ($this->mailer->validateMailAddress($recipient) === false) {
			return false;
		}

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$recipient]);
			$message->setSubject($subject);
			$message->setPlainBody($body);
			$failed = $this->mailer->send($message);
			return empty($failed) === true;
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq portal: mail send failed', ['exception' => $e->getMessage()]);
			return false;
		}
	}            
}           
