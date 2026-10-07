<?php

   
                                      
  
                                                                 
                                                                     
                                                                       
                                                                      
                           
  
                                  
                                                                       
                                                                 
                                                                   
                                                                                 
  
                                                                           
                                                                              
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Listener\NrcNotificationListener;
use OCA\Pipelinq\Service\Zgw\ZgwApiClient;
use OCA\Pipelinq\Service\Zgw\ZgwRegisterAccess;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

   
                                  
  
                                                         
   
class ZgwNotificationController extends Controller {
	   
                
   
                                     
                                                                               
                                                                      
                                                                   
                                                
    
	public function __construct(
		IRequest $request,
		private ZgwRegisterAccess $registers,
		private ZgwApiClient $api,
		private NrcNotificationListener $listener,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                           
   
                                                                           
                                                                           
                                   
   
                                                                            
                                                          
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function inbox(): JSONResponse {
		                                                                      
		                                                                    
		                                                             
		                                                                  
		                                                                   
		                                              
		$authHeader = (string)$this->request->getHeader('Authorization');
		$token = self::extractBearer(headerValue: $authHeader);
		if ($token === '') {
			return new JSONResponse(
				['error' => 'Missing bearer token'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$abonnement = $this->resolveAbonnementByBearer(token: $token);
		if ($abonnement === null) {
			$this->logger->warning(
				'ZGW NRC: inbox received with unknown bearer',
				['ip' => $this->request->getRemoteAddress()]
			);
			return new JSONResponse(
				['error' => 'Unknown bearer token'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$payload = json_decode($this->readRawBody(), true);
		if (is_array($payload) === false) {
			return new JSONResponse(
				['error' => 'Invalid JSON payload'],
				Http::STATUS_BAD_REQUEST
			);
		}

		                                                               
		                                                                     
		$this->listener->dispatch($abonnement, $payload);

		return new JSONResponse(
			['status' => 'accepted'],
			Http::STATUS_ACCEPTED
		);
	}             

	   
                                                                  
                                                                       
   
                                                                       
                                   
   
                                              
   
                                                                   
    
	private function resolveAbonnementByBearer(string $token): ?array {
		$rows = $this->registers->findAll(ZgwRegisterAccess::SCHEMA_ABONN, ['active' => true]);
		foreach ($rows as $row) {
			if ((bool)($row['active'] ?? false) === false) {
				continue;
			}

			$stored = (string)($row['callbackAuth'] ?? '');
			if ($stored === '') {
				continue;
			}

			$resolved = $this->api->resolveClientSecret($stored);
			if ($resolved === '') {
				$resolved = $stored;
			}

			if (hash_equals($resolved, $token) === true) {
				return $row;
			}
		}

		return null;
	}                                 

	   
                                                                  
   
                                                          
   
                                              
    
	private static function extractBearer(string $headerValue): string {
		if ($headerValue === '') {
			return '';
		}

		if (stripos($headerValue, 'Bearer ') === 0) {
			return trim(substr($headerValue, 7));
		}

		return '';
	}                     

	   
                              
   
                                                                     
                           
   
                                                  
    
	protected function readRawBody(): string {
		$body = file_get_contents('php://input');
		if ($body !== false) {
			return $body;
		}

		return '';
	}                   
}           
