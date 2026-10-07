<?php

   
                                       
  
                                                              
  
                       
                                    
  
                                                              
                                  
                                                                                    
  
                         
  
                            
  
                                                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\IcpConfigService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use RuntimeException;

   
                                                     
  
                                                                                                   
   
class ProspectSettingsController extends Controller {

	   
                                    
   
                                                                                                
    
	private ?\OCA\OpenRegister\Contract\ObjectServiceInterface $objectService = null;

	   
                
   
                                         
                                                       
                                                   
                                                              
    
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private IcpConfigService $icpConfig,
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

	   
                                  
   
                                                             
   
                                          
   
                                                                                                    
    
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function index(): JSONResponse {
		return new JSONResponse(data: $this->icpConfig->getSettings());
	}             

	   
                           
   
                                                             
   
                                         
   
                                                                                                    
    
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function update(): JSONResponse {
		$data = $this->request->getParams();

		try {
			$icpHash = $this->icpConfig->saveSettings(data: $data);

			return new JSONResponse(
				data: [
					'status' => 'saved',
					'icpHash' => $icpHash,
				]
			);
		} catch (\Exception $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: 500
			);
		}         
	}              
}           
