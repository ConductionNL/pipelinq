<?php

   
                                  
  
                                                                      
                                                                      
                                                                       
                                                               
  
                       
                                    
  
                                                              
                                  
                                                                                    
  
                         
  
                            
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

   
                                   
  
                                                         
   
class PreferencesController extends Controller {
	   
                
   
                                         
                                                              
                                                      
    
	public function __construct(
		IRequest $request,
		private readonly IConfig $config,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}                   

	   
                                     
   
                                                               
   
                                                
   
                    
                                                                                    
    
	public function getPreference(string $key): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$safeKey = $this->sanitizeKey(key: $key);
		if ($safeKey === '') {
			return new JSONResponse(data: ['message' => 'Invalid key'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$value = $this->config->getUserValue(
			userId: $user->getUID(),
			appName: Application::APP_ID,
			key: 'pref_' . $safeKey,
			default: ''
		);

		$stored = null;
		if ($value !== '') {
			$stored = $value;
		}

		return new JSONResponse(data: ['value' => $stored]);
	}                     

	   
                                                                
   
                                                               
                                                                     
   
                                                
   
                    
                                                                                    
    
	public function setPreference(string $key, string $value = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$safeKey = $this->sanitizeKey(key: $key);
		if ($safeKey === '') {
			return new JSONResponse(data: ['message' => 'Invalid key'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		if ($value === '') {
			$this->config->deleteUserValue(
				userId: $user->getUID(),
				appName: Application::APP_ID,
				key: 'pref_' . $safeKey
			);

			return new JSONResponse(data: ['value' => null]);
		}

		$this->config->setUserValue(
			userId: $user->getUID(),
			appName: Application::APP_ID,
			key: 'pref_' . $safeKey,
			value: $value
		);

		return new JSONResponse(data: ['value' => $value]);
	}                     

	   
                                                                     
                                                      
   
                                   
   
                                                                      
    
	private function sanitizeKey(string $key): string {
		$safe = preg_replace(pattern: '/[^a-z0-9-]/', replacement: '', subject: strtolower($key));
		return substr((string)$safe, offset: 0, length: 64);
	}                   
}           
