<?php

   
                                
  
                                                                              
                                                                           
                                                                               
                                                                                 
                                                                            
                                                                                
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

   
                                               
  
                                                                                 
                                                                        
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalExportService {
	   
                                                       
   
            
    
	private const TTL_MINUTES = (30 * 24 * 60);

	   
                
   
                                                             
                                                       
                                                             
                                                                        
                                                    
                                               
                                                
    
	public function __construct(
		private PortalProfileService $profile,
		private PortalAuditService $audit,
		private PortalInvoiceService $invoices,
		private DocumentSigningService $signing,
		private PortalMailService $mail,
		private ITimeFactory $time,
		private IL10N $l10n,
	) {
	}                   

	   
                                                               
   
                                                                   
   
                                                    
                                                                                         
                                                                            
                                                                            
                               
    
	public function buildExport(array $account): array {
		$accountId = (string)($account['@self']['id'] ?? $account['id'] ?? $account['uuid'] ?? '');

		$invoicePage = $this->invoices->getForAccount($account, 1, 100);

		return [
			'exportedAt' => $this->time->getDateTime()->format(DATE_ATOM),
			'account' => $this->profile->present(account: $account),
			'auditEvents' => array_map(
				static fn (array $event): array => [
					'eventType' => ($event['eventType'] ?? null),
					'outcome' => ($event['outcome'] ?? null),
					'occurredAt' => ($event['occurredAt'] ?? null),
				],
				$this->audit->getForAccount($accountId)
			),
			'documents' => array_map(
				static fn (array $invoice): array => [
					'type' => 'invoice',
					'number' => ($invoice['invoiceNumber'] ?? null),
					'date' => ($invoice['date'] ?? null),
					'amount' => ($invoice['amount'] ?? null),
				],
				$invoicePage['items']
			),
		];
	}                   

	   
                                                                     
   
                                                                   
                                          
   
                                                                               
                                                                                         
                                                                            
                                                                            
                               
    
	public function requestExport(array $account, string $tenantId): array {
		$accountId = (string)($account['@self']['id'] ?? $account['id'] ?? $account['uuid'] ?? '');
		$signed = $this->signing->generateUrl(
			objectId: $accountId,
			objectType: 'data-export',
			accountId: $accountId,
			ttlMinutes: self::TTL_MINUTES
		);

		$this->mail->send(
			(string)($account['email'] ?? ''),
			$this->l10n->t('Your data export is ready'),
			$this->l10n->t('Your data export is ready to download. The link is valid for 30 days.')
		);

		$this->audit->log(accountId: $accountId, tenantId: $tenantId, eventType: 'data-export-requested', outcome: 'success');

		return ['downloadUrl' => $signed['path'], 'expiresAt' => $signed['expiresAt']];
	}                     
}           
