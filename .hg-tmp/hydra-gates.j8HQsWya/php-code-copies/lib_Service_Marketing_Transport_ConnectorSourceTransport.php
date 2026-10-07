<?php

   
                                     
  
                                                                         
                                                                       
                                                             
                                                                          
                                                                            
                                                                            
                                  
  
                                                                         
                                                                             
                                                                         
                                                                            
                                                                          
            
  
                    
                                                     
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing\Transport;

use OCA\Pipelinq\Service\ConnectorSourceRegister;
use OCA\Pipelinq\Support\FleetAppId;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                   
  
                                                                                                                                                       
  
                                                                              
                                                                              
                                                                           
                                                                     
   
final class ConnectorSourceTransport implements TransportInterface {
	   
                                       
   
                                                                           
                                                                           
                                                                             
                                                                              
                                         
    
	private const OPENCONNECTOR_SOURCE_SCHEMA_SLUG = 'source';

	   
                                                                  
                                                                    
                                                                   
                             
   
                 
    
	private const KNOWN_PROVIDERS = ['ses', 'brevo', 'mailjet', 'sendgrid', 'mailgun', 'postmark'];

	   
                
   
                                                                                              
                                              
                                                                                                     
                                                                       
                                                                                                                   
    
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ConnectorSourceRegister $connectorRegister,
		private readonly string $connectorSourceId,
		private readonly string $provider,
	) {
	}                   

	   
                                                   
   
                                                        
   
                                   
   
                                                                                                                                                        
    
	public function send(RenderedMail $mail): SendResult {
		if ($this->connectorSourceId === '') {
			$this->logger->warning('ConnectorSourceTransport.send: no connectorSourceId', ['deliveryId' => $mail->deliveryId]);
			return new SendResult(accepted: false, error: 'no-connector-source');
		}

		                                                                                
		                                                                             
		                                                                
		                                                                             
		                                                                              
		                                                                        
		                                                       
		$registerSlug = $this->connectorRegister->slugOrNull(
			operation: 'ConnectorSourceTransport.send',
			sourceId: $this->connectorSourceId
		);
		if ($registerSlug === null) {
			return new SendResult(accepted: false, error: 'connector-register-absent');
		}

		$source = $this->resolveSource(registerSlug: $registerSlug);
		if ($source === null) {
			return new SendResult(accepted: false, error: 'connector-source-not-found');
		}

		$callService = $this->resolveCallService();
		if ($callService === null) {
			return new SendResult(accepted: false, error: 'call-service-unavailable');
		}

		$body = $this->buildRequestBody(mail: $mail);

		try {
			$callLog = $callService->call(
				source: $source,
				endpoint: '',
				method: 'POST',
				config: ['json' => $body],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ConnectorSourceTransport.send: connector call failed',
				['connectorSourceId' => $this->connectorSourceId, 'exception' => $e->getMessage()]
			);
			return new SendResult(accepted: false, error: 'connector-call-failed');
		}

		$callLogData = $this->toArray(value: $callLog);
		$statusCode = (int)($callLogData['statusCode'] ?? 0);
		if ($statusCode < 200 || $statusCode >= 300) {
			$this->logger->warning(
				'ConnectorSourceTransport.send: connector source responded with a non-2xx status',
				['connectorSourceId' => $this->connectorSourceId, 'statusCode' => $statusCode]
			);
			return new SendResult(accepted: false, error: 'non-2xx-response');
		}

		$providerId = $this->extractProviderId(
			result: $this->decodeCallLogResponseBody(callLogData: $callLogData),
		);
		return new SendResult(accepted: true, providerId: $providerId);
	}            

	   
                                                                             
   
                                                                                
                                                                   
   
                                                                           
                                                                              
    
	private function resolveSource(string $registerSlug): array|object|null {
		$objectService = $this->resolveObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$source = $objectService->find(
				id: $this->connectorSourceId,
				register: $registerSlug,
				schema: self::OPENCONNECTOR_SOURCE_SCHEMA_SLUG,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ConnectorSourceTransport.resolveSource: lookup failed',
				['connectorSourceId' => $this->connectorSourceId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($source === null || (is_array($source) === false && is_object($source) === false)) {
			$this->logger->warning(
				'ConnectorSourceTransport.resolveSource: connector source not found',
				['connectorSourceId' => $this->connectorSourceId]
			);
			return null;
		}

		return $source;
	}                     

	   
                                                             
   
                                                
   
                                                  
    
	private function buildRequestBody(RenderedMail $mail): array {
		$provider = $this->provider;
		if (in_array($provider, self::KNOWN_PROVIDERS, true) === false) {
			$provider = 'sendgrid';
		}

		return match ($provider) {
			'ses' => $this->buildSesBody(mail: $mail),
			'brevo' => $this->buildBrevoBody(mail: $mail),
			'mailjet' => $this->buildMailjetBody(mail: $mail),
			'mailgun' => $this->buildMailgunBody(mail: $mail),
			'postmark' => $this->buildPostmarkBody(mail: $mail),
			default => $this->buildSendGridBody(mail: $mail),
		};
	}                        

	   
                                                                       
                                                                       
   
                                                
   
                                
    
	private function buildSendGridBody(RenderedMail $mail): array {
		return [
			'to' => $mail->toEmail,
			'subject' => $mail->subject,
			'bodyHtml' => $mail->html,
			'bodyText' => $mail->text,
			'senderName' => $mail->fromName,
			'senderEmail' => $mail->fromEmail,
			'replyTo' => $mail->replyTo,
		];
	}                         

	   
                                          
   
                                                
   
                                
    
	private function buildSesBody(RenderedMail $mail): array {
		return [
			'FromEmailAddress' => $this->formatAddress(email: $mail->fromEmail, name: $mail->fromName),
			'Destination' => ['ToAddresses' => [$mail->toEmail]],
			'ReplyToAddresses' => $this->nonEmptyList(value: $mail->replyTo),
			'Content' => [
				'Simple' => [
					'Subject' => ['Data' => $mail->subject],
					'Body' => [
						'Html' => ['Data' => $mail->html],
						'Text' => ['Data' => $mail->text],
					],
				],
			],
		];
	}                    

	   
                                                           
   
                                                
   
                                
    
	private function buildBrevoBody(RenderedMail $mail): array {
		$body = [
			'sender' => ['name' => $mail->fromName, 'email' => $mail->fromEmail],
			'to' => [['email' => $mail->toEmail]],
			'subject' => $mail->subject,
			'htmlContent' => $mail->html,
			'textContent' => $mail->text,
		];
		if ($mail->replyTo !== '') {
			$body['replyTo'] = ['email' => $mail->replyTo];
		}

		return $body;
	}                      

	   
                                      
   
                                                
   
                                
    
	private function buildMailjetBody(RenderedMail $mail): array {
		$message = [
			'From' => ['Email' => $mail->fromEmail, 'Name' => $mail->fromName],
			'To' => [['Email' => $mail->toEmail]],
			'Subject' => $mail->subject,
			'HTMLPart' => $mail->html,
			'TextPart' => $mail->text,
		];
		if ($mail->replyTo !== '') {
			$message['ReplyTo'] = ['Email' => $mail->replyTo];
		}

		return ['Messages' => [$message]];
	}                        

	   
                                                         
   
                                                
   
                                
    
	private function buildMailgunBody(RenderedMail $mail): array {
		$body = [
			'from' => $this->formatAddress(email: $mail->fromEmail, name: $mail->fromName),
			'to' => $mail->toEmail,
			'subject' => $mail->subject,
			'html' => $mail->html,
			'text' => $mail->text,
		];
		if ($mail->replyTo !== '') {
			$body['h:Reply-To'] = $mail->replyTo;
		}

		return $body;
	}                        

	   
                                          
   
                                                
   
                                
    
	private function buildPostmarkBody(RenderedMail $mail): array {
		$body = [
			'From' => $this->formatAddress(email: $mail->fromEmail, name: $mail->fromName),
			'To' => $mail->toEmail,
			'Subject' => $mail->subject,
			'HtmlBody' => $mail->html,
			'TextBody' => $mail->text,
		];
		if ($mail->replyTo !== '') {
			$body['ReplyTo'] = $mail->replyTo;
		}

		return $body;
	}                         

	   
                                                                            
   
                                     
                                                        
   
                  
    
	private function formatAddress(string $email, string $name): string {
		if ($name === '') {
			return $email;
		}

		return sprintf('"%s" <%s>', str_replace('"', '', $name), $email);
	}                     

	   
                                                                              
   
                                   
   
                    
    
	private function nonEmptyList(string $value): array {
		if ($value === '') {
			return [];
		}

		return [$value];
	}                    

	   
                                                                 
   
                       
    
	private function resolveObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'ConnectorSourceTransport.resolveObjectService: unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}                            

	   
                                                                
   
                       
    
	private function resolveCallService(): ?object {
		                                                                
		                                                                   
		                                                                    
		                                                                   
		                                                                      
		                                                                   
		                                       
		$callService = FleetAppId::getService(
			container: $this->container,
			canonical: 'integriq',
			relative: 'Service\CallService'
		);
		if ($callService === null) {
			$this->logger->error('ConnectorSourceTransport.resolveCallService: unavailable');
			return null;
		}

		if (method_exists($callService, 'call') === false) {
			$this->logger->error('ConnectorSourceTransport.resolveCallService: CallService lacks call()');
			return null;
		}

		return $callService;
	}                          

	   
                                                                  
   
                                                             
   
                                                                       
    
	private function decodeCallLogResponseBody(array $callLogData): mixed {
		$response = ($callLogData['response'] ?? null);
		if (is_array($response) === false) {
			return null;
		}

		$body = ($response['body'] ?? null);
		if (is_string($body) === false || $body === '') {
			return null;
		}

		if ((string)($response['encoding'] ?? 'UTF-8') !== 'UTF-8') {
			return null;
		}

		$decoded = json_decode($body, true);
		if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) === true) {
			return $decoded;
		}

		return null;
	}                                 

	   
                                                                         
   
                                               
   
                       
   
                                                                                    
                                                                                   
    
	private function extractProviderId(mixed $result): ?string {
		if (is_array($result) === true) {
			foreach (['providerId', 'messageId', 'id', 'MessageID'] as $key) {
				if (isset($result[$key]) === true && is_scalar($result[$key]) === true && (string)$result[$key] !== '') {
					return (string)$result[$key];
				}
			}
		}

		if (is_object($result) === true) {
			foreach (['providerId', 'messageId', 'id', 'MessageID'] as $key) {
				if (isset($result->{$key}) === true && is_scalar($result->{$key}) === true && (string)$result->{$key} !== '') {
					return (string)$result->{$key};
				}
			}
		}

		if (is_string($result) === true && $result !== '') {
			return $result;
		}

		return null;
	}                         

	   
                                                                   
   
                                            
   
                                
    
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true) {
			if (method_exists($value, 'jsonSerialize') === true) {
				return (array)$value->jsonSerialize();
			}

			if (method_exists($value, 'getObject') === true) {
				return (array)$value->getObject();
			}
		}

		return [];
	}               
}           
