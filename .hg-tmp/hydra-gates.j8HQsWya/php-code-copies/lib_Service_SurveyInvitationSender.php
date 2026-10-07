<?php

   
                                   
  
                                                                             
                                                                               
                                                                      
                                                          
  
                                                                             
                                                                               
                                                  
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                                                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                          
  
                                                                                                                                                    
   
class SurveyInvitationSender {
	   
                
   
                                              
                                                                         
                                              
    
	public function __construct(
		private readonly IMailer $mailer,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                                  
   
                                                  
   
                           
    
	public function linkFor(string $token): string {
		return $this->urlGenerator->getAbsoluteURL(
			'/index.php/apps/pipelinq/survey/i/' . rawurlencode($token)
		);
	}               

	   
                        
   
                                                           
                                                                         
                                                                
   
                                                  
   
                                                                                                                                                                    
    
	public function send(array $invitation, string $address = ''): bool {
		$channel = trim((string)($invitation['channel'] ?? 'email'));
		if ($channel !== 'email') {
			                                                                   
			                                                                 
			                                                        
			$this->logger->info(
				'SurveyInvitationSender: no transport configured for this channel',
				['channel' => $channel]
			);

			return false;
		}

		if ($address === '') {
			$address = (string)($invitation['deliveryAddress'] ?? '');
		}

		$address = trim($address);
		if ($address === '' || $this->mailer->validateMailAddress($address) === false) {
			return false;
		}

		$link = $this->linkFor(token: (string)($invitation['token'] ?? ''));

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$address]);
			$message->setSubject('How did we do?');
			$message->setHtmlBody(
				'<p>We would like to know how the last contact went.</p>'
				. '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">'
				. 'Answer a few questions</a></p>'
			);
			$message->setPlainBody(
				"We would like to know how the last contact went.\n\n" . $link
			);

			$this->mailer->send($message);

			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				'SurveyInvitationSender: the invitation could not be sent',
				['exception' => $e->getMessage()]
			);

			return false;
		}
	}            
}           
