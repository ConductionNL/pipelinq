<?php

   
                              
  
                                                                            
                                                                               
                                                                    
                                                                         
                                              
  
                                                                 
                                                                         
                                                                            
                                                                              
                                                    
  
                                                                           
                                                                              
                                                                               
                                                                           
                                                                         
                                                        
                                                                   
                                                                        
                
  
                       
                                    
  
                                                              
                                  
                                                                                    
  
                         
  
                            
  
                                                         
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;

   
                                                                                   
  
                                                                               
                                                                              
                                                              
  
                              
  
                                                         
   
class MetricsController extends Controller {

	   
                                                      
   
                                                                      
                              
   
               
    
	private const MANIFEST_LOADER = 'OCA\\OpenRegister\\AppHost\\Observability\\ManifestLoader';

	   
                                                  
   
                                                                      
                              
   
               
    
	private const METRICS_ENGINE = 'OCA\\OpenRegister\\AppHost\\Observability\\MetricsEngine';

	   
                                                                            
   
               
    
	private const CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

	   
                
   
                                              
                                                                                           
   
                
    
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}                   

	   
                                                                            
   
                                                                 
   
                                                                           
                                                                   
   
                                                               
   
                                                          
    
	#[NoCSRFRequired]
	public function index(): TextPlainResponse {
		try {
			$manifestLoader = $this->container->get(self::MANIFEST_LOADER);
			$engine = $this->container->get(self::METRICS_ENGINE);

			$manifest = $manifestLoader->load(appId: $this->appName);
			$body = (string)$engine->render(manifest: $manifest);
			$status = Http::STATUS_OK;
		} catch (\Throwable $e) {
			$body = '# metrics unavailable: the OpenRegister AppHost observability engine is not installed' . "\n";
			$status = Http::STATUS_SERVICE_UNAVAILABLE;
		}         

		$response = new TextPlainResponse($body, $status);
		$response->addHeader('Content-Type', self::CONTENT_TYPE);

		return $response;
	}             
}           
