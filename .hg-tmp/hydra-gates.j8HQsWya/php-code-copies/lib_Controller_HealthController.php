<?php

   
                             
  
                                                                            
                                                                          
                                                    
                                                                         
                                                                             
                                                                             
                                                   
  
                                                                           
                                                                              
                                                                               
                                                                           
                                                                         
                                                        
                                                                   
                                                                        
                
  
                       
                                    
  
                                                              
                                  
                                                                                    
  
                         
  
                            
  
                                                         
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Container\ContainerInterface;

   
                                                                    
  
                                                                           
                                                                  
                          
  
                              
  
                                                         
   
class HealthController extends Controller {

	   
                                                      
   
                                                                      
                              
   
               
    
	private const MANIFEST_LOADER = 'OCA\\OpenRegister\\AppHost\\Observability\\ManifestLoader';

	   
                                                          
   
                                                                      
                              
   
               
    
	private const HEALTH_EXECUTOR = 'OCA\\OpenRegister\\AppHost\\Observability\\HealthCheckExecutor';

	   
                
   
                                              
                                                                           
                                                                                           
   
                
    
	public function __construct(
		IRequest $request,
		private readonly IConfig $config,
		private readonly ContainerInterface $container,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}                   

	   
                                                                       
   
                                                                            
                                                                          
                                                                    
                                                 
   
                                                                       
                                                                      
                                                                             
   
                                                                        
                                       
   
                                                                                    
   
                                                          
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(): JSONResponse {
		$engine = $this->engineResult();
		if ($engine === null) {
			return new JSONResponse(
				[
					'status' => 'degraded',
					'app' => $this->appName,
					'version' => $this->config->getAppValue(Application::APP_ID, 'installed_version', ''),
					'checks' => ['openregister' => 'unavailable'],
				],
				Http::STATUS_OK
			);
		}

		$response = new JSONResponse(
			[
				'status' => $engine['status'],
				'app' => $this->appName,
				'version' => $engine['version'],
				'checks' => $engine['checks'],
			],
			$engine['httpStatus']
		);

		if ($engine['cors'] === true) {
			$response->addHeader('Access-Control-Allow-Origin', '*');
			$response->addHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
		}

		return $response;
	}             

	   
                                                      
   
                                                                       
   
                                                                                                                   
    
	private function engineResult(): ?array {
		try {
			$manifestLoader = $this->container->get(self::MANIFEST_LOADER);
			$executor = $this->container->get(self::HEALTH_EXECUTOR);

			$appId = $this->appName;
			$manifest = $manifestLoader->load(appId: $appId);
			$result = $executor->execute(manifest: $manifest);

			return [
				'status' => (string)$result->status,
				'version' => (string)$manifestLoader->appVersion(appId: $appId),
				'checks' => (array)$result->checks,
				'httpStatus' => (int)$result->httpStatusCode,
				'cors' => ($manifest->cors === true),
			];
		} catch (\Throwable $e) {
			return null;
		}         

	}                    
}           
