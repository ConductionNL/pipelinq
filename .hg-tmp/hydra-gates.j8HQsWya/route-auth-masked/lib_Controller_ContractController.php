<?php

   
                               
  
                                                                               
                                                                          
                                                                          
                                                                   
  
                                                                                
                                                                            
                                                        
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ContractService;
use OCA\Pipelinq\Service\RecurringRevenueService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                     
  
                                                                                                   
   
class ContractController extends Controller {
	   
                
   
                                         
                                                                       
                                                                             
                                                      
                                                                                
                                                          
                                              
    
	public function __construct(
		IRequest $request,
		private ContractService $contractService,
		private RecurringRevenueService $revenueService,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $accessPolicy,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                   
   
                                                                            
                                                                        
   
                                                          
   
                                                                                                    
    
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		                                                    
		                                                                       
		                                                                      
		                                                             
		                                                                  
		                                                       
		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$body = $this->request->getParams();
		unset($body['_route']);

		if (trim((string)($body['title'] ?? '')) === '' || trim((string)($body['clientRef'] ?? '')) === '') {
			return new JSONResponse(['message' => 'title and clientRef are required'], Http::STATUS_BAD_REQUEST);
		}

		$existing = $this->contractService->loadAll();
		$body['contractNumber'] = $this->contractService->generateContractNumber($existing);
		if (trim((string)($body['ownerId'] ?? '')) === '') {
			$body['ownerId'] = $user->getUID();
		}

		if (trim((string)($body['status'] ?? '')) === '') {
			$body['status'] = 'draft';
		}

		$saved = $this->contractService->save($body, null);
		if ($saved === null) {
			return new JSONResponse(['message' => 'Failed to save contract'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($saved, Http::STATUS_CREATED);
	}              

	   
                                                       
   
                                                                        
                                                                           
                                                                       
   
                                        
   
                                                          
   
                                                                                                    
    
	#[NoAdminRequired]
	public function transition(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$newStatus = (string)$this->request->getParam('status', '');
		$reason = (string)$this->request->getParam('cancellationReason', '');

		$contract = $this->loadContract(id: $id);
		if ($contract === null) {
			return new JSONResponse(['message' => 'Contract not found'], Http::STATUS_NOT_FOUND);
		}

		if ($this->isAuthorized(uid: $user->getUID(), contract: $contract) === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		if ($reason !== '') {
			$contract['cancellationReason'] = $reason;
		}

		try {
			$this->contractService->assertTransitionAllowed($contract, $newStatus, false);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$contract['status'] = $newStatus;
		$saved = $this->contractService->save($contract, $id);
		if ($saved === null) {
			return new JSONResponse(['message' => 'Failed to save contract'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		                                                                     
		if ($newStatus === 'renewed') {
			$successor = $this->contractService->buildSuccessorDraft($saved);
			$this->contractService->save($successor, null);
		}

		return new JSONResponse($saved);
	}                  

	   
                                                                       
   
                                     
   
                                                                                      
                                                                                         
   
                                                                                                
    
	#[NoAdminRequired]
	public function summary(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		                                                                  
		                                                                       
		                                       
		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse($this->revenueService->getSummary());
	}               

	   
                                                      
   
                                             
   
                                                                                    
                                                                             
   
                                                                                                
    
	#[NoAdminRequired]
	public function renewalMetrics(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		                                                                     
		                                                                    
		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$from = (string)$this->request->getParam('from', date('Y-m-d', strtotime('-3 months')));
		$to = (string)$this->request->getParam('to', date('Y-m-d'));

		$contracts = $this->contractService->loadAll();
		return new JSONResponse($this->revenueService->computeRenewalMetrics($contracts, $from, $to));
	}                      

	   
                                                    
   
                               
   
                                                                                    
    
	private function loadContract(string $id): ?array {
		[$registerId, $schemaId] = $this->contractService->getRegisterAndSchema();
		if ($registerId === '' || $schemaId === '') {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			                                                                       
			                                                                   
			                                                                    
			                                                                      
			                                       
			$object = $objectService->find(
				id: $id,
				register: $registerId,
				schema: $schemaId,
			);
			if ($object === null) {
				return null;
			}

			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				return $object->jsonSerialize();
			}

			return (array)$object;
		} catch (Throwable $e) {
			$this->logger->warning('ContractController: loadContract failed', ['error' => $e->getMessage()]);
			return null;
		}         
	}                    

	   
                                                                             
                                                                             
   
                                          
                                                             
   
                                                               
    
	private function isAuthorized(string $uid, array $contract): bool {
		return $this->accessPolicy->mayAccess(uid: $uid, object: $contract, ownerField: 'ownerId');
	}                    
}           
