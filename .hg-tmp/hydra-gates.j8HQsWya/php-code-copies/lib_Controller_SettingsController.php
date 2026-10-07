<?php

   
                               
  
                                                         
  
                       
                                    
  
                                                              
                                  
                                                                                    
  
                         
  
                            
  
                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

   
                                    
  
                                                  
  
                                                        
   
class SettingsController extends Controller {

	   
                                    
   
                                                                                                
    
	private ?\OCA\OpenRegister\Contract\ObjectServiceInterface $objectService = null;

	   
                
   
                                         
                                                       
                                                   
                                                         
                                                                 
                                                      
                                                
                                              
    
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly IGroupManager $groupManager,
		private SettingsService $settingsService,
		private IUserSession $userSession,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                     
   
                                                                                                                         
                                                              
                                                                           
    
	public function getObjectService(): ?\OCA\OpenRegister\Contract\ObjectServiceInterface {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			$this->objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			return $this->objectService;
		}

		throw new RuntimeException('OpenRegister service is not available.');
	}                        

	   
                                                                      
   
                                                                                                                       
                                                              
                                                                           
    
	public function getConfigurationService(): ?\OCA\OpenRegister\Service\ConfigurationService {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			$configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
			return $configurationService;
		}

		throw new RuntimeException('Configuration service is not available.');
	}                               

	   
                                  
   
                                               
   
                                               
                                                         
    
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		$isAdmin = $user !== null && $this->groupManager->isAdmin($user->getUID());

		$config = $this->settingsService->getSettings();
		$response = [
			'success' => true,
			'openRegisters' => in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()),
			'isAdmin' => $isAdmin,
			'config' => $config,
			                                                                 
			                                                                     
			'leadStaleThresholdDays' => (int)($config['lead_stale_threshold_days'] ?? 14),
		];

		return new JSONResponse(data: $response);
	}             

	   
                                                                 
   
                                                      
                                                                                        
                                                                           
                                                
                                                                           
                                                                            
                                                                         
                                                                 
   
                                           
                                                                           
                                                                           
                                                                     
                                                                       
                                                    
   
                                                                           
                                                                          
                                                 
   
                                                                      
                                                                          
                                                                            
                                                                       
                                                   
                                                          
   
                                                       
   
                                               
    
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function update(): JSONResponse {
		$data = $this->request->getParams();

		                                                                      
		                                                                
		                                                                  
		                                                                  
		                                           
		try {
			$config = $this->settingsService->updateSettings($data);
		} catch (UnexpectedValueException $e) {
			return new JSONResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(
			[
				'success' => true,
				'config' => $config,
			]
		);
	}              

	   
                                                            
   
                                                                     
                                                                          
                                                                      
                                                                         
                                                                    
   
                                                                          
                                                                            
                                                          
   
                                                       
   
                                               
    
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function create(): JSONResponse {
		return $this->update();
	}              

	   
                                                            
   
                                              
   
                                               
    
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function reimport(): JSONResponse {
		try {
			$result = $this->settingsService->loadSettings(force: true);

			                                                              
			                                                             
			                                                              
			                                                                
			                                                             
			                                                 
			$rejected = $this->rejectedSchemas(result: $result);
			$message = $this->l10n->t('Configuration re-imported successfully');
			if ($rejected !== []) {
				$message = $this->l10n->t(
					'Configuration re-imported, but OpenRegister rejected %1$s schema(s): %2$s',
					[count($rejected), implode(', ', array_column($rejected, 'slug'))]
				);
			}

			return new JSONResponse(
				[
					'success' => ($rejected === []),
					'message' => $message,
					'config' => $this->settingsService->getSettings(),
					'result' => [
						'registers' => count($result['registers'] ?? []),
						'schemas' => count($result['schemas'] ?? []),
						'rejectedSchemas' => $rejected,
					],
				]
			);
		} catch (\Exception $e) {
			$this->logger->error('SettingsController::reimport failed', ['exception' => $e]);
			return new JSONResponse(
				[
					'success' => false,
					'message' => $this->l10n->t('An unexpected error occurred'),
					'error' => $e->getMessage(),
				],
				500
			);
		}         
	}                

	   
                                                                          
   
                                                                
   
                                                          
   
                                               
    
	private function rejectedSchemas(array $result): array {
		$failed = $result['failed']['schemas'] ?? [];
		if (is_array($failed) === false) {
			return [];
		}

		$rejected = [];
		foreach ($failed as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$rejected[] = [
				'slug' => (string)($entry['slug'] ?? $entry['key'] ?? ''),
				'error' => (string)($entry['error'] ?? ''),
			];
		}

		return $rejected;
	}                       

	   
                                           
   
                                                    
   
                                                                         
    
	#[NoAdminRequired]
	public function getUserSettings(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(
			data: $this->settingsService->getUserSettings(userId: $user->getUID())
		);
	}                       

	   
                                              
   
                                                            
   
                                                                         
    
	#[NoAdminRequired]
	public function updateUserSettings(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$data = $this->request->getParams();

		return new JSONResponse(
			data: $this->settingsService->updateUserSettings(userId: $user->getUID(), data: $data)
		);
	}                          
}           
