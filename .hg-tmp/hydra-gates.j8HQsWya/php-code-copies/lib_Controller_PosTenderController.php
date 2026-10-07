<?php

   
                                
  
                                                                           
                   
  
                                                                          
                                                                        
                                                                     
                                                                             
                                                                               
                                                                           
                                     
  
                                                                        
                                                                          
                                                                           
                                                                         
                                                                           
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                               
                                                               
                                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\InvalidTenderException;
use OCA\Pipelinq\Service\PosTenderService;
use OCA\Pipelinq\Service\TenderTypeNotFoundException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                                      
  
                       
                                                                               
                                                 
                                                                           
                                                                                  
  
                                                                               
                                                                             
                               
  
                                                               
   
class PosTenderController extends Controller {
	   
                
   
                                         
                                                        
                                                  
                                              
    
	public function __construct(
		IRequest $request,
		private PosTenderService $service,
		private IUserSession $session,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	                                                                        
	                                                                 
	                                                                        

	   
                                                  
   
                                                            
   
                                                              
   
                                                                
    
	#[NoAdminRequired]
	public function indexTypes(): JSONResponse {
		$this->requireAuthenticatedUser();

		$activeOnly = $this->request->getParam(key: 'activeOnly', default: '0') === '1';

		try {
			$types = $this->service->listTenderTypes(activeOnly: $activeOnly);
			return new JSONResponse(['results' => $types, 'total' => count($types)]);
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq POS tender: listTenderTypes failed',
				['exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'Failed to list tender types'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}                  

	   
                                                           
   
                                           
   
                                                              
   
                                                                
    
	#[NoAdminRequired]
	public function showType(string $id): JSONResponse {
		$this->requireAuthenticatedUser();

		try {
			return new JSONResponse($this->service->getTenderTypeById(id: $id));
		} catch (TenderTypeNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq POS tender: showType failed',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'Failed to fetch tender type'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}                

	   
                                                              
   
                                                               
   
                                                                
    
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function createType(): JSONResponse {
		$payload = $this->bodyPayload();

		try {
			return new JSONResponse(
				$this->service->createTenderType(data: $payload),
				Http::STATUS_CREATED
			);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq POS tender: createType failed',
				['exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'Failed to create tender type'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}                  

	   
                                                                  
   
                                           
   
                                                 
   
                                                                
    
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function updateType(string $id): JSONResponse {
		$payload = $this->bodyPayload();

		try {
			return new JSONResponse($this->service->updateTenderType(id: $id, data: $payload));
		} catch (TenderTypeNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq POS tender: updateType failed',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'Failed to update tender type'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}                  

	   
                                                                     
   
                                                                 
   
                                           
   
                                             
   
                                                                
    
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function destroyType(string $id): JSONResponse {
		try {
			$this->service->deleteTenderType(id: $id);
			return new JSONResponse(null, Http::STATUS_NO_CONTENT);
		} catch (TenderTypeNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq POS tender: destroyType failed',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'Failed to delete tender type'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}                   

	                                                                        
	                                                                              
	                                                                        

	   
                                                                     
   
                                                                         
                                                         
   
                                                         
   
                                                                 
   
                                                                
                                                                
    
	#[NoAdminRequired]
	public function indexTenders(string $transactionId): JSONResponse {
		$this->requireAuthenticatedUser();

		try {
			$tenders = $this->service->getTendersForTransaction(transactionId: $transactionId);
			$validation = $this->service->validateTenderSum(transactionId: $transactionId);
			return new JSONResponse(
				[
					'results' => $tenders,
					'total' => count($tenders),
					'validation' => $validation,
				]
			);
		} catch (OCSNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq POS tender: indexTenders failed',
				['transactionId' => $transactionId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'Failed to list tenders'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}         
	}                    

	   
                                                                      
   
                                                                                  
   
                                                         
   
                                                                             
   
                                                                
    
	#[NoAdminRequired]
	public function addTender(string $transactionId): JSONResponse {
		$this->requireAuthenticatedUser();

		$payload = $this->bodyPayload();

		try {
			$tender = $this->service->addTender(transactionId: $transactionId, payload: $payload);
			$validation = $this->service->validateTenderSum(transactionId: $transactionId);
			return new JSONResponse(
				['tender' => $tender, 'validation' => $validation],
				Http::STATUS_CREATED
			);
		} catch (TenderTypeNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (OCSNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (InvalidTenderException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getStatusCode());
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq POS tender: addTender failed',
				['transactionId' => $transactionId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'Failed to add tender'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}         
	}                 

	   
                                                                                      
   
                                                         
                                               
   
                                             
   
                                                                
    
	#[NoAdminRequired]
	public function removeTender(string $transactionId, string $tenderId): JSONResponse {
		$this->requireAuthenticatedUser();

		try {
			$this->service->removeTender(transactionId: $transactionId, tenderId: $tenderId);
			return new JSONResponse(null, Http::STATUS_NO_CONTENT);
		} catch (OCSNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (InvalidTenderException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getStatusCode());
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq POS tender: removeTender failed',
				[
					'transactionId' => $transactionId,
					'tenderId' => $tenderId,
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				['error' => 'Failed to remove tender'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}         
	}                    

	   
                                                                                 
   
                                                                           
                                                                      
                                                                   
   
                                                         
   
                                                
   
                                                                
    
	#[NoAdminRequired]
	public function summary(string $transactionId): JSONResponse {
		$this->requireAuthenticatedUser();

		try {
			return new JSONResponse(
				$this->service->validateTenderSum(transactionId: $transactionId)
			);
		} catch (OCSNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq POS tender: summary failed',
				['transactionId' => $transactionId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'Failed to validate tender sum'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}               

	                                                                        
	                                                   
	                                                                        

	   
                                                  
   
                                                                         
                                                                    
                                                                      
                                                                        
                                                       
   
                
   
                                                                             
    
	private function requireAuthenticatedUser(): void {
		$user = $this->session->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('Authentication required');
		}
	}                                

	   
                                                   
   
                                             
    
	private function bodyPayload(): array {
		$body = (string)file_get_contents('php://input');
		if ($body === '') {
			return $this->request->getParams();
		}

		$decoded = json_decode($body, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return $this->request->getParams();
	}                   
}           
