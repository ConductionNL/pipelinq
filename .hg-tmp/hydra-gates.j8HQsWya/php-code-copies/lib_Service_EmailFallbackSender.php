<?php

   
                                
  
                                                                         
                                                                  
                                                                    
                                                                   
                                                           
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                                      
                                                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use RuntimeException;

   
                                                     
  
                                                             
   
class EmailFallbackSender {
	   
                                                                     
                                              
   
               
    
	public const FALLBACK_NOTICE = 'Dit bericht is ook beschikbaar in uw MijnOverheid Berichtenbox.';

	   
                                                   
   
               
    
	public const CONFIG_FROM_ADDRESS = 'berichtenbox_fallback_from';

	   
                
   
                                            
                                            
                                          
                                                                                     
                                                                                             
    
	public function __construct(
		private readonly IMailer $mailer,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly IntegriqConsentClient $integriq,
		private readonly UnsubscribeMail $unsubscribeMail,
	) {
	}                   

	   
                                                                    
   
                                              
    
	private ?array $lastRefusal = null;

	   
                                                                                    
   
                                                              
   
                                                                                                                                                               
    
	public function lastRefusal(): ?array {
		return $this->lastRefusal;
	}                   

	   
                                                                 
   
                                                    
   
                                                              
    
	private function categoryOf(array $message): string {
		$category = trim((string)($message['category'] ?? ''));
		if ($category === '') {
			return IntegriqConsentClient::CATEGORY_SERVICE;
		}

		return $category;
	}                  

	   
                                                                    
   
                                                                 
                                                
                                                                      
                                                                        
                                                                 
                                                                   
                                                
   
                                                       
   
                                                                 
   
                                                                                                        
                                                                                                                                                               
   
                                                                                  
                                                                            
                                                   
    
	public function send(array $message, string $toEmail, bool $appendNotice = true): bool {
		if ($toEmail === '' || $this->mailer->validateMailAddress($toEmail) === false) {
			throw new RuntimeException('Invalid recipient address.');
		}

		                                                                    
		                                                                     
		                               
		$this->lastRefusal = null;
		$category = $this->categoryOf(message: $message);

		$decision = $this->integriq->decideOne(channel: 'email', category: $category, requiresConsent: false, address: $toEmail);
		if ($decision['send'] === false) {
			$this->lastRefusal = ['code' => $decision['code'], 'reason' => $decision['reason']];
			$this->logger->warning(
				'Berichtenbox fallback email not sent: integriq refused it.',
				['messageId' => (string)($message['uuid'] ?? ''), 'code' => $decision['code'], 'category' => $category]
			);
			return false;
		}

		$subject = (string)($message['subject'] ?? '');
		$body = (string)($message['body'] ?? '');
		if ($appendNotice === true) {
			$body = '<p>' . htmlspecialchars(self::FALLBACK_NOTICE, ENT_XHTML, 'UTF-8') . '</p>' . $body;
		}

		$line = $this->unsubscribeMail->bodyLine(unsubscribe: $decision['unsubscribe']);
		if ($line !== '') {
			$body .= '<p>' . htmlspecialchars($line, ENT_XHTML, 'UTF-8') . '</p>';
		}

		try {
			$msg = $this->mailer->createMessage();
			$msg->setTo([$toEmail]);
			$msg->setSubject($subject);
			$msg->setHtmlBody($body);
			$msg->setPlainBody(strip_tags($body));

			$from = $this->appConfig->getValueString(
				Application::APP_ID,
				self::CONFIG_FROM_ADDRESS,
				''
			);
			if ($from !== '' && $this->mailer->validateMailAddress($from) === true) {
				$msg->setFrom([$from]);
			}

			$this->unsubscribeMail->applyHeaders(message: $msg, unsubscribe: $decision['unsubscribe']);

			$failed = $this->mailer->send($msg);
			return empty($failed) === true;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Berichtenbox fallback email dispatch failed.',
				['exception' => $e->getMessage()]
			);
			throw new RuntimeException('Fallback email dispatch failed: ' . $e->getMessage(), 0, $e);
		}         
	}            
}           
