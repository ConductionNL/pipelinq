<?php

   
                                 
  
                                                                        
                                                                             
                                                                           
                                                                        
                                                               
                                                                     
                                                                          
                                                                     
                           
  
                                                                          
                                                                         
                                                                           
                                                                            
                                                                            
                                          
  
                    
                                                     
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing\Transport;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                       
  
                                                                                                                                                                      
   
final class MailAccountTransport implements TransportInterface {
	   
                                                                          
                                                                      
                                                                  
               
   
                              
    
	private const MAIL_CLASSES = [
		'accounts' => '\OCA\Mail\Service\AccountService',
		'outbox' => '\OCA\Mail\Service\OutboxService',
		'localMessage' => '\OCA\Mail\Db\LocalMessage',
	];

	   
                
   
                                                                                     
                                              
                                                                                 
                                                                                       
    
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly string $mailAccountRef,
		private readonly string $mailAccountUserId,
	) {
	}                   

	   
                                       
   
                                                        
   
                                   
   
                                                                                                                                                                       
    
	public function send(RenderedMail $mail): SendResult {
		$accountId = $this->parseAccountId();
		if ($accountId === null || $this->mailAccountUserId === '') {
			$this->logger->warning(
				'MailAccountTransport.send: malformed mailAccountRef/mailAccountUserId',
				['deliveryId' => $mail->deliveryId, 'mailAccountRef' => $this->mailAccountRef]
			);
			return new SendResult(accepted: false, error: 'malformed-mail-account-reference');
		}

		$accountService = $this->resolve(key: 'accounts');
		$outboxService = $this->resolve(key: 'outbox');
		if ($accountService === null || $outboxService === null) {
			$this->logger->info(
				'MailAccountTransport.send: Mail app unavailable',
				['deliveryId' => $mail->deliveryId]
			);
			return new SendResult(accepted: false, error: 'mail-app-not-available');
		}

		try {
			$account = $accountService->find($this->mailAccountUserId, $accountId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'MailAccountTransport.send: account lookup failed',
				['deliveryId' => $mail->deliveryId, 'exception' => $e->getMessage()]
			);
			return new SendResult(accepted: false, error: 'mail-account-not-found');
		}

		return $this->sendViaOutbox(
			outboxService: $outboxService,
			account: $account,
			mail: $mail,
		);
	}            

	   
                                                               
   
                                                          
                                              
                                                        
   
                                   
    
	private function sendViaOutbox(object $outboxService, object $account, RenderedMail $mail): SendResult {
		$localMessageClass = self::MAIL_CLASSES['localMessage'];
		if (class_exists($localMessageClass) === false) {
			return new SendResult(accepted: false, error: 'mail-app-not-available');
		}

		try {
			$localMessage = new $localMessageClass();
			$localMessage->setSubject($mail->subject);
			$localMessage->setBodyPlain($mail->text);
			$localMessage->setHtml(true);
			$localMessage->setBodyHtml($mail->html);

			$saved = $outboxService->saveMessage(
				$account,
				$localMessage,
				[['email' => $mail->toEmail]],
				[],
				[],
			);
			$outboxService->sendMessage($saved, $account);
		} catch (Throwable $e) {
			$this->logger->warning(
				'MailAccountTransport.send: outbox send failed',
				['deliveryId' => $mail->deliveryId, 'exception' => $e->getMessage()]
			);
			return new SendResult(accepted: false, error: 'mail-account-send-failed');
		}

		return new SendResult(accepted: true);
	}                     

	   
                                                                     
   
                                                                          
                                                        
    
	private function parseAccountId(): ?int {
		if ($this->mailAccountRef === '' || ctype_digit($this->mailAccountRef) === false) {
			return null;
		}

		return (int)$this->mailAccountRef;
	}                      

	   
                                                                        
                   
   
                                                    
   
                                                                          
                                                    
    
	private function resolve(string $key): ?object {
		$class = (self::MAIL_CLASSES[$key] ?? '');
		if ($class === '' || class_exists($class) === false) {
			return null;
		}

		try {
			$service = $this->container->get($class);
		} catch (Throwable $e) {
			$this->logger->debug(
				'MailAccountTransport.resolve: container resolution failed',
				['class' => $class, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if (is_object($service) === false) {
			return null;
		}

		return $service;
	}               
}           
