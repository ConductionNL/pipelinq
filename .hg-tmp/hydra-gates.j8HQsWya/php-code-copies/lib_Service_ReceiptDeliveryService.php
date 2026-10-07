<?php

   
                                   
  
                                                                               
                                                                             
                                                                               
                                                                              
                                                                       
                                                                            
          
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                  
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                                    
  
                                                                              
                                                                            
                                                                               
                                   
                                                                              
                                                                              
                                                                             
                                                                              
                                                                               
                                                        
  
                                                  
   
class ReceiptDeliveryService {
	   
                                                                     
   
                                                                  
                                                                            
                                       
   
                           
    
	private const RECEIPTABLE = ['confirmed', 'settled', 'refunded'];

	   
                
   
                                                               
                                                                                
                                                
                                                
                                                                
                                                
                                              
                                                                                         
    
	public function __construct(
		private ReceiptService $receiptService,
		private InvoiceSequenceService $invoiceSequence,
		private IMailer $mailer,
		private IAppConfig $appConfig,
		private PosAccessPolicy $policy,
		private IL10N $l10n,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                                 
   
                                                      
                                                          
                                                                                       
   
                                                                                                     
   
                                                                                  
                                                                               
   
                                                   
                                                                    
    
	public function preview(string $transactionId, ?string $templateId = null, string $userId = ''): array {
		$transaction = $this->loadTransactionWithLines(id: $transactionId);
		$this->assertCanAccess(transaction: $transaction, userId: $userId);
		$template = $this->loadTemplate(id: $templateId);

		$text = $this->receiptService->renderText(transaction: $transaction, template: $template);
		$html = $this->receiptService->renderHtml(transaction: $transaction, template: $template);

		return [
			'text' => $text,
			'html' => $html,
			'isInvoice' => $this->receiptService->isInvoiceTransaction(transaction: $transaction),
			'reference' => (string)($transaction['reference'] ?? $transaction['id'] ?? ''),
			'customerEmail' => $this->customerEmail(transaction: $transaction),
		];
	}               

	   
                                                  
   
                                                                           
                                                                             
                                                                          
                                                                             
                                                            
   
                                                                            
                                                                              
                                                      
   
                                                      
                                                          
                                                                                   
                                                                  
   
                                                                           
   
                                                                                  
                                                                                                  
   
                                                   
    
	public function emailReceipt(
		string $transactionId,
		?string $templateId,
		?string $requestedRecipient,
		string $userId,
	): array {
		$transaction = $this->loadReceiptableTransaction(id: $transactionId);
		$this->assertCanAccess(transaction: $transaction, userId: $userId);
		$template = $this->loadTemplate(id: $templateId);
		$recipient = $this->resolveCustomerRecipient(transaction: $transaction, requested: $requestedRecipient);

		$transaction = $this->ensureInvoiceNumber(transaction: $transaction);
		$subject = $this->l10n->t('Your receipt') . ' ' . (string)($transaction['reference'] ?? '');
		$text = $this->receiptService->renderText(transaction: $transaction, template: $template);
		$html = $this->receiptService->renderHtml(transaction: $transaction, template: $template);

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$recipient]);
			$message->setSubject($subject);
			$message->setPlainBody($text);
			$message->setHtmlBody($html);

			$sender = $this->appConfig->getValueString(Application::APP_ID, 'receipt_email_sender', '');
			if ($sender !== '' && $this->mailer->validateMailAddress($sender) === true) {
				$message->setFrom([$sender]);
			}

			$failed = $this->mailer->send($message);
			if (count($failed) > 0) {
				throw new RuntimeException('Mailer reported failed recipients.');
			}

			$logId = $this->writeLog(
				transaction: $transaction,
				template: $template,
				action: 'email',
				status: 'success',
				extra: [
					'emailRecipient' => $recipient,
					'renderedContent' => $text,
					'errorMessage' => null,
					'actor' => $userId,
				]
			);

			return ['status' => 'success', 'emailRecipient' => $recipient, 'logId' => $logId];
		} catch (\Throwable $e) {
			                                                                    
			                                                                    
			$this->logger->warning(
				'Pipelinq: receipt email send failed (SMTP may be disabled on this host)',
				['exception' => $e->getMessage()]
			);

			$logId = $this->writeLog(
				transaction: $transaction,
				template: $template,
				action: 'email',
				status: 'failed',
				extra: [
					'emailRecipient' => $recipient,
					'errorMessage' => $this->l10n->t('Mail delivery failed (no SMTP relay configured).'),
					'renderedContent' => null,
					'actor' => $userId,
				]
			);

			return [
				'status' => 'failed',
				'emailRecipient' => $recipient,
				'logId' => $logId,
				'error' => $this->l10n->t('Mail delivery failed (no SMTP relay configured).'),
			];
		}         
	}                    

	   
                                                              
   
                                                                           
                                                                          
                                                                              
                   
   
                                                      
                                                          
                                                                  
   
                                                                                     
   
                                                                                  
                                                                         
   
                                                   
    
	public function printReceipt(string $transactionId, ?string $templateId, string $userId): array {
		$transaction = $this->loadReceiptableTransaction(id: $transactionId);
		$this->assertCanAccess(transaction: $transaction, userId: $userId);
		$template = $this->loadTemplate(id: $templateId);
		$transaction = $this->ensureInvoiceNumber(transaction: $transaction);

		$bytes = $this->receiptService->renderEscPos(transaction: $transaction, template: $template);
		$device = $this->appConfig->getValueString(Application::APP_ID, 'receipt_printer_host', '');
		$port = $this->appConfig->getValueString(Application::APP_ID, 'receipt_printer_port', '9100');
		$target = '';
		if ($device !== '') {
			$target = $device . ':' . $port;
		}

		$logId = $this->writeLog(
			transaction: $transaction,
			template: $template,
			action: 'print',
			status: 'success',
			extra: [
				'printerDevice' => $target,
				'renderedContent' => $this->receiptService->renderText(transaction: $transaction, template: $template),
				'errorMessage' => null,
				'actor' => $userId,
			]
		);

		return [
			'status' => 'success',
			'escposBase64' => base64_encode($bytes),
			'byteLength' => strlen($bytes),
			'printerDevice' => $target,
			'logId' => $logId,
		];
	}                    

	   
                                                                     
   
                                                                              
                                                                              
                                                                             
                                             
   
                                                             
                                                                           
   
                                                   
   
                                                                                                  
   
                                                   
    
	private function resolveCustomerRecipient(array $transaction, ?string $requested): string {
		$customerEmail = $this->customerEmail(transaction: $transaction);
		if ($customerEmail === '') {
			throw new OCSBadRequestException(
				'Deze transactie heeft geen gekoppelde klant met een e-mailadres.'
			);
		}

		if ($requested !== null && trim($requested) !== '') {
			$requestedNorm = strtolower(trim($requested));
			if ($requestedNorm !== strtolower($customerEmail)) {
				throw new OCSBadRequestException(
					'Het bonnetje kan alleen naar het e-mailadres van de gekoppelde klant worden gestuurd.'
				);
			}
		}

		if ($this->mailer->validateMailAddress($customerEmail) === false) {
			throw new OCSBadRequestException('Ongeldig e-mailadres van de klant.');
		}

		return $customerEmail;
	}                                

	   
                                                                  
   
                                                             
   
                                                                 
    
	private function customerEmail(array $transaction): string {
		                                                                       
		$inline = (string)($transaction['customerEmail'] ?? '');
		if ($inline !== '') {
			return $inline;
		}

		$clientId = (string)($transaction['client'] ?? '');
		if ($clientId === '') {
			return '';
		}

		try {
			[$register, $schema] = $this->resolveSchema(schemaKey: 'client_schema');
			$client = $this->getObjectService()->find(id: $clientId, register: $register, schema: $schema);
			$client = $this->toArray(object: $client);

			return (string)($client['email'] ?? $client['emailAddress'] ?? '');
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: failed to resolve customer email', ['exception' => $e->getMessage()]);

			return '';
		}
	}                     

	   
                                                                     
   
                                                                              
                                                                            
                                                                                
                                                                                
                                                                               
                                                                           
                                                                            
                                                                              
                            
   
                                                             
   
                                                                                  
   
                                                   
    
	private function ensureInvoiceNumber(array $transaction): array {
		if ($this->receiptService->isInvoiceTransaction(transaction: $transaction) === false) {
			return $transaction;
		}

		$id = (string)($transaction['id'] ?? $transaction['uuid'] ?? '');
		$existing = (string)($transaction['invoiceNumber'] ?? '');
		if ($existing !== '' && $this->isServerIssuedInvoiceNumber(transactionId: $id, number: $existing) === true) {
			return $transaction;
		}

		$number = $this->invoiceSequence->next();
		$transaction['invoiceNumber'] = $number;

		                                                                          
		try {
			[$register, $schema] = $this->resolveSchema(schemaKey: 'posTransaction_schema');
			$id = (string)($transaction['id'] ?? $transaction['uuid'] ?? '');
			                                                      
			$persist = $transaction;
			unset($persist['lines'], $persist['@self']);
			$this->getObjectService()->saveObject(
				object: $persist,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $id
			);
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq: failed to persist invoice number', ['exception' => $e->getMessage()]);
		}         

		return $transaction;
	}                           

	   
                                                                          
                                                                      
   
                                                                          
                                                                           
                                                                  
   
                                                      
                                                       
   
                                                                                     
   
                                                   
    
	private function isServerIssuedInvoiceNumber(string $transactionId, string $number): bool {
		if ($transactionId === '' || $number === '') {
			return false;
		}

		try {
			[$register, $schema] = $this->resolveSchema(schemaKey: 'receiptPrintLog_schema');
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'transaction' => $transactionId,
						'invoiceNumber' => $number,
					],
				]
			);
		} catch (\Throwable $e) {
			                                                                      
			                                                            
			return false;
		}

		return count(($results ?? [])) > 0;
	}                                   

	   
                                                   
   
                                                                       
                                                                       
   
                                                             
                                                                           
                                             
                                                           
                                                              
   
                                                         
   
                                                   
    
	private function writeLog(array $transaction, array $template, string $action, string $status, array $extra): string {
		$log = array_merge(
			[
				'transaction' => (string)($transaction['id'] ?? $transaction['uuid'] ?? ''),
				'template' => (string)($template['id'] ?? $template['uuid'] ?? ''),
				'action' => $action,
				'status' => $status,
				'invoiceNumber' => (string)($transaction['invoiceNumber'] ?? ''),
				'printedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			],
			$extra
		);

		try {
			[$register, $schema] = $this->resolveSchema(schemaKey: 'receiptPrintLog_schema');
			$saved = $this->getObjectService()->saveObject(
				object: $log,
				extend: [],
				register: $register,
				schema: $schema
			);
			$saved = $this->toArray(object: $saved);

			return (string)($saved['id'] ?? $saved['uuid'] ?? '');
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq: failed to write receipt log', ['exception' => $e->getMessage()]);

			return '';
		}         
	}                

	   
                                                                      
   
                                           
   
                                                                      
   
                                                                                  
    
	private function loadTransactionWithLines(string $id): array {
		[$register, $schema] = $this->resolveSchema(schemaKey: 'posTransaction_schema');

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new OCSNotFoundException('Transactie niet gevonden.');
		}

		$transaction = $this->toArray(object: $object);
		$transaction['lines'] = $this->loadLines(transactionId: $id);

		return $transaction;
	}                                

	   
                                                                
   
                                           
   
                                                            
   
                                                                                  
                                                                                        
    
	private function loadReceiptableTransaction(string $id): array {
		$transaction = $this->loadTransactionWithLines(id: $id);
		$status = (string)($transaction['status'] ?? '');
		if (in_array($status, self::RECEIPTABLE, true) === false) {
			throw new OCSBadRequestException(
				'Een bonnetje kan alleen voor een bevestigde, afgerekende of teruggeboekte transactie worden gemaakt.'
			);
		}

		return $transaction;
	}                                  

	   
                                    
   
                                                             
   
                                                            
    
	private function loadLines(string $transactionId): array {
		try {
			[$register, $schema] = $this->resolveSchema(schemaKey: 'posTransactionLine_schema');
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'transaction' => $transactionId,
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: failed to load receipt lines', ['exception' => $e->getMessage()]);

			return [];
		}

		$lines = [];
		foreach (($results ?? []) as $result) {
			$lines[] = $this->toArray(object: $result);
		}

		return $lines;
	}                 

	   
                                                                 
   
                                                                            
                                                                               
   
                                                      
   
                                                                 
    
	private function loadTemplate(?string $id): array {
		if ($id === null || trim($id) === '') {
			return [];
		}

		try {
			[$register, $schema] = $this->resolveSchema(schemaKey: 'receiptTemplate_schema');
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);

			return $this->toArray(object: $object);
		} catch (\Throwable $e) {
			return [];
		}
	}                    

	   
                                                         
   
                                                                            
                                                                              
                                                                               
                                                                           
                                                
   
                                                                    
                                              
   
                
   
                                                                               
   
                                                                    
    
	private function assertCanAccess(array $transaction, string $userId): void {
		if ($this->policy->canAccessTransaction(object: $transaction, userId: $userId) === false) {
			throw new OCSForbiddenException(
				'U mag het bonnetje van deze transactie niet inzien. Alleen de eigen '
				. 'kassamedewerker, een lid van de POS-groep of een beheerder is gemachtigd.'
			);
		}
	}                       

	   
                                                                     
   
                                                       
   
                                                                   
   
                                                                             
    
	private function resolveSchema(string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('POS register of schema is niet geconfigureerd.');
		}

		return [$register, $schema];
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
}           
