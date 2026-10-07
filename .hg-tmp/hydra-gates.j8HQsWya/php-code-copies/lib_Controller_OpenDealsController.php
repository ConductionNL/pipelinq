<?php

   
                                
  
                                                                            
                                                                     
                                                                         
                                                                          
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                                 
  
                                    
                                                                    
  
                                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

   
                                                         
  
                                                 
   
class OpenDealsController extends Controller {

	   
                                                                        
   
                              
    
	private const PARTIES = [
		'contact' => 'contact_schema',
		'client' => 'client_schema',
	];

	   
                                                 
   
            
    
	private const LIMIT = 1000;

	   
                
   
                                                             
                                                                                       
                                                                               
                                                                    
    
	public function __construct(
		IRequest $request,
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                               
   
                                                                           
                                                                         
   
                                                                      
   
                                                                                        
    
	#[NoAdminRequired]
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		[$field, $partyId] = $this->party();
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$leadSchema = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
		if ($field === '' || $register === '' || $leadSchema === '') {
			return new JSONResponse(['error' => 'Name one contact or client'], Http::STATUS_BAD_REQUEST);
		}

		$partySchema = $this->appConfig->getValueString(Application::APP_ID, self::PARTIES[$field], '');
		if ($this->canRead(id: $partyId, register: $register, schema: $partySchema) === false) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$rows = $this->objectService->findAll(
				config: [
					'filters' => ['register' => $register, 'schema' => $leadSchema, $field => $partyId, 'status' => 'open'],
					'limit' => self::LIMIT,
				]
			);
		} catch (Throwable) {
			return new JSONResponse(['error' => 'Deals unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse($this->summarise(rows: $rows, field: $field, partyId: $partyId));
	}             

	   
                                              
   
                                                                     
   
                                                                   
                                                                    
                                                 
   
                                                           
    
	private function summarise(iterable $rows, string $field, string $partyId): array {
		$count = 0;
		$value = 0.0;
		foreach ($rows as $row) {
			$data = $this->toArray(row: $row);
			if (($data['status'] ?? '') !== 'open' || (string)($data[$field] ?? '') !== $partyId) {
				continue;
			}

			$count++;
			$value += (float)($data['value'] ?? 0);
		}

		return ['openDealCount' => $count, 'openDealValue' => round($value, 2)];
	}                 

	   
                                                       
   
                                                                                                  
    
	private function party(): array {
		$named = [];
		foreach (array_keys(self::PARTIES) as $field) {
			$id = trim((string)$this->request->getParam($field, ''));
			if ($id !== '') {
				$named[] = [$field, $id];
			}
		}

		if (count($named) !== 1) {
			return ['', ''];
		}

		return $named[0];
	}             

	   
                                                          
   
                                         
                                            
                                                
   
                                                     
    
	private function canRead(string $id, string $register, string $schema): bool {
		if ($schema === '') {
			return false;
		}

		try {
			return $this->objectService->find(id: $id, register: $register, schema: $schema, _audit: false) !== null;
		} catch (Throwable) {
			return false;
		}
	}               

	   
                                                     
   
                              
   
                                                            
    
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$data = $row->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}               
}           
