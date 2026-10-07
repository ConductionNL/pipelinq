<?php

   
                                     
  
                                                                                
                                                                              
                                                                              
                                                                                
                                                                      
                                                                               
                      
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Service\Portal\DocumentSigningService;
use OCA\Pipelinq\Service\Portal\PortalAuditService;
use OCA\Pipelinq\Service\Portal\PortalExportService;
use OCA\Pipelinq\Service\Portal\PortalInvoiceService;
use OCA\Pipelinq\Service\Portal\PortalObjectRepository;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                  
  
                                                                                 
                                                                                     
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalDocumentController extends PortalApiController {
	   
                             
   
               
    
	private const ACCOUNT_SCHEMA = 'crmPortalAccount';

	   
                
   
                                         
                                                      
                                              
                                                               
                                                                               
                                                                           
                                                       
                                                                           
    
	public function __construct(
		IRequest $request,
		PortalRequestGuard $guard,
		LoggerInterface $logger,
		private DocumentSigningService $signing,
		private PortalInvoiceService $invoices,
		private PortalObjectRepository $repository,
		private PortalAuditService $audit,
		private PortalExportService $exports,
	) {
		parent::__construct(request: $request, guard: $guard, logger: $logger);
	}                   

	   
                                                                       
   
                                                                       
                                                                         
   
                                                    
   
                                                                              
                                                         
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 20, period: 60)]
	public function sign(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				$objectId = $this->strParam(name: 'objectId');
				$objectType = $this->strParam(name: 'objectType', default: 'invoice');

				                                                             
				if ($this->ensureAccess(account: $ctx['account'], objectType: $objectType, objectId: $objectId) === false) {
					return [['errorCode' => 'notFound', 'message' => 'Niet gevonden.'], Http::STATUS_NOT_FOUND];
				}

				$signed = $this->signing->generateUrl(objectId: $objectId, objectType: $objectType, accountId: $ctx['accountId']);
				return [['downloadUrl' => $signed['path'], 'expiresAt' => $signed['expiresAt']], Http::STATUS_OK];
			}
		);
	}            

	   
                                           
   
                                          
   
                                                                        
   
                    
                   
               
   
                                                           
    
	#[AnonRateLimit(limit: 60, period: 60)]
	public function download(string $token) {
		try {
			$result = $this->signing->validateToken(token: $token);
			if ($result === 'expired') {
				return new JSONResponse(
					['errorCode' => 'linkExpired', 'message' => 'Deze link is verlopen. Download het document opnieuw vanuit het portaal.'],
					Http::STATUS_GONE
				);
			}

			if (is_array($result) === false) {
				return new JSONResponse(['errorCode' => 'notFound', 'message' => 'Niet gevonden.'], Http::STATUS_NOT_FOUND);
			}

			$accountId = (string)($result['accountId'] ?? '');
			$objectId = (string)($result['objectId'] ?? '');
			$objectType = (string)($result['objectType'] ?? '');
			$account = $this->repository->find(self::ACCOUNT_SCHEMA, $accountId);

			                                                                  
			if ($account === null
				|| ($account['status'] ?? 'active') === 'closed'
				|| ($objectType !== 'data-export' && $this->ensureAccess(account: $account, objectType: $objectType, objectId: $objectId) === false)
			) {
				return new JSONResponse(['errorCode' => 'notFound', 'message' => 'Niet gevonden.'], Http::STATUS_NOT_FOUND);
			}

			$this->audit->log(
				$accountId,
				(string)($account['tenantId'] ?? ''),
				'document-download',
				'success',
				[
					'targetObjectType' => $objectType,
					'targetObjectId' => $objectId,
				]
			);

			                                                                      
			                                                               
			                                                                      
			                                                                      
			                                                   
			                                           
			  
			                                                            
			                                                                 
			                                                
			                                                        
			                                                                  
			                                              
			  
			                                                              
			                                                                     
			$payload = $this->documentPayload(objectType: $objectType, objectId: $objectId);
			if ($objectType === 'data-export') {
				$payload = $this->exports->buildExport(account: $account);
			}
			return new DataDownloadResponse(
				(string)json_encode($payload),
				$objectType . '-' . $objectId . '.json',
				'application/json'
			);
		} catch (Throwable $e) {
			                                                                   
			                                                                 
			                                                               
			                                                             
			                                                         
			                                                               
			                                                         
			$this->logger->error(
				'Portal document download failed',
				['exception' => $e]
			);
			return new JSONResponse(['errorCode' => 'notFound', 'message' => 'Niet gevonden.'], Http::STATUS_NOT_FOUND);
		}         
	}                

	   
                                                                     
                                                                        
                                                                        
                                                         
   
                                                     
                                                           
                                                           
                                                           
                                                    
                                          
   
                                      
   
                                                                             
                                                                           
                                                                             
    
	private function ensureAccess(array $account, string $objectType, string $objectId): bool {
		if ($objectId === '') {
			return false;
		}

		return $this->invoices->getOneForAccount(account: $account, id: $objectId) !== null;
	}                    

	   
                                                        
   
                                              
                                          
   
                                                
    
	private function documentPayload(string $objectType, string $objectId): array {
		return [
			'objectType' => $objectType,
			'objectId' => $objectId,
			'generatedAt' => gmdate(DATE_ATOM),
		];
	}                       
}           
