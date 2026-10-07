<?php

   
                                          
  
                                                                           
                                                                              
                                                                               
                                                                           
                               
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                     
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\EnquiryIntakeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                             
   
class EnquiryController extends Controller {

	   
                
   
                                                             
                                                            
                                                
   
                                                                                          
    
	public function __construct(
		IRequest $request,
		private readonly EnquiryIntakeService $intake,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}                   

	   
                                                                
   
                                                                            
                                                                         
                                                                     
   
                                                                            
                                                                             
                                                                        
                                                                           
                                                                            
                                                                      
                                    
   
                                                                            
                                                                           
                                                                          
   
                                                                  
   
                                                                                                                                                      
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function submit(): JSONResponse {
		try {
			$id = $this->intake->submit(payload: $this->request->getParams());

			return $this->cors(response: new JSONResponse(['id' => $id], Http::STATUS_CREATED));
		} catch (InvalidArgumentException $e) {
			                                                                    
			                                                                 
			                               
			return $this->cors(
				response: new JSONResponse(
					['error' => 'This submission could not be accepted.'],
					Http::STATUS_BAD_REQUEST
				)
			);
		} catch (Throwable $e) {
			                                                                   
			                                                                    
			                                                                 
			                                                      
			$this->logger->error(
				'Pipelinq: website enquiry intake failed.',
				['exception' => $e]
			);

			return $this->cors(
				response: new JSONResponse(
					['error' => 'The enquiry could not be stored right now.'],
					Http::STATUS_SERVICE_UNAVAILABLE
				)
			);
		}         
	}              

	   
                                                                          
   
                                                                       
                                                                            
                                                                             
                                                           
   
                                                                       
                                                  
                                                                            
                                                                       
                            
   
                                                           
   
                                                              
   
                                                                                                                                                      
    
	private function cors(JSONResponse $response): JSONResponse {
		$origin = $this->request->getHeader('Origin');
		if ($origin === '') {
			return $response;
		}

		$response->addHeader('Access-Control-Allow-Origin', $origin);
		$response->addHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
		$response->addHeader('Vary', 'Origin');

		return $response;
	}            
}           
