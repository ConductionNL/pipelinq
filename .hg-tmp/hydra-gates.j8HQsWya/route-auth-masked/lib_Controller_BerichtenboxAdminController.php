<?php

   
                                        
  
                                                                
                                                                      
                                                                        
                                                                          
                                                                      
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                        
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                        
  
                                                   
   
class BerichtenboxAdminController extends Controller {
	   
                
   
                                     
                                            
                                                                                         
    
	public function __construct(
		IRequest $request,
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                       
                                   
   
                                               
   
                                                                                                                                          
   
                        
                                                    
    
	public function retry(string $id): JSONResponse {
		try {
			$service = $this->getObjectService();
			$message = $service->find(
				id: $id,
				register: $this->appConfig->getValueString(Application::APP_ID, 'register', ''),
				schema: $this->appConfig->getValueString(Application::APP_ID, 'berichtenboxMessage_schema', '')
			);
		} catch (Throwable $e) {
			return new JSONResponse(
				['error' => 'Message not found: ' . $e->getMessage()],
				Http::STATUS_NOT_FOUND
			);
		}

		if ($message === null) {
			return new JSONResponse(['error' => 'Message not found'], Http::STATUS_NOT_FOUND);
		}

		$data = $this->toArray(row: $message);
		if ($data === null) {
			return new JSONResponse(['error' => 'Unreadable message'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		                                                              
		$data['retryCount'] = 0;
		$data['nextRetryAt'] = '';
		$data['deliveryStatus'] = 'queued';
		try {
			$service
				->saveObject(
					object: $data,
					extend: [],
					register: $this->appConfig->getValueString(Application::APP_ID, 'register', ''),
					schema: $this->appConfig->getValueString(Application::APP_ID, 'berichtenboxMessage_schema', ''),
					uuid: ($data['uuid'] ?? $id)
				);
		} catch (Throwable $e) {
			return new JSONResponse(
				['error' => 'Save failed: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse(['ok' => true, 'id' => $id, 'status' => 'queued']);
	}             

	   
                                                                  
   
                                                                                                                                               
   
                        
   
                                                                                                     
    
	public function stats(): JSONResponse {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'berichtenboxMessage_schema', '');
		$counters = [
			'queued' => 0,
			'sent' => 0,
			'read' => 0,
			'fallback-emailed' => 0,
			'failed' => 0,
			'opted-out' => 0,
		];
		if ($register === '' || $schema === '') {
			return new JSONResponse(['counters' => $counters, 'unread' => 0]);
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
				]
			);
		} catch (Throwable $e) {
			return new JSONResponse(
				['error' => 'Stats query failed: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse($this->tally(rows: $rows, counters: $counters));
	}             

	   
                                                          
   
                                         
                                                        
   
                                               
    
	private function tally(iterable $rows, array $counters): array {
		$unread = 0;
		foreach ($rows as $row) {
			$data = $this->toArray(row: $row);
			if ($data === null) {
				continue;
			}

			$status = (string)($data['deliveryStatus'] ?? '');
			if (isset($counters[$status]) === true) {
				$counters[$status]++;
			}

			if ($status === 'sent' && (($data['readAt'] ?? '') === '' || $data['readAt'] === null)) {
				$unread++;
			}
		}

		return ['counters' => $counters, 'unread' => $unread];
	}             

	   
                       
   
                                                             
    
	private function getObjectService(): \OCA\OpenRegister\Contract\ObjectServiceInterface {
		return $this->objectService;
	}                        

	   
                                 
   
                          
   
                      
    
	private function toArray(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true) {
			if (method_exists($row, 'jsonSerialize') === true) {
				$serialized = $row->jsonSerialize();
				if (is_array($serialized) === true) {
					return $serialized;
				}
			}

			if (method_exists($row, 'getObject') === true) {
				$inner = $row->getObject();
				if (is_array($inner) === true) {
					return $inner;
				}
			}
		}

		return null;
	}               
}           
