<?php

   
                                  
  
                                                                             
                                                                             
                                                                          
         
  
                    
                                 
  
                                                              
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                                                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Support\FleetAppId;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                         
  
                                                                      
                                                                      
                              
  
                                                                          
                                                                        
                                                                          
                                    
  
                                                                                                     
                                                                                 
  
                                                                                                                                                              
   
class IntegriqConsentClient {

	   
                                                         
    
	public const DECISION_EVENT = 'Event\\OutboundSendDecisionRequestedEvent';

	   
                                                       
    
	public const CHANGE_EVENT = 'Event\\OptOutChangeRequestedEvent';

	   
                                                                       
    
	public const CONFIG_STORE = 'consent.store';

	   
                                                                                          
    
	public const CONFIG_CUTOVER_AT = 'consent.cutover_at';

	   
                                                             
    
	public const STORE_PIPELINQ = 'pipelinq';

	   
                                            
    
	public const STORE_INTEGRIQ = 'integriq';

	   
                                             
    
	public const CODE_UNAVAILABLE = 'authority-unavailable';

	   
                        
    
	public const CODE_ALLOWED = 'allowed';

	   
                                      
    
	public const CODE_OPTED_OUT = 'opted-out';

	   
                                                                    
    
	public const CATEGORY_REMINDER = 'reminder';

	   
                                                               
    
	public const CATEGORY_SERVICE = 'service';

	   
                                                                  
    
	public const CATEGORY_MARKETING = 'marketing';

	   
                                                                        
    
	public const CATEGORY_REPLY = 'reply';

	   
                                                                         
    
	public const CATEGORY_ACCOUNT = 'account';

	   
                                                                              
    
	public const STATE_ERASE_CONTACT = 'erase-contact';

	   
                                                                      
    
	public const CHUNK = 500;

	   
                                                                             
                                                              
   
                          
    
	private const EXEMPT = ['besluit', 'statutory', 'account', 'security'];

	   
                
   
                                                                            
                                                                   
                                                                                         
                                                                                         
                                                                                                 
                                                                                               
    
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly IAppConfig $appConfig,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
		private readonly string $decisionEvent = self::DECISION_EVENT,
		private readonly string $changeEvent = self::CHANGE_EVENT,
	) {
	}                   

	   
                                                                 
   
                                         
   
                                 
   
                                                                                                                                                               
    
	public static function isExempt(string $category): bool {
		return in_array($category, self::EXEMPT, true);
	}                

	   
                                                        
   
                                                                           
   
                                                                                                                                                                   
    
	public function isCutover(): bool {
		try {
			$store = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_STORE, self::STORE_PIPELINQ);
		} catch (Throwable $e) {
			return false;
		}

		return strtolower(trim($store)) === self::STORE_INTEGRIQ;
	}                 

	   
                                                            
   
                                   
   
                                                                                                                                                                   
    
	public function canRecord(): bool {
		return $this->eventClass(relative: $this->changeEvent) !== null;
	}                 

	   
                                                        
   
                                                                           
                                                           
   
                                                                                        
                                                                                
                                                                                                              
                                                                                                             
                                                                                                                   
                                                                                                 
                                                                                                                       
   
                                                                                                                              
   
                                                                                                          
   
                                                                                                                                                               
    
	public function decide(
		string $channel,
		string $category,
		bool $requiresConsent,
		array $recipients,
		string $correlationId = '',
		?string $inReplyTo = null,
		bool $probe = false,
	): array {
		if ($correlationId === '') {
			$correlationId = 'pipelinq-'.bin2hex(random_bytes(8));
		}

		$recipients = array_values(
			array_filter(
				$recipients,
				static fn (mixed $recipient): bool => is_array($recipient) === true && trim((string)($recipient['address'] ?? '')) !== ''
			)
		);

		$decisions = [];
		foreach (array_chunk($recipients, self::CHUNK) as $chunk) {
			$decisions += $this->decideChunk(
				channel: $channel,
				category: $category,
				requiresConsent: $requiresConsent,
				chunk: $chunk,
				correlationId: $correlationId,
				inReplyTo: $inReplyTo,
				probe: $probe
			);
		}

		return $decisions;
	}              

	   
                                                                        
   
                                                                         
                                                                                  
                                                         
   
                                               
                                                
                                                               
                                               
                                                                       
   
                                                                                                         
   
                                                                                              
   
                                                                                                                                            
    
	public function probeOne(
		string $channel,
		string $category,
		bool $requiresConsent,
		string $address,
		string $contactRef = '',
	): array {
		if (trim($address) === '') {
			return $this->unavailable(channel: $channel, category: $category, why: 'the contact has no address on this channel');
		}

		$recipient = array_filter(
			['address' => $address, 'contactRef' => $contactRef],
			static fn (string $value): bool => $value !== ''
		);

		$decisions = $this->decide(
			channel: $channel,
			category: $category,
			requiresConsent: $requiresConsent,
			recipients: [$recipient],
			probe: true
		);

		return ($decisions[$address] ?? $this->unavailable(channel: $channel, category: $category, why: 'no answer for this recipient'));
	}                

	   
                          
   
                                                    
                                                     
                                                                    
                                                                       
                                                                            
                                                                              
                                                                  
   
                                                                                                         
   
                                                                                              
   
                                                                                                                                                               
    
	public function decideOne(
		string $channel,
		string $category,
		bool $requiresConsent,
		string $address,
		string $contactRef = '',
		?string $inReplyTo = null,
		string $listRef = '',
	): array {
		if (trim($address) === '') {
			                                                                   
			return $this->unavailable(channel: $channel, category: $category, why: 'the contact has no address on this channel');
		}

		$recipient = array_filter(
			['address' => $address, 'contactRef' => $contactRef, 'listRef' => $listRef],
			static fn (string $value): bool => $value !== ''
		);

		$decisions = $this->decide(
			channel: $channel,
			category: $category,
			requiresConsent: $requiresConsent,
			recipients: [$recipient],
			inReplyTo: $inReplyTo
		);

		return ($decisions[$address] ?? $this->unavailable(channel: $channel, category: $category, why: 'no answer for this recipient'));
	}                 

	   
                                                                       
   
                                                                                      
                                                                                          
                                                                                
   
                                                                                                                         
   
                                                                                                                                               
    
	public function record(array $request): array {
		$eventClass = $this->eventClass(relative: $this->changeEvent);
		if ($eventClass === null) {
			return $this->notRecorded(code: self::CODE_UNAVAILABLE, reason: 'integriq or its change event is not installed');
		}

		try {
			$event = $this->newChangeEvent(eventClass: $eventClass, request: $request);
			if ($event === null) {
				return $this->notRecorded(code: self::CODE_UNAVAILABLE, reason: 'the change event is not an event');
			}

			$this->dispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			return $this->notRecorded(code: self::CODE_UNAVAILABLE, reason: 'the change failed: '.$e->getMessage());
		}

		return $this->readChange(event: $event);
	}              

	   
                                                                           
   
                                                           
                                                       
   
                                                                     
    
	private function newChangeEvent(string $eventClass, array $request): ?Event {
		$evidence = ($request['evidence'] ?? []);
		if (is_array($evidence) === false) {
			$evidence = ['text' => (string)$evidence];
		}

		$event = new $eventClass(
				'pipelinq',
				(string)($request['address'] ?? ''),
				(string)($request['state'] ?? ''),
				(string)($request['scope'] ?? 'channel'),
				(string)($request['channel'] ?? ''),
				(string)($request['ref'] ?? ''),
				(string)($request['contactRef'] ?? ''),
				(string)($request['lawfulBasis'] ?? ''),
				$evidence,
				(string)($request['source'] ?? ''),
				(string)($request['correlationId'] ?? ('pipelinq-'.bin2hex(random_bytes(8)))),
				(string)($request['legacyRef'] ?? ''),
				(string)($request['purpose'] ?? ''),
		);
		if (($event instanceof Event) === false) {
			return null;
		}

		return $event;
	}                      

	   
                                             
   
                                             
   
                                                                                         
    
	private function readChange(Event $event): array {
		if (method_exists($event, 'isHandled') === false || $event->isHandled() !== true) {
			return $this->notRecorded(code: self::CODE_UNAVAILABLE, reason: 'integriq did not answer');
		}

		$refusal = null;
		if (method_exists($event, 'getRefusal') === true) {
			$refusal = $event->getRefusal();
		}

		if (is_array($refusal) === true) {
			return $this->notRecorded(code: (string)($refusal['code'] ?? 'refused'), reason: (string)($refusal['reason'] ?? ''));
		}

		$recordId = null;
		if (method_exists($event, 'getRecordId') === true) {
			$recordId = $event->getRecordId();
		}

		return ['recorded' => true, 'recordId' => $recordId, 'code' => '', 'reason' => ''];
	}                  

	   
                                                                            
   
                                                        
   
                                                                                         
   
                                                                                                                                                      
    
	public function eraseContact(string $contactRef): array {
		return $this->record(
			request: [
				'address' => '',
				'state' => self::STATE_ERASE_CONTACT,
				'scope' => 'instance',
				'contactRef' => $contactRef,
				'source' => 'contact-erased',
			]
		);
	}                    

	   
                               
   
                                                                       
                                                                        
                                                                                       
                                                                          
                                                                              
                                                                                            
                                                                                         
   
                                                                                                                     
   
                                                                                              
    
	private function decideChunk(
		string $channel,
		string $category,
		bool $requiresConsent,
		array $chunk,
		string $correlationId,
		?string $inReplyTo,
		bool $probe,
	): array {
		[$event, $why] = $this->askChunk(
			channel: $channel,
			category: $category,
			requiresConsent: $requiresConsent,
			chunk: $chunk,
			correlationId: $correlationId,
			inReplyTo: $inReplyTo,
			probe: $probe
		);

		$decisions = [];
		foreach ($chunk as $recipient) {
			$address = (string)($recipient['address'] ?? '');
			$answer = null;
			if ($event !== null) {
				$answer = $this->answerFor(event: $event, address: $address);
			}

			if ($answer === null) {
				$reason = $why;
				if ($reason === '') {
					$reason = 'integriq did not answer for this recipient';
				}

				$decisions[$address] = $this->unavailable(channel: $channel, category: $category, why: $reason);
				continue;
			}

			$decisions[$address] = $this->readAnswer(answer: $answer, category: $category);
		}             

		return $decisions;
	}                   

	   
                                              
   
                                                                       
                                                                        
                                                                                       
                                                                          
                                                                              
                                                                                            
                                                                                         
   
                                                                                   
   
                                                                                              
    
	private function askChunk(
		string $channel,
		string $category,
		bool $requiresConsent,
		array $chunk,
		string $correlationId,
		?string $inReplyTo,
		bool $probe,
	): array {
		$eventClass = $this->eventClass(relative: $this->decisionEvent);
		if ($eventClass === null) {
			return [null, 'integriq or its decision event is not installed'];
		}

		try {
			                                                                         
			$event = new $eventClass('pipelinq', $channel, $category, $chunk, $correlationId, $this->baseUrl(), $requiresConsent, $inReplyTo, $probe);
			if (($event instanceof Event) === false) {
				return [null, 'the decision event is not an event'];
			}

			$this->dispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			return [null, 'the question failed: '.$e->getMessage()];
		}

		return [$event, ''];
	}                

	   
                                                                 
   
                                                
                                                
   
                                                
    
	private function answerFor(object $event, string $address): ?array {
		if (method_exists($event, 'isHandled') === false || $event->isHandled() !== true) {
			return null;
		}

		if (method_exists($event, 'getDecision') === false) {
			return null;
		}

		$answer = $event->getDecision($address);
		if (is_array($answer) === false || array_key_exists('send', $answer) === false) {
			return null;
		}

		return $answer;
	}                 

	   
                                           
   
                                                           
                                                      
   
                                                                                                         
    
	private function readAnswer(array $answer, string $category): array {
		$unsubscribe = null;
		if (is_array($answer['unsubscribe'] ?? null) === true && self::isExempt(category: $category) === false) {
			$unsubscribe = $answer['unsubscribe'];
		}

		return [
			'send' => ($answer['send'] === true),
			'code' => (string)($answer['code'] ?? ''),
			'reason' => (string)($answer['reason'] ?? ''),
			'unsubscribe' => $unsubscribe,
		];
	}                  

	   
                                                                                           
   
                                                     
                                         
                                                         
   
                                                                                                         
    
	private function unavailable(string $channel, string $category, string $why): array {
		$exempt = self::isExempt(category: $category);
		$outcome = 'not sent';
		if ($exempt === true) {
			$outcome = 'sent, the category is exempt';
		}

		$this->logger->warning(
			'Pipelinq: integriq could not say whether this person may be messaged; '.$outcome,
			[
				'app' => Application::APP_ID,
				'channel' => $channel,
				'category' => $category,
				'code' => self::CODE_UNAVAILABLE,
				'why' => $why,
			]
		);

		return [
			'send' => $exempt,
			'code' => self::CODE_UNAVAILABLE,
			'reason' => 'integriq is not available, so pipelinq cannot check whether this person may be messaged.',
			'unsubscribe' => null,
		];
	}                   

	   
                                 
   
                                         
                                        
   
                                                                                         
    
	private function notRecorded(string $code, string $reason): array {
		$this->logger->warning(
			'Pipelinq: integriq did not record the consent change; pipelinq keeps it and replays it',
			['app' => Application::APP_ID, 'code' => $code, 'reason' => $reason]
		);

		return ['recorded' => false, 'recordId' => null, 'code' => $code, 'reason' => $reason];
	}                   

	   
                                                                           
   
                                                                      
   
                                  
    
	private function eventClass(string $relative): ?string {
		foreach (FleetAppId::classCandidates(canonical: 'integriq', relative: $relative) as $candidate) {
			if (class_exists($candidate) === true) {
				return $candidate;
			}
		}

		return null;
	}                  

	   
                                                 
   
                                                        
    
	private function baseUrl(): string {
		try {
			return rtrim($this->urlGenerator->getAbsoluteURL('/'), '/');
		} catch (Throwable $e) {
			return '';
		}
	}               
}           
