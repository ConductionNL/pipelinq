<?php

   
                                                
  
                                                                           
                                                                      
                                                                          
                                                                          
  
                       
                                    
  
                                                              
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                         
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Demo\DemoRegisterImporter;
use OCA\Pipelinq\Service\DemoSeedService;
use OCA\Pipelinq\Service\SettingsService;
use OCA\Pipelinq\Settings\AdminSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

   
                                                                   
  
                                                                         
   
class SetupController extends Controller {
	   
                                                           
   
            
    
	private const SETUP_VERSION = 1;

	   
                                                                            
                                                                         
                                      
   
               
    
	private const DEMO_DATA_DECIDED_KEY = 'demo_data_decided';

	   
                                                           
   
                                                                          
                                                                            
                                                                         
                                                                      
   
               
    
	private const DATASET_KEY = 'demo_dataset';

	   
                                                                      
                                        
   
                                                                          
                                                                            
                                                                       
                                                                        
                         
   
               
    
	private const DISMISSED_VERSION_KEY = 'setup_dismissed_version';

	   
                
   
                                      
                                         
                                                          
                                                                                        
                                                                                                
                                                                                          
                                          
                                                                                                         
    
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IAppConfig $appConfig,
		private readonly SettingsService $settingsService,
		private readonly DemoSeedService $demoSeedService,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
		private readonly DemoRegisterImporter $registerImporter,
	) {
		parent::__construct(appName: $appName, request: $request);
	}                   

	   
                                                
   
                                                                              
   
                                                                          
                                                                           
                                                                    
                                                                            
                                                             
   
                                                                           
                                                                           
                                                                             
                                                                     
                                                                      
                                                                           
   
                                                                         
                                                                             
                                                                            
                                                                
                                            
   
                                                                             
                                                                          
                                                                           
                                                                         
   
                                                                            
                                                                           
                                                                         
                   
   
                                                                                        
   
                                                                          
                                                                                      
                                                                                      
    
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function status(): DataResponse {
		                                                                     
		$currencyDone = $this->config(key: 'currency') !== '';

		                                                                     
		                                                                         
		                                                                       
		                                                                        
		                                  
		$demoDataDone = ($this->config(key: self::DEMO_DATA_DECIDED_KEY) !== ''
			|| $this->config(key: self::DATASET_KEY) !== '');

		                                                                       
		$organisationDone = $this->config(key: 'receipt_company_name') !== '';

		if ($currencyDone === true) {
			$this->appConfig->setValueString(Application::APP_ID, 'setup_completed_version', (string)self::SETUP_VERSION);
		}

		return new DataResponse(
			[
				'version' => self::SETUP_VERSION,
				'completed' => $currencyDone,
				'dismissed' => $this->dismissedVersion(),
				                                                           
				                                                            
				                                                               
				'datasets' => $this->demoSeedService->listChoices(),
				                                                         
				                                                          
				                                                                
				                                                            
				'steps' => [
					'welcome' => ['done' => true],
					'demo-data' => ['done' => $demoDataDone],
					'currency' => ['done' => $currencyDone],
					'organisation' => ['done' => $organisationDone],
					'done' => ['done' => true],
				],
			]
		);
	}              

	   
                                                                        
   
                                                                             
                                                                        
                                                                       
                                                                        
                                                         
   
                                                
   
                                                                                                       
    
	private function skipDemoData(): DataResponse {
		                                                                  
		                               
		$this->appConfig->setValueString(Application::APP_ID, self::DATASET_KEY, DemoSeedService::NONE_DATASET);
		$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DATA_DECIDED_KEY, 'skipped');

		return new DataResponse(
			['success' => true, 'message' => 'Demo data skipped. You can seed it later with `occ pipelinq:demo:seed`.']
		);
	}                    

	   
                                                                     
   
                                       
   
                                                                          
    
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function saveConfig(): DataResponse {
		                                                                       
		                                                                        
		                                                                     
		                                                                        
		                                         
		$refusal = $this->refuseUnknownDataset(dataset: $this->request->getParam(self::DATASET_KEY));
		if ($refusal !== null) {
			return $refusal;
		}

		foreach ($this->request->getParams() as $key => $value) {
			if ($key === '_route') {
				continue;
			}

			$stored = json_encode($value);
			if (is_scalar($value) === true) {
				$stored = (string)$value;
			}

			$this->appConfig->setValueString(Application::APP_ID, (string)$key, $stored);
		}

		return new DataResponse(['success' => true]);
	}                  

	   
                                                     
   
                                                                            
   
                                                                                     
   
                                                                                      
    
	private function refuseUnknownDataset(mixed $dataset): ?DataResponse {
		if ($dataset === null) {
			return null;
		}

		$named = 'that';
		if (is_scalar($dataset) === true) {
			$named = (string)$dataset;
		}

		$known = array_column($this->demoSeedService->listChoices(), 'id');
		if (in_array($named, $known, true) === true) {
			return null;
		}

		return new DataResponse(['success' => false, 'message' => 'No dataset is called "' . $named . '".']);
	}                            

	   
                                                                                
   
                                          
   
                                                
   
                                                                          
                                                                                      
    
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function runAction(string $actionId): DataResponse {
		if ($actionId === 'provision-register') {
			return $this->provisionRegister();
		}

		                                                                 
		                                                                      
		                                                                        
		if ($actionId === 'load-demo-data') {
			return $this->loadDataset();
		}

		if ($actionId === 'seed-demo-data') {
			return $this->seedDemoData();
		}

		if ($actionId === 'skip-demo-data') {
			return $this->skipDemoData();
		}

		if ($actionId === 'dismiss-setup') {
			return $this->dismissSetup();
		}

		return new DataResponse(
			['success' => false, 'message' => 'Unknown setup action: ' . $actionId],
			Http::STATUS_NOT_FOUND,
		);
	}                 

	   
                                                                     
                                                         
   
                                                                             
                                                                       
                                                                     
                                                                           
                                                                        
   
                                                
   
                                                 
    
	private function provisionRegister(): DataResponse {
		if ($this->appManager->isInstalled('openregister') === false) {
			return new DataResponse(
				[
					'success' => false,
					'message' => 'OpenRegister is not installed — install and enable it, then run this step.',
				],
				Http::STATUS_PRECONDITION_FAILED,
			);
		}

		try {
			$result = $this->settingsService->loadSettings(force: false);
			$registerCount = count($result['registers'] ?? []);
			$schemaCount = count($result['schemas'] ?? []);

			$this->settingsService->createDefaultPipelines();
			$this->settingsService->createDefaultSkills();

			$message = sprintf(
				'Provisioned %d register(s) and %d schema(s); default pipelines and skills are ready.',
				$registerCount,
				$schemaCount,
			);

			return new DataResponse(['success' => true, 'message' => $message]);
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq setup provisioning failed', ['exception' => $e->getMessage()]);
			return new DataResponse(
				['success' => false, 'message' => 'Provisioning failed: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}         
	}                         

	   
                                                                        
                                                                      
   
                                                                         
                                                                         
                                                                          
                                                     
   
                                                
   
                                                                                                       
                                                                                      
    
	private function loadDataset(): DataResponse {
		                                                                 
		                                                                       
		$posted = $this->request->getParam('dataset');
		$refusal = $this->refuseUnknownDataset(dataset: $posted);
		if ($refusal !== null) {
			return $refusal;
		}

		                                                                       
		                                                                 
		$picked = $this->config(key: self::DATASET_KEY);
		if ($posted !== null) {
			$picked = (string)$posted;
		}

		                                                                     
		                                                               
		if ($picked === '') {
			return new DataResponse(['success' => false, 'message' => 'Pick a dataset first.']);
		}

		if ($picked === DemoSeedService::NONE_DATASET) {
			$this->appConfig->setValueString(Application::APP_ID, self::DATASET_KEY, DemoSeedService::NONE_DATASET);
			$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DATA_DECIDED_KEY, 'skipped');

			return new DataResponse(['success' => true, 'message' => 'No example data was seeded.']);
		}

		return $this->seedDemoData();

	}                   

	   
                                     
   
                                                
    
	private function seedDemoData(): DataResponse {
		try {
			$result = $this->demoSeedService->seed();

			if ($result['success'] === false) {
				return new DataResponse(
					['success' => false, 'message' => (string)($result['message'] ?? 'Demo seed failed.')],
					Http::STATUS_PRECONDITION_FAILED,
				);
			}

			                                                                  
			                                                                
			                                                          
			                                                           
			$this->appConfig->setValueString(Application::APP_ID, self::DATASET_KEY, DemoSeedService::DEMO_DATASET);
			$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DATA_DECIDED_KEY, 'seeded');

			                                                                        
			$example = $this->registerImporter->import();

			$created = (array_sum($result['created']) + $example['imported']);
			$skipped = (array_sum($result['skipped']) + $example['skipped']);
			$message = sprintf(
				'Seeded %d demo object(s) (%d already present). Remove them any time with `occ pipelinq:demo:seed --remove`.',
				$created,
				$skipped,
			);

			return new DataResponse(['success' => true, 'message' => $message]);
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq demo seed failed', ['exception' => $e->getMessage()]);
			return new DataResponse(
				['success' => false, 'message' => 'Demo seed failed: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}         
	}                    

	   
                                                                     
   
                                                                           
                                                                            
                                                                           
                            
   
                                                  
   
                                                                                      
    
	private function dismissSetup(): DataResponse {
		$this->appConfig->setValueString(
			Application::APP_ID,
			self::DISMISSED_VERSION_KEY,
			(string)self::SETUP_VERSION
		);

		return new DataResponse(['success' => true, 'dismissed' => self::SETUP_VERSION]);
	}                    

	   
                                                                           
   
                                                   
   
                                                                                      
    
	private function dismissedVersion(): int|false {
		$stored = $this->config(key: self::DISMISSED_VERSION_KEY);
		if ($stored === '' || ctype_digit($stored) === false) {
			return false;
		}

		return (int)$stored;
	}                        

	   
                                            
   
                                      
   
                                               
    
	private function config(string $key): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, '');
	}              
}           
