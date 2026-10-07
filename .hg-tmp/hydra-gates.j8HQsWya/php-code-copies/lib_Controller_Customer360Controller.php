<?php

   
                                  
  
                                                                                       
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                          
                                                                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use DateTimeImmutable;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Customer360SummaryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                 
  
                                                                                          
   
class Customer360Controller extends Controller {
	   
                                   
    
	private const ACCESS_GRANTED = 'granted';

	   
                                  
    
	private const ACCESS_DENIED = 'denied';

	   
                              
    
	private const ACCESS_MISSING = 'missing';

	   
                                                 
    
	private const ACCESS_FAILED = 'failed';

	   
                
   
                                         
                                                                                         
                                                  
                                                                                            
                                                                                   
                                                                                
                                         
    
	public function __construct(
		IRequest $request,
		private Customer360SummaryService $summaryService,
		private IUserSession $userSession,
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                                       
   
                                                                        
                                                                            
                                                                           
                                                                           
                                      
                                                                         
                                                                  
                                                           
   
                                                           
   
                    
   
                                                                                           
                                                                                                       
                                                                                                                                                           
    
	public function summary(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$clientId = (string)$this->request->getParam('clientId', '');
		if ($clientId === '') {
			return new JSONResponse(['message' => 'Missing required parameter: clientId'], Http::STATUS_BAD_REQUEST);
		}

		                                                                      
		                                                                
		$access = $this->clientAccess(clientId: $clientId);
		if ($access === self::ACCESS_DENIED) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		if ($access === self::ACCESS_MISSING) {
			return new JSONResponse(['message' => 'Client not found'], Http::STATUS_NOT_FOUND);
		}

		if ($access === self::ACCESS_FAILED) {
			return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		try {
			$summary = $this->summaryService->getSummary(clientId: $clientId);
		} catch (Throwable $e) {
			$this->logger->error(
				'Customer360Controller: summary failed',
				['clientId' => $clientId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$this->logAccess(actor: $user->getUID(), clientId: $clientId);

		return new JSONResponse($summary);
	}               

	   
                                                                 
   
                                                                     
                                                                       
                                                                          
                                                                          
                                                                   
   
                                                      
                                                               
                                                                           
                                                                           
                       
   
                                            
   
                                                 
   
                                                                                                                                                           
    
	private function clientAccess(string $clientId): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
		if ($register === '' || $schema === '') {
			return self::ACCESS_FAILED;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$object = $objectService->find(
				id: $clientId,
				register: $register,
				schema: $schema,
				_rbac: true,
				_multitenancy: true,
			);
		} catch (NotAuthorizedException $e) {
			return self::ACCESS_DENIED;
		} catch (Throwable $e) {
			                                                            
			                                                   
			if (str_ends_with(get_class($e), '\\DoesNotExistException') === true) {
				return self::ACCESS_MISSING;
			}

			$this->logger->warning(
				'Customer360Controller: client read-guard failed',
				['clientId' => $clientId, 'exception' => $e->getMessage()]
			);
			return self::ACCESS_FAILED;
		}

		if ($object === null) {
			return self::ACCESS_MISSING;
		}

		return self::ACCESS_GRANTED;
	}                    

	   
                                                                       
                                                                         
                                                                           
                                                                        
                                                                      
                          
   
                                         
                                                       
   
                
    
	private function logAccess(string $actor, string $clientId): void {
		$this->logger->info(
			'Customer 360 accessed',
			[
				'audit' => 'customer-360-access',
				'actor' => $actor,
				'clientId' => $clientId,
				'time' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
			]
		);
	}                 
}           
