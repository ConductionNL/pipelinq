<?php

   
                             
  
                                                                   
  
                    
                                 
  
                                                              
                                  
                                                                                    
  
                         
  
                            
  
                                                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

   
                                                           
  
                                                                                                   
   
class IcpConfigService {
	   
                                         
   
                      
    
	private const ICP_KEYS = [
		'icp_sbi_codes',
		'icp_employee_count_min',
		'icp_employee_count_max',
		'icp_provinces',
		'icp_cities',
		'icp_legal_forms',
		'icp_exclude_inactive',
		'icp_keywords',
		'icp_kvk_api_key',
		'icp_opencorporates_enabled',
	];

	   
                                                   
   
                              
    
	private const JSON_ARRAY_FIELDS = [
		'sbiCodes' => 'icp_sbi_codes',
		'provinces' => 'icp_provinces',
		'cities' => 'icp_cities',
		'legalForms' => 'icp_legal_forms',
		'keywords' => 'icp_keywords',
	];

	   
                
   
                                                     
    
	public function __construct(
		private IcpConfigReader $reader,
	) {
	}                   

	   
                         
   
                                        
                                                                           
    
	public function getSettings(): array {
		$kvkApiKey = $this->reader->getString(key: 'icp_kvk_api_key');

		return [
			'sbiCodes' => $this->reader->getJsonArray(key: 'icp_sbi_codes'),
			'employeeCountMin' => $this->reader->getInt(key: 'icp_employee_count_min'),
			'employeeCountMax' => $this->reader->getInt(key: 'icp_employee_count_max'),
			'provinces' => $this->reader->getJsonArray(key: 'icp_provinces'),
			'cities' => $this->reader->getJsonArray(key: 'icp_cities'),
			'legalForms' => $this->reader->getJsonArray(key: 'icp_legal_forms'),
			'excludeInactive' => $this->reader->isBoolTrue(key: 'icp_exclude_inactive'),
			'keywords' => $this->reader->getJsonArray(key: 'icp_keywords'),
			'kvkApiKey' => $this->maskApiKey(apiKey: $kvkApiKey),
			'openCorporatesEnabled' => $this->reader->isBoolTrue(
				key: 'icp_opencorporates_enabled',
				default: 'false'
			),
		];
	}                   

	   
                      
   
                                            
   
                                
                                                                            
    
	public function saveSettings(array $data): string {
		$this->saveJsonArrayFields(data: $data);
		$this->saveIntegerFields(data: $data);
		$this->saveBooleanFields(data: $data);
		$this->saveApiKeyField(data: $data);

		return $this->getIcpHash();
	}                    

	   
                               
   
                                                           
                                                                            
    
	public function isConfigured(): bool {
		$sbiCodes = $this->reader->getJsonArray(key: 'icp_sbi_codes');

		return count($sbiCodes) > 0;
	}                    

	   
                            
   
                               
                                                                                                    
    
	public function getKvkApiKey(): string {
		return $this->reader->getString(key: 'icp_kvk_api_key');
	}                    

	   
                                 
   
                                       
                                                                           
    
	public function getCriteria(): array {
		return [
			'sbiCodes' => $this->reader->getJsonArray(key: 'icp_sbi_codes'),
			'employeeCountMin' => $this->reader->getInt(key: 'icp_employee_count_min'),
			'employeeCountMax' => $this->reader->getInt(key: 'icp_employee_count_max'),
			'provinces' => $this->reader->getJsonArray(key: 'icp_provinces'),
			'legalForms' => $this->reader->getJsonArray(key: 'icp_legal_forms'),
			'excludeInactive' => $this->reader->isBoolTrue(key: 'icp_exclude_inactive'),
		];
	}                   

	   
                                            
   
                                                    
                                                                           
    
	public function getIcpHash(): string {
		$values = [];
		foreach (self::ICP_KEYS as $key) {
			$values[$key] = $this->reader->getString(key: $key);
		}

		return substr(md5(string: json_encode(value: $values)), offset: 0, length: 8);
	}                  

	   
                                         
   
                                    
   
                
    
	private function saveJsonArrayFields(array $data): void {
		foreach (self::JSON_ARRAY_FIELDS as $dataKey => $configKey) {
			if (isset($data[$dataKey]) === true) {
				$this->reader->setJsonArray(key: $configKey, value: $data[$dataKey]);
			}
		}
	}                           

	   
                                      
   
                                    
   
                
    
	private function saveIntegerFields(array $data): void {
		if (isset($data['employeeCountMin']) === true) {
			$this->reader->setInt(key: 'icp_employee_count_min', value: $data['employeeCountMin']);
		}

		if (isset($data['employeeCountMax']) === true) {
			$this->reader->setInt(key: 'icp_employee_count_max', value: $data['employeeCountMax']);
		}
	}                         

	   
                                      
   
                                    
   
                
    
	private function saveBooleanFields(array $data): void {
		if (isset($data['excludeInactive']) === true) {
			$this->reader->setBool(key: 'icp_exclude_inactive', value: $data['excludeInactive']);
		}

		if (isset($data['openCorporatesEnabled']) === true) {
			$this->reader->setBool(key: 'icp_opencorporates_enabled', value: $data['openCorporatesEnabled']);
		}
	}                         

	   
                                                      
   
                                    
   
                
    
	private function saveApiKeyField(array $data): void {
		if (isset($data['kvkApiKey']) === true && $data['kvkApiKey'] !== '***configured***') {
			                                                            
			                                                                      
			$this->reader->setSensitiveString(key: 'icp_kvk_api_key', value: (string)$data['kvkApiKey']);
		}
	}                       

	   
                                
   
                                          
   
                                                  
    
	private function maskApiKey(string $apiKey): string {
		if ($apiKey !== '') {
			return '***configured***';
		}

		return '';
	}                  
}           
