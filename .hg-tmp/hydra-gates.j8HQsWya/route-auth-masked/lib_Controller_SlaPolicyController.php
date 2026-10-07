<?php

   
                                
  
                                                                 
                                                                  
            
                                                                   
                                                                    
                            
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                            
  
                                                         
  
                                                                    
                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                     
  
          
                                                                         
                                                                          
  
                                                                                          
   
class SlaPolicyController extends Controller {
	   
                
   
                                          
                                                                         
                                            
                                                    
                                          
    
	public function __construct(
		IRequest $request,
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                           
   
                                               
   
                                                          
    
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function create(): JSONResponse {
		$payload = $this->request->getParams();
		unset($payload['_route']);

		$justification = (string)($payload['justification'] ?? '');
		if (trim($justification) === '') {
			return new JSONResponse(
				['error' => 'justificationRequired', 'message' => 'Justification is required to modify SLA policies'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'sla_register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'sla_policy_schema', '');
		if ($register === '' || $schema === '') {
			return new JSONResponse(['error' => 'slaRegisterNotConfigured'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$saved = $objectService->saveObject(
				object: $payload,
				extend: [],
				register: $register,
				schema: $schema,
			);
			$this->auditLog(action: 'create', existing: null, payload: $payload, justification: $justification);
			return new JSONResponse($this->serialise(saved: $saved));
		} catch (Throwable $e) {
			$this->logger->error(
				'SlaPolicyController: create failed',
				['error' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'saveFailed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}              

	   
                               
   
                                  
   
                                               
   
                                                          
    
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function update(string $id): JSONResponse {
		$payload = $this->request->getParams();
		unset($payload['_route'], $payload['id']);

		$justification = (string)($payload['justification'] ?? '');
		if (trim($justification) === '') {
			return new JSONResponse(
				['error' => 'justificationRequired', 'message' => 'Justification is required to modify SLA policies'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'sla_register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'sla_policy_schema', '');
		if ($register === '' || $schema === '') {
			return new JSONResponse(['error' => 'slaRegisterNotConfigured'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$existing = null;
			try {
				$existing = $objectService->find(
					register: $register,
					schema: $schema,
					uuid: $id,
				);
			} catch (Throwable $e) {
				$this->logger->debug(
					'SlaPolicyController: existing policy not found, treating as create',
					['id' => $id]
				);
			}

			$saved = $objectService->saveObject(
				object: $payload,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $id,
			);
			$this->auditLog(action: 'update', existing: $existing, payload: $payload, justification: $justification);
			return new JSONResponse($this->serialise(saved: $saved));
		} catch (Throwable $e) {
			$this->logger->error(
				'SlaPolicyController: update failed',
				['error' => $e->getMessage(), 'id' => $id]
			);
			return new JSONResponse(['error' => 'saveFailed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}         
	}              

	   
                                                         
   
                                                                     
                                                                      
                                                               
   
                                         
                                                     
                                                           
                                                         
   
                
    
	private function auditLog(string $action, $existing, array $payload, string $justification): void {
		$actor = $this->userSession->getUser();
		$actorId = 'system';
		if ($actor !== null) {
			$actorId = $actor->getUID();
		}

		$before = null;
		if ($existing !== null) {
			$before = (array)$existing;
			if (method_exists($existing, 'getObject') === true) {
				$before = $existing->getObject();
			}
		}

		$this->logger->info(
			'SlaPolicyController: policy audited',
			[
				'action' => $action,
				'actor' => $actorId,
				'justification' => $justification,
				'before' => $before,
				'after' => $payload,
				'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			]
		);
	}                

	   
                                                              
   
                                      
   
                                                   
    
	private function serialise($saved): array {
		if (is_array($saved) === true) {
			return $saved;
		}

		if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
			$json = $saved->jsonSerialize();
			if (is_array($json) === true) {
				return $json;
			}

			return [];
		}

		if (is_object($saved) === true && method_exists($saved, 'getObject') === true) {
			$data = $saved->getObject();
			if (method_exists($saved, 'getUuid') === true) {
				$data['id'] = $data['id'] ?? (string)$saved->getUuid();
			}

			return $data;
		}

		return [];
	}                 
}           
