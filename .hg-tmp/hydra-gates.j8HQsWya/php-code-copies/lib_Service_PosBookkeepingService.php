<?php

   
                                  
  
                                                                             
                                                                            
                                                                               
                                                                  
                                                                        
                                                        
  
                                                                              
                                                                             
                                                                            
                                                                                
                                                                                 
                                                                               
                                                          
                                                   
  
                                                                           
                                                                              
                                                                               
                                                                              
                                                                            
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                    
                                               
  
                         
  
                                                 
  
                                                                                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                                             
  
                               
  
                                                                              
                                                                       
                                                                                   
                                                                         
                                                                               
                                                                             
                                                                              
                                                           
                                                                               
                                                                               
                                                                          
  
                                                                                 
                                                                             
                                                                
                               
  
                                                                                
                                                                                 
                                                                     
                                                                               
                                                         
                                                                             
                                                                   
                                                                  
                                                                           
                                                                           
                                        
  
                                                                                                                      
   
class PosBookkeepingService {
	   
                                                         
   
               
    
	public const EVENT_ZREPORT_SUBMITTED = 'pipelinq.PosZReport.submitted';

	   
                                                                             
   
               
    
	public const EVENT_JOURNAL_RAISE = 'shillinq.JournalEntry.raise';

	   
                                                                  
   
               
    
	private const EVENT_SOURCE = 'pipelinq/posBookkeeping';

	   
                                                              
   
            
    
	public const DEFAULT_MAX_ATTEMPTS = 5;

	   
                
   
                                                
                                                       
                                                                
                                              
                                                                  
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private IMailer $mailer,
		private PosAccessPolicy $policy,
		private LoggerInterface $logger,
		private readonly WebhookService $webhookService,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                         
   
                                           
   
                                             
    
	private function money(float $value): float {
		return round($value, 2);
	}             

	   
                                                                     
   
                                                                             
                                                                           
                                                                             
                                                      
   
                                                                          
                                                                          
                                                                        
                                                                          
                                                                      
   
                                                                
                                                                            
   
                                                          
   
                                                                        
   
                                                                                                                       
    
	public function generateZReport(string $reportDate, ?string $terminalId = null): array {
		if ($this->isValidDate(value: $reportDate) === false) {
			throw new OCSBadRequestException('reportDate moet in YYYY-MM-DD formaat zijn.');
		}

		$transactions = $this->fetchTransactionsForDate(reportDate: $reportDate, terminalId: $terminalId);

		$aggregate = $this->aggregateTransactions(transactions: $transactions);

		$status = 'draft';
		if ($aggregate['transactionCount'] > 0) {
			$status = 'ready';
		}

		$reference = 'Z-' . $reportDate;
		if ($terminalId !== null && $terminalId !== '') {
			$reference .= '-' . strtoupper($terminalId);
		}

		$report = [
			'reference' => $reference,
			'reportDate' => $reportDate,
			'terminalId' => $terminalId,
			'transactionIds' => $aggregate['transactionIds'],
			'transactionCount' => $aggregate['transactionCount'],
			'subtotal' => $aggregate['subtotal'],
			'discountTotal' => $aggregate['discountTotal'],
			'taxBreakdown' => $aggregate['taxBreakdown'],
			'totalTax' => $aggregate['totalTax'],
			'total' => $aggregate['total'],
			'paymentMethodBreakdown' => $aggregate['paymentMethodBreakdown'],
			'createdAt' => $this->now(),
			'settledAt' => $aggregate['settledAt'],
			'status' => $status,
			'bookkeepingStatus' => 'pending',
		];

		$persisted = $this->saveObjectFor(schemaKey: 'posZReport_schema', id: '', object: $report);

		$this->emitZReportSubmittedEvent(zReport: $persisted);

		$this->logger->info(
			'Pipelinq: Z-report generated',
			[
				'reportDate' => $reportDate,
				'terminalId' => $terminalId,
				'transactionCount' => $aggregate['transactionCount'],
				'total' => $aggregate['total'],
			]
		);

		return $persisted;
	}                       

	   
                                                                  
   
                                                                            
                                                             
                                                                             
                                                                         
                              
   
                                                                           
   
                  
                                         
                            
                      
                           
                                                         
                      
                   
                                                                      
                            
                    
   
                                                                                                                       
    
	public function aggregateTransactions(array $transactions): array {
		$ids = [];
		$subtotal = 0.0;
		$discountTotal = 0.0;
		$totalTax = 0.0;
		$grandTotal = 0.0;
		$byRate = [];
		$byMethod = [];
		$latestSettled = null;

		foreach ($transactions as $txn) {
			$id = (string)($txn['id'] ?? $txn['uuid'] ?? '');
			if ($id !== '') {
				$ids[] = $id;
			}

			$subtotal += (float)($txn['subtotal'] ?? 0);
			$discountTotal += (float)($txn['discountTotal'] ?? 0);
			$totalTax += (float)($txn['totalTax'] ?? 0);
			$grandTotal += (float)($txn['total'] ?? 0);

			$latestSettled = $this->maxIso(left: $latestSettled, right: (string)($txn['settledAt'] ?? $txn['confirmedAt'] ?? ''));

			foreach (($txn['taxBreakdown'] ?? []) as $entry) {
				$rate = (string)(int)($entry['rate'] ?? 0);
				if (isset($byRate[$rate]) === false) {
					$byRate[$rate] = ['rate' => (int)(float)($entry['rate'] ?? 0), 'base' => 0.0, 'tax' => 0.0];
				}

				$byRate[$rate]['base'] += (float)($entry['base'] ?? 0);
				$byRate[$rate]['tax'] += (float)($entry['tax'] ?? 0);
			}

			$method = (string)($txn['paymentMethod'] ?? '');
			if ($method !== '') {
				if (isset($byMethod[$method]) === false) {
					$byMethod[$method] = ['method' => $method, 'amount' => 0.0];
				}

				$byMethod[$method]['amount'] += (float)($txn['total'] ?? 0);
			}
		}             

		$taxBreakdown = [];
		foreach ($byRate as $row) {
			$taxBreakdown[] = [
				'rate' => $row['rate'],
				'base' => $this->money(value: (float)$row['base']),
				'tax' => $this->money(value: (float)$row['tax']),
			];
		}

		$paymentBreakdown = [];
		foreach ($byMethod as $row) {
			$paymentBreakdown[] = [
				'method' => $row['method'],
				'amount' => $this->money(value: (float)$row['amount']),
			];
		}

		return [
			'transactionIds' => $ids,
			'transactionCount' => count($ids),
			'subtotal' => $this->money(value: $subtotal),
			'discountTotal' => $this->money(value: $discountTotal),
			'taxBreakdown' => $taxBreakdown,
			'totalTax' => $this->money(value: $totalTax),
			'total' => $this->money(value: $grandTotal),
			'paymentMethodBreakdown' => $paymentBreakdown,
			'settledAt' => $latestSettled,
		];
	}                             

	   
                                                             
   
                                                                            
                                                                            
                                                                             
                                                                  
   
                                                             
   
                                       
   
                                                                                                                       
    
	public function computeIdempotencyKey(array $zReport): string {
		$uuid = (string)($zReport['id'] ?? $zReport['uuid'] ?? '');
		$date = (string)($zReport['reportDate'] ?? '');

		return 'sha256:' . hash('sha256', $uuid . $date);
	}                             

	   
                                              
   
                                                                       
                                                                          
                                                                         
                                                                      
   
                                                                   
   
                                                                                                                       
    
	public function shouldDispatch(): bool {
		$url = $this->journalWebhookUrl();
		if ($url === '') {
			return false;
		}

		if (filter_var($url, FILTER_VALIDATE_URL) === false) {
			return false;
		}

		return str_starts_with($url, 'https://');
	}                      

	   
                                                                             
   
                                                                       
                                                                              
                                                                     
                                                                           
                                                                            
                          
   
                                                                              
                                                                          
                                                                            
                                                                     
                                                                       
   
                                                                              
                                                                        
   
                                               
                                                                
   
                                                                        
   
                                                                             
                                                              
   
                                                                                                                       
    
	public function raiseJournalEntry(string $zReportId, string $userId): array {
		$this->requireManager(userId: $userId);

		$zReport = $this->fetchZReport(id: $zReportId);

		return $this->dispatchRaise(zReport: $zReport);
	}                         

	   
                                                                      
   
                                                                              
                                                                            
   
                                                                           
                                                                          
                                                                       
                                                                      
                                           
   
                                                             
                                                                         
   
                                                                        
   
                                                                                                                       
    
	public function dispatchRaise(array $zReport, ?string $idempotencyKey = null): array {
		if ($idempotencyKey === null || $idempotencyKey === '') {
			$idempotencyKey = $this->computeIdempotencyKey(zReport: $zReport);
		}

		if ($this->shouldDispatch() === false) {
			return $this->projectPending(
				zReport: $zReport,
				reason: 'shillinq journal integration not configured'
			);
		}

		$payload = $this->buildRaisePayload(zReport: $zReport, idempotencyKey: $idempotencyKey);
		$dispatched = $this->dispatch(eventName: self::EVENT_JOURNAL_RAISE, payload: $payload);

		if ($dispatched === false) {
			return $this->onRaiseFailure(zReport: $zReport);
		}

		return $this->onRaised(zReport: $zReport, idempotencyKey: $idempotencyKey);
	}                     

	   
                                                            
   
                                                                             
                                                                             
                                                                           
                                                         
   
                                                      
                                                        
   
                                                        
    
	private function onRaised(array $zReport, string $idempotencyKey): array {
		$zReport['bookkeepingStatus'] = 'raised';
		$zReport['shillinqJournalEntryId'] = $idempotencyKey;
		$zReport['bookkeepingAttempts'] = ((int)($zReport['bookkeepingAttempts'] ?? 0)) + 1;

		$saved = $this->saveObjectFor(
			schemaKey: 'posZReport_schema',
			id: (string)($zReport['id'] ?? $zReport['uuid'] ?? ''),
			object: $zReport
		);

		$this->logger->info(
			'Pipelinq: POS-day journal raised in shillinq via registry',
			['zReportId' => (string)($zReport['id'] ?? ''), 'idempotencyKey' => $idempotencyKey]
		);

		return $saved;
	}                

	   
                                                                               
   
                                                                            
                                                                
   
                                                      
                                                     
   
                                                        
    
	private function projectPending(array $zReport, string $reason): array {
		$zReport['bookkeepingStatus'] = 'pending';

		$saved = $this->saveObjectFor(
			schemaKey: 'posZReport_schema',
			id: (string)($zReport['id'] ?? $zReport['uuid'] ?? ''),
			object: $zReport
		);

		$this->logger->info(
			'Pipelinq: POS-day journal raise deferred (pending)',
			['zReportId' => (string)($zReport['id'] ?? ''), 'reason' => $reason]
		);

		return $saved;
	}                      

	   
                                                                            
   
                                                                              
                                                                          
                                                                            
   
                                                      
   
                                                        
    
	private function onRaiseFailure(array $zReport): array {
		$attempts = ((int)($zReport['bookkeepingAttempts'] ?? 0)) + 1;
		$zReport['bookkeepingAttempts'] = $attempts;

		if ($attempts >= $this->maxAttempts()) {
			$zReport['bookkeepingStatus'] = 'failed';

			$saved = $this->saveObjectFor(
				schemaKey: 'posZReport_schema',
				id: (string)($zReport['id'] ?? $zReport['uuid'] ?? ''),
				object: $zReport
			);

			$this->sendAlertEmail(
				subject: 'POS bookkeeping: journaalpost raise naar shillinq permanent gefaald',
				body: 'Z-report ' . ((string)($zReport['reference'] ?? ($zReport['id'] ?? '')))
					. ' kon na ' . $attempts . ' pogingen niet naar shillinq worden geraised.'
			);

			$this->logger->warning(
				'Pipelinq: POS-day journal raise max-retries exhausted',
				['zReportId' => (string)($zReport['id'] ?? ''), 'attempts' => $attempts]
			);

			return $saved;
		}        

		$zReport['bookkeepingStatus'] = 'pending';

		$saved = $this->saveObjectFor(
			schemaKey: 'posZReport_schema',
			id: (string)($zReport['id'] ?? $zReport['uuid'] ?? ''),
			object: $zReport
		);

		$this->logger->info(
			'Pipelinq: POS-day journal raise transient failure, will retry',
			['zReportId' => (string)($zReport['id'] ?? ''), 'attempts' => $attempts]
		);

		return $saved;
	}                      

	   
                                                               
   
                                                                             
                                                                              
                                                                         
                                                                          
            
   
                                                      
                                                                    
   
                                                        
   
                                                                                                                       
    
	public function buildRaisePayload(array $zReport, string $idempotencyKey): array {
		$zReportId = (string)($zReport['id'] ?? $zReport['uuid'] ?? '');

		return [
			'specversion' => '1.0',
			'type' => self::EVENT_JOURNAL_RAISE,
			'source' => self::EVENT_SOURCE,
			'id' => $idempotencyKey,
			'time' => $this->now(),
			'subject' => (string)($zReport['reference'] ?? $zReportId),
			'datacontenttype' => 'application/json',
			'data' => [
				'idempotencyKey' => $idempotencyKey,
				'zReportId' => $zReportId,
				'reference' => (string)($zReport['reference'] ?? ''),
				'postingDate' => (string)($zReport['reportDate'] ?? ''),
				'terminalId' => (string)($zReport['terminalId'] ?? ''),
				'currency' => 'EUR',
				'subtotal' => (float)($zReport['subtotal'] ?? 0),
				'totalTax' => (float)($zReport['totalTax'] ?? 0),
				'total' => (float)($zReport['total'] ?? 0),
				'taxBreakdown' => (array)($zReport['taxBreakdown'] ?? []),
				'transactionCount' => (int)($zReport['transactionCount'] ?? 0),
			],
		];
	}                         

	   
                                                                             
   
                                                                 
                                                                            
                                       
   
                                                                
   
                                                              
   
                                                                                                                       
    
	public function emitZReportSubmittedEvent(array $zReport): bool {
		$payload = [
			'specversion' => '1.0',
			'type' => self::EVENT_ZREPORT_SUBMITTED,
			'source' => self::EVENT_SOURCE,
			'id' => (string)($zReport['id'] ?? $zReport['uuid'] ?? ''),
			'time' => $this->now(),
			'subject' => (string)($zReport['reference'] ?? ($zReport['id'] ?? '')),
			'datacontenttype' => 'application/json',
			'data' => [
				'zReportId' => (string)($zReport['id'] ?? $zReport['uuid'] ?? ''),
				'reportDate' => (string)($zReport['reportDate'] ?? ''),
				'terminalId' => (string)($zReport['terminalId'] ?? ''),
				'transactionCount' => (int)($zReport['transactionCount'] ?? 0),
				'total' => (float)($zReport['total'] ?? 0),
				'currency' => 'EUR',
			],
		];

		return $this->dispatch(eventName: self::EVENT_ZREPORT_SUBMITTED, payload: $payload);
	}                                 

	   
                                                                          
   
                                                                           
                                                                           
                                                                    
                                                                
   
                                                    
                                                                
   
                                                               
    
	private function dispatch(string $eventName, array $payload): bool {
		try {
			$event = new Event();
			$this->webhookService->dispatchEvent(
				_event: $event,
				eventName: $eventName,
				payload: $payload
			);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: bookkeeping CloudEvent not dispatched (no consumer or OpenRegister unavailable)',
				['exception' => $e->getMessage(), 'eventName' => $eventName]
			);
			return false;
		}         
	}                

	   
                                                                   
   
                                                                
   
                                             
                                       
   
                
    
	private function sendAlertEmail(string $subject, string $body): void {
		$to = trim($this->appConfig->getValueString(Application::APP_ID, 'pos_eod.alert_email', ''));
		if ($to === '') {
			return;
		}

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$to]);
			$message->setSubject($subject);
			$message->setPlainBody($body);
			$this->mailer->send($message);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: POS bookkeeping alert email not sent',
				['exception' => $e->getMessage(), 'to' => $to, 'subject' => $subject]
			);
		}
	}                      

	   
                                                                                
   
                                                                         
                                                                            
                                                                            
                                                                              
   
                                                                
                                                            
   
                                                                       
    
	private function fetchTransactionsForDate(string $reportDate, ?string $terminalId): array {
		[$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

		try {
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: failed to read POS transactions for Z-report; total assumed 0',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$matches = [];
		foreach (($results ?? []) as $result) {
			$txn = $this->toArray(object: $result);
			$status = (string)($txn['status'] ?? '');
			if (in_array($status, ['confirmed', 'settled'], true) === false) {
				continue;
			}

			if ($terminalId !== null && $terminalId !== '' && (string)($txn['terminalId'] ?? '') !== $terminalId) {
				continue;
			}

			$stamp = (string)($txn['settledAt'] ?? $txn['confirmedAt'] ?? '');
			$day = $this->isoDay(value: $stamp);
			if ($day !== $reportDate) {
				continue;
			}

			$matches[] = $txn;
		}

		return $matches;
	}                                

	   
                                                       
   
                                              
   
                
   
                                                                         
    
	private function requireManager(string $userId): void {
		if ($this->policy->isManager(userId: $userId) === false) {
			throw new OCSForbiddenException('Alleen accounting-beheerders mogen de journaalpost naar shillinq raisen.');
		}
	}                      

	   
                                                             
   
                                        
   
                                              
   
                                                                
                                                                                                                              
    
	public function fetchZReport(string $id): array {
		return $this->fetchOne(schemaKey: 'posZReport_schema', id: $id, label: 'Z-report niet gevonden.');
	}                    

	   
                                                          
   
                                                       
                                      
                                                     
   
                                            
   
                                                              
    
	private function fetchOne(string $schemaKey, string $id, string $label): array {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new OCSNotFoundException($label);
		}

		return $this->toArray(object: $object);
	}                

	   
                                                                       
   
                                                       
                                                        
                                                        
   
                                                  
    
	private function saveObjectFor(string $schemaKey, string $id, array $object): array {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		unset($object['@self']);

		$saved = $this->getObjectService()->saveObject(
			object: $object,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $id
		);

		return $this->toArray(object: $saved);
	}                     

	   
                                                                     
   
                                                       
   
                                                                   
   
                                                                               
    
	private function config(string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('Bookkeeping-register of -schema is niet geconfigureerd.');
		}

		return [$register, $schema];
	}              

	   
                                                                    
   
                                                                     
    
	private function journalWebhookUrl(): string {
		return trim($this->appConfig->getValueString(Application::APP_ID, 'shillinq_journal_webhook_url', ''));
	}                         

	   
                                                                                       
   
                                        
   
                                                                                                                       
    
	public function maxAttempts(): int {
		$raw = trim(
			$this->appConfig->getValueString(
				Application::APP_ID,
				'pos_eod.max_retry_attempts',
				(string)self::DEFAULT_MAX_ATTEMPTS
			)
		);

		$candidate = (int)$raw;
		if ($candidate > 0) {
			return $candidate;
		}

		return self::DEFAULT_MAX_ATTEMPTS;
	}                   

	   
                                       
   
                                      
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        

	   
                                                                
   
                                       
   
                                                        
    
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return (array)$object;
	}               

	   
                                                              
   
                                            
                                              
   
                                                                           
    
	private function maxIso(?string $left, ?string $right): ?string {
		$l = $left;
		if ($left === null || $left === '') {
			$l = null;
		}

		$r = $right;
		if ($right === null || $right === '') {
			$r = null;
		}

		if ($l === null) {
			return $r;
		}

		if ($r === null) {
			return $l;
		}

		if (strcmp($l, $r) >= 0) {
			return $l;
		}

		return $r;
	}              

	   
                                                            
   
                                                
   
                                                                           
    
	private function isoDay(string $value): string {
		if ($value === '') {
			return '';
		}

		try {
			$dateTime = new DateTimeImmutable($value);
			return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
		} catch (\Throwable $e) {
			return '';
		}
	}              

	   
                                                    
   
                                            
   
                                 
    
	private function isValidDate(string $value): bool {
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
			return false;
		}

		try {
			new DateTimeImmutable($value);
			return true;
		} catch (\Throwable $e) {
			return false;
		}
	}                   

	   
                                              
   
                                                                     
                                                                         
   
                                         
   
                                                                                                                       
    
	public function now(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
	}           
}           
