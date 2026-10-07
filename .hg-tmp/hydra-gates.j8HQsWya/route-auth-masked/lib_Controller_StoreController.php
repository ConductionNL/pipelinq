<?php

   
                            
  
                                                       
                                                                           
                                                                         
                                                                             
                                                                             
  
                                                                          
                                                                       
                                                                         
          
  
                                                                            
                                                                               
                                                                            
                                                                             
                                                                         
                                                                             
  
                                                                         
                                                                            
                                                                              
  
                       
                                    
  
                                                              
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                              
  
                                                                                     
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Service\StoreDescriptor;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\RegisterResolverService;
use OCA\Pipelinq\Service\Settings\SchemaSlugMap;
use OCA\Pipelinq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                              
  
                                                                        
                                                                               
                                                                              
                                                                               
                                                                 
                                                                              
                                                                          
                                                                           
                                                                               
                                 
  
                                                                                     
   
class StoreController extends Controller {
	   
                                                    
    
	private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/';

	   
                                                             
   
                                                          
   
                                                                      
                                                                       
                                                                        
                                                                         
                                                                 
   
                                                                             
                                                                          
                                                                           
   
                                                                             
                                                                          
   
                                                                       
                                                                     
                                     
   
                           
    
	private const INSTALLABLE_SLUGS = [
		'pipeline',
		'skill',
		'product',
		'productCategory',
		'billingCategory',
		'posRole',
		'posTenderType',
		'receiptTemplate',
		'refundReason',
		'loyaltyProgramme',
		'pointsRule',
		'berichtenboxTemplate',
	];

	   
                
   
                                                      
                                              
                                                                    
                                                                                                 
                                                                                 
                                                                                         
                                                                                     
   
                
    
	public function __construct(
		IRequest $request,
		private readonly LoggerInterface $logger,
		private readonly IUserSession $userSession,
		private readonly IAppConfig $appConfig,
		private readonly GenericStoreService $storeService,
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterResolverService $registerResolver,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                
   
                           
   
                                                                                      
    
	private function descriptor(): StoreDescriptor {
		return new StoreDescriptor(
			appId: Application::APP_ID,
			schema: 'commercial-template',
			defaultRegister: 'pipelinq',
			cardFields: [
				'slug' => 'slug',
				'title' => 'title',
				'description' => 'description',
				'kind' => 'kind',
				'category' => 'category',
				'version' => 'version',
				'publisher' => 'publisher',
			]
		);
	}                  

	   
                            
   
                                                                           
                                                                             
                                                     
   
                                                                           
                                                                   
                                                       
   
                                                                        
   
                                                                                      
   
                                                                           
                                                                            
                                                             
    
	#[NoAdminRequired]
	public function search(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(
				data: ['outcome' => 'unauthenticated', 'cards' => []],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		$query = $this->request->getParam('q');
		if (is_string($query) === false) {
			$query = null;
		}

		$kind = $this->request->getParam('kind');
		if (is_string($kind) === false) {
			$kind = null;
		}

		try {
			$result = $this->storeService->search(
				descriptor: $this->descriptor(),
				query: $query,
				kind: $kind
			);
		} catch (Throwable $e) {
			                                                                  
			                                           
			$this->logger->error('Pipelinq store: search failed: ' . $e->getMessage());
			return new JSONResponse(
				data: ['outcome' => GenericStoreService::OUTCOME_UNREACHABLE, 'cards' => []],
				statusCode: Http::STATUS_OK
			);
		}

		return new JSONResponse(
			data: ['outcome' => $result['outcome'], 'cards' => $result['cards']],
			statusCode: Http::STATUS_OK
		);
	}              

	   
                                              
   
                                                                         
                                                      
   
                                                               
                                                                            
                                                                        
                          
   
                                             
   
                                                                                                  
   
                                                                                      
    
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function install(string $slug): JSONResponse {
		if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
			return new JSONResponse(
				data: ['success' => false, 'message' => 'Malformed item slug.'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$item = $this->storeService->resolve(descriptor: $this->descriptor(), slug: $slug);
		} catch (Throwable $e) {
			$this->logger->error('Pipelinq store: resolve failed: ' . $e->getMessage());
			$item = null;
		}

		if ($item === null) {
			return new JSONResponse(
				data: ['success' => false, 'message' => 'The store item could not be resolved.'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse(
			data: $this->installComponents(components: $this->components(item: $item)),
			statusCode: Http::STATUS_OK
		);
	}               

	   
                                                       
   
                                                                 
   
                                                                                     
   
                                                                                      
    
	private function components(array $item): array {
		$components = ($item['components'] ?? null);

		                                                           
		if (is_string($components) === true) {
			$components = json_decode($components, true);
		}

		if (is_array($components) === false) {
			return [];
		}

		return array_values(array_filter($components, static fn ($c): bool => is_array($c) === true));
	}                  

	   
                                                                
   
                                                                        
                                                                   
                                                                           
                                                 
   
                                                                                
   
                                                                               
   
                                                                                      
    
	private function installComponents(array $components): array {
		$report = [];
		$failed = false;
		$registerId = $this->registerResolver->resolve();

		foreach ($components as $component) {
			$slug = (string)($component['schema'] ?? '');
			$object = ($component['object'] ?? null);

			if (in_array($slug, self::INSTALLABLE_SLUGS, true) === false) {
				$failed = true;
				$report[] = [
					'schema' => $slug,
					'status' => 'refused',
					'message' => 'Only commercial configuration installs from the store; '
						. 'this component names a record schema.',
				];
				continue;
			}

			if (is_array($object) === false) {
				$failed = true;
				$report[] = [
					'schema' => $slug,
					'status' => 'refused',
					'message' => 'The component declares no object to install.',
				];
				continue;
			}

			                                                                  
			                                                                
			                                                                  
			                                                     
			$schemaId = $this->appConfig->getValueString(
				Application::APP_ID,
				SchemaSlugMap::SLUG_TO_CONFIG_KEY[$slug],
				''
			);

			if ($registerId === '' || $schemaId === '') {
				$failed = true;
				$report[] = [
					'schema' => $slug,
					'status' => 'error',
					'message' => 'This instance has no register or schema configured for that component.',
				];
				continue;
			}

			try {
				$this->objectService->saveObject(
					$this->asNewObject(object: $object),
					[],
					$registerId,
					$schemaId,
					null
				);
				$report[] = ['schema' => $slug, 'status' => 'installed', 'message' => ''];
			} catch (Throwable $e) {
				$failed = true;
				$this->logger->error(
					'Pipelinq store: installing {schema} failed: {error}',
					['schema' => $slug, 'error' => $e->getMessage()]
				);
				$report[] = [
					'schema' => $slug,
					'status' => 'error',
					'message' => 'The component could not be written.',
				];
			}         
		}             

		return ['success' => ($failed === false && $report !== []), 'components' => $report];
	}                         

	   
                                                                           
   
                                                        
   
                                                                            
                                                           
                                                                            
                                                                       
                                                                           
                                                                             
                                                                    
   
                                                                       
                                                                           
                                                           
   
                                                                        
                                                                           
                                                           
   
                                                               
   
                                                                            
   
                                                                                      
    
	private function asNewObject(array $object): array {
		unset($object['id'], $object['uuid'], $object['@self']);

		return $object;
	}                   

	   
                                 
   
                                                                             
                                                                          
                                                      
   
                                                                        
   
                                                                                      
    
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function getSettings(): JSONResponse {
		return new JSONResponse(
			data: [
				'registryUrl' => $this->appConfig->getValueString(Application::APP_ID, 'registry_url', ''),
				'registryRegister' => $this->appConfig->getValueString(
					Application::APP_ID,
					'registry_register',
					'pipelinq'
				),
				'registryTokenSet' => trim(
					$this->appConfig->getValueString(Application::APP_ID, 'registry_token', '')
				) !== '',
			],
			statusCode: Http::STATUS_OK
		);
	}                   

	   
                                 
   
                                                                            
                                                                            
                                                                          
                                                            
   
                                                                        
   
                                                                                      
    
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function saveSettings(): JSONResponse {
		$url = $this->request->getParam('registryUrl');
		if (is_string($url) === true) {
			$this->appConfig->setValueString(Application::APP_ID, 'registry_url', trim($url));
		}

		$register = $this->request->getParam('registryRegister');
		if (is_string($register) === true && trim($register) !== '') {
			$this->appConfig->setValueString(Application::APP_ID, 'registry_register', trim($register));
		}

		$token = $this->request->getParam('registryToken');
		if (is_string($token) === true && trim($token) !== '') {
			$this->appConfig->setValueString(Application::APP_ID, 'registry_token', trim($token));
		}

		return $this->getSettings();
	}                    
}           
