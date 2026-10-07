<?php

   
                                          
  
                                                                         
                                                                        
                                                                        
                         
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                 
  
                                                                           
                                                                   
                                                                        
  
                                                                          
                                                                 
  
                                                                
   
class PosCustomerSettingsController extends Controller {
	   
                                                 
   
                              
    
	private const DEFAULTS = [
		'customerSearchFields' => 'name,email,phone',
		'customerHistoryDepth' => '10',
		'enablePipelinqSync' => 'true',
		'requireCustomerForOnAccount' => 'true',
	];

	   
                                                                  
   
                           
    
	private const ALLOWED_FIELDS = ['name', 'email', 'phone'];

	   
                                            
   
                        
    
	private const ALLOWED_DEPTHS = [10, 20, 50];

	   
                
   
                                         
                                                
                                              
    
	public function __construct(
		IRequest $request,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                       
   
                                      
   
                                                                 
    
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function index(): JSONResponse {
		return new JSONResponse(['settings' => $this->readAll()]);
	}             

	   
                                                            
   
                            
       
                                                         
                                   
                                   
                                           
       
   
                                                                          
   
                                                                 
    
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function update(): JSONResponse {
		try {
			$this->applyFieldsParam();
			$this->applyDepthParam();
			$this->applyBoolParam(key: 'enablePipelinqSync');
			$this->applyBoolParam(key: 'requireCustomerForOnAccount');
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error('PosCustomerSettingsController::update failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['error' => 'Onverwachte fout bij opslaan van instellingen.'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse(['settings' => $this->readAll()]);
	}              

	   
                                                             
   
                                              
    
	private function readAll(): array {
		$fields = $this->appConfig->getValueString(
			Application::APP_ID,
			'customerSearchFields',
			self::DEFAULTS['customerSearchFields']
		);
		$fieldList = array_values(
			array_intersect(
				array_filter(array_map('trim', explode(',', $fields))),
				self::ALLOWED_FIELDS
			)
		);
		if (count($fieldList) === 0) {
			$fieldList = self::ALLOWED_FIELDS;
		}

		return [
			'customerSearchFields' => $fieldList,
			'customerHistoryDepth' => (int)$this->appConfig->getValueString(
				Application::APP_ID,
				'customerHistoryDepth',
				self::DEFAULTS['customerHistoryDepth']
			),
			'enablePipelinqSync' => $this->readBool(key: 'enablePipelinqSync'),
			'requireCustomerForOnAccount' => $this->readBool(key: 'requireCustomerForOnAccount'),
		];
	}               

	   
                                                               
   
                                       
   
                                   
    
	private function readBool(string $key): bool {
		$value = $this->appConfig->getValueString(Application::APP_ID, $key, self::DEFAULTS[$key]);
		return strtolower($value) !== 'false';
	}                

	   
                                                               
   
                                                                   
                                                       
   
                
   
                                                      
    
	private function applyFieldsParam(): void {
		$raw = $this->request->getParam('customerSearchFields', null);
		if ($raw === null) {
			return;
		}

		$fields = array_filter(array_map('trim', explode(',', (string)$raw)));
		if (is_array($raw) === true) {
			$fields = array_values(array_filter(array_map('strval', $raw)));
		}

		foreach ($fields as $field) {
			if (in_array($field, self::ALLOWED_FIELDS, true) === false) {
				throw new InvalidArgumentException(
					"Onbekend zoekveld: '" . $field . "'. Toegestaan: name, email, phone."
				);
			}
		}

		if (count($fields) === 0) {
			throw new InvalidArgumentException('Ten minste één zoekveld is verplicht.');
		}

		$this->appConfig->setValueString(Application::APP_ID, 'customerSearchFields', implode(',', $fields));
	}                        

	   
                                                 
   
                
   
                                                      
    
	private function applyDepthParam(): void {
		$raw = $this->request->getParam('customerHistoryDepth', null);
		if ($raw === null) {
			return;
		}

		$depth = (int)$raw;
		if (in_array($depth, self::ALLOWED_DEPTHS, true) === false) {
			throw new InvalidArgumentException('Geschiedenisdiepte moet 10, 20 of 50 zijn.');
		}

		$this->appConfig->setValueString(Application::APP_ID, 'customerHistoryDepth', (string)$depth);
	}                       

	   
                                  
   
                                       
   
                
    
	private function applyBoolParam(string $key): void {
		$raw = $this->request->getParam($key, null);
		if ($raw === null) {
			return;
		}

		$truthy = ((int)$raw) === 1;
		if (is_bool($raw) === true) {
			$truthy = $raw;
		}

		if (is_string($raw) === true) {
			$truthy = in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
		}

		$value = 'false';
		if ($truthy === true) {
			$value = 'true';
		}

		$this->appConfig->setValueString(Application::APP_ID, $key, $value);
	}                      
}           
