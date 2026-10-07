<?php

   
                                    
  
                                                                             
                                                                        
                                                                          
                                
  
                                                                      
                                                                            
                                                                 
                                                                           
                                                                             
                                                                       
                                                                        
                                                                         
                                                                           
                                                                    
             
  
                    
                                                     
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing\Transport;

use OCA\Pipelinq\Service\UnsubscribeMail;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                             
  
                                                                                                                                              
   
final class InstanceMailerTransport implements TransportInterface {

	   
                         
    
	public const HEADER_UNSUBSCRIBE = 'List-Unsubscribe';

	   
                         
    
	public const HEADER_UNSUBSCRIBE_POST = 'List-Unsubscribe-Post';

	   
                             
    
	public const ONE_CLICK = 'List-Unsubscribe=One-Click';

	   
                
   
                                                          
                                              
                                                                                                     
    
	public function __construct(
		private readonly IMailer $mailer,
		private readonly LoggerInterface $logger,
		private readonly ?UnsubscribeMail $unsubscribeMail = null,
	) {
	}                   

	   
                           
   
                                                        
   
                                   
   
                                                                                                                                                              
    
	public function send(RenderedMail $mail): SendResult {
		try {
			$message = $this->mailer->createMessage();
		} catch (Throwable $e) {
			$this->logger->warning(
				'InstanceMailerTransport.send: createMessage failed',
				['exception' => $e->getMessage()]
			);
			return new SendResult(accepted: false, error: 'instance-mailer-unavailable');
		}

		$this->applyEnvelope(message: $message, mail: $mail);
		if ($mail->headers !== []) {
			$this->applyHeaders(message: $message, headers: $mail->headers);
		}

		try {
			$failedRecipients = $this->mailer->send($message);
		} catch (Throwable $e) {
			$this->logger->warning(
				'InstanceMailerTransport.send: send failed',
				['deliveryId' => $mail->deliveryId, 'exception' => $e->getMessage()]
			);
			return new SendResult(accepted: false, error: 'instance-mailer-send-failed');
		}

		if ($failedRecipients !== []) {
			$this->logger->warning(
				'InstanceMailerTransport.send: provider rejected recipient(s)',
				['deliveryId' => $mail->deliveryId, 'failedRecipients' => $failedRecipients]
			);
			return new SendResult(accepted: false, error: 'recipient-rejected');
		}

		return new SendResult(accepted: true);
	}            

	   
                                                                        
   
                                                     
                                                
   
                
    
	private function applyEnvelope(IMessage $message, RenderedMail $mail): void {
		$from = [$mail->fromEmail];
		if ($mail->fromName !== '') {
			$from = [$mail->fromEmail => $mail->fromName];
		}

		$message->setFrom(addresses: $from);
		$message->setTo(recipients: [$mail->toEmail]);
		if ($mail->replyTo !== '') {
			$message->setReplyTo(addresses: [$mail->replyTo]);
		}

		$message->setSubject(subject: $mail->subject);
		if ($mail->html !== '') {
			$message->setHtmlBody(body: $mail->html);
		}

		if ($mail->text !== '') {
			$message->setPlainBody(body: $mail->text);
		}
	}                     

	   
                                                                       
   
                                                                      
                                                                       
                                                             
   
                                                     
                                                                     
   
                
   
                                                                                                                                                              
                                                                                                                                                                   
    
	private function applyHeaders(IMessage $message, array $headers): void {
		                                                                 
		                                                                  
		$unsubscribe = trim((string)($headers[self::HEADER_UNSUBSCRIBE] ?? ''), " <>\t");
		unset($headers[self::HEADER_UNSUBSCRIBE], $headers[self::HEADER_UNSUBSCRIBE_POST]);
		if ($unsubscribe !== '') {
			$set = false;
			if ($this->unsubscribeMail !== null) {
				$set = $this->unsubscribeMail->applyHeaders(message: $message, unsubscribe: ['oneClickUrl' => $unsubscribe]);
			}

			if ($set === false) {
				$this->logger->info('InstanceMailerTransport.applyHeaders: List-Unsubscribe not set; the body link remains');
			}
		}

		if ($headers === []) {
			return;
		}

		if (method_exists($message, 'getSymfonyEmail') === false) {
			$this->logger->info(
				'InstanceMailerTransport.applyHeaders: getSymfonyEmail() unavailable — sending without extra headers',
				['headerNames' => array_keys($headers)]
			);
			return;
		}

		try {
			$symfonyEmail = $message->getSymfonyEmail();
			foreach ($headers as $name => $value) {
				$symfonyEmail->getHeaders()->addTextHeader($name, $value);
			}
		} catch (Throwable $e) {
			$this->logger->info(
				'InstanceMailerTransport.applyHeaders: header injection failed — sending without extra headers',
				['exception' => $e->getMessage()]
			);
		}
	}                    
}           
