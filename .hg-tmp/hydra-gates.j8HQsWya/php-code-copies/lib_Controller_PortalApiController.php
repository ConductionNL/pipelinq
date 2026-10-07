<?php

   
                                
  
                                                                             
                                                                     
                                                                             
                                                                                
                                                                          
                                                                                
                               
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

   
                                          
  
                                                                                        
                                                                           
                                                                           
                              
   
abstract class PortalApiController extends Controller {
	   
                
   
                                         
                                                                  
                                              
    
	public function __construct(
		IRequest $request,
		protected PortalRequestGuard $guard,
		protected LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                   
   
                                                                                                                    
   
                                                   
    
	protected function context(): array {
		return $this->guard->authenticate(request: $this->request);
	}               

	   
                                                                       
                                                                           
                                                                         
                                                                         
                         
   
                                                                                                                    
   
                                                   
    
	protected function requireSession(): array {
		return $this->context();
	}                      

	   
                                                                       
                                                                          
                                                                         
                                                                          
   
                                 
    
	protected function requireTenant(): string {
		return $this->guard->resolveTenant(request: $this->request);
	}                     

	   
                                                                             
                                                                        
   
                                                                      
   
                                      
    
	protected function guarded(callable $handler): JSONResponse {
		try {
			[$body, $status] = $handler();
			return new JSONResponse($body, $status);
		} catch (PortalException $e) {
			return new JSONResponse($e->toBody(), $e->getStatus());
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq portal: unhandled error', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['errorCode' => 'serverError', 'message' => 'Er is een fout opgetreden.'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}               

	   
                                                       
   
                                       
                                       
   
                             
    
	protected function strParam(string $name, string $default = ''): string {
		$value = $this->request->getParam($name, $default);
		if (is_string($value) === true) {
			return trim($value);
		}

		return $default;
	}                

	   
                                            
   
                                       
                                    
   
                          
    
	protected function intParam(string $name, int $default = 0): int {
		$value = $this->request->getParam($name, $default);
		if (is_numeric($value) === true) {
			return (int)$value;
		}

		return $default;
	}                
}           
