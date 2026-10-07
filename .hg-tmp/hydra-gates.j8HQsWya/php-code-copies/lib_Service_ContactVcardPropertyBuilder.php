<?php

   
                                        
  
                                                                   
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                                   
  
                                                                             
   
class ContactVcardPropertyBuilder {
	   
                
   
                                                
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                     
   
                                          
                                                                  
   
                                       
   
                                              
    
	public function buildProperties(array $objData, string $objectType): array {
		$name = $objData['name'] ?? 'Unknown';
		$properties = ['FN' => $name];

		$properties = $this->addChannelProperties(properties: $properties, objData: $objData);

		if ($objectType === 'client') {
			$properties = $this->addClientProperties(
				properties: $properties,
				objData: $objData,
				name: $name
			);
		}

		if ($objectType === 'contact') {
			$properties = $this->addContactProperties(
				properties: $properties,
				objData: $objData
			);
		}

		return $properties;
	}                       

	   
                                                                            
                                                                        
                                                                         
                                                                        
                                                                     
   
                              
    
	private const KIND_TO_VCARD_TYPE = [
		'work' => 'WORK',
		'private' => 'HOME',
		'mobile' => 'CELL',
		'whatsapp' => 'CELL',
	];

	   
                                                                       
                                                                        
                                                                        
                                                                    
                                                                        
                                                  
   
                                                               
                                          
   
                                         
   
                                                                                                                                                   
    
	private function addChannelProperties(array $properties, array $objData): array {
		$emailEntries = $this->buildTypedVcardEntries(entries: ($objData['emails'] ?? []));
		if ($emailEntries !== []) {
			$properties['EMAIL'] = $emailEntries;
		} elseif (empty($objData['email']) === false) {
			$properties['EMAIL'] = $objData['email'];
		}

		$phoneEntries = $this->buildTypedVcardEntries(entries: ($objData['phones'] ?? []));
		if ($phoneEntries !== []) {
			$properties['TEL'] = $phoneEntries;
		} elseif (empty($objData['phone']) === false) {
			$properties['TEL'] = $objData['phone'];
		}

		$socialEntries = $this->buildSocialProfileEntries(profiles: ($objData['socialProfiles'] ?? []));
		if ($socialEntries !== []) {
			$properties['X-SOCIALPROFILE'] = $socialEntries;
		}

		return $properties;
	}                            

	   
                                                                        
                                                                     
                                                                     
                                                                        
                                            
   
                                                          
   
                                                                            
    
	private function buildTypedVcardEntries(mixed $entries): array {
		if (is_array($entries) === false) {
			return [];
		}

		$out = [];
		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$value = (string)($entry['value'] ?? '');
			if ($value === '') {
				continue;
			}

			$vcardEntry = ['value' => $value];
			$vcardType = self::KIND_TO_VCARD_TYPE[(string)($entry['kind'] ?? '')] ?? null;
			if ($vcardType !== null) {
				$vcardEntry['type'] = $vcardType;
			}

			$out[] = $vcardEntry;
		}

		return $out;
	}                              

	   
                                                                
                                                                     
                                                                      
                                                                    
                                                                       
                                              
   
                                                        
   
                                                                            
    
	private function buildSocialProfileEntries(mixed $profiles): array {
		if (is_array($profiles) === false) {
			return [];
		}

		$out = [];
		foreach ($profiles as $profile) {
			if (is_array($profile) === false) {
				continue;
			}

			$value = (string)($profile['url'] ?? '');
			if ($value === '') {
				$value = (string)($profile['handle'] ?? '');
			}

			if ($value === '') {
				continue;
			}

			$entry = ['value' => $value];
			$network = (string)($profile['network'] ?? '');
			if ($network !== '') {
				$entry['type'] = $network;
			}

			$out[] = $entry;
		}

		return $out;
	}                                 

	   
                                         
   
                                                     
                                          
                                        
   
                                         
    
	private function addClientProperties(array $properties, array $objData, string $name): array {
		if (($objData['type'] ?? '') === 'organization') {
			$properties['ORG'] = $name;
		}

		if (empty($objData['website']) === false) {
			$properties['URL'] = $objData['website'];
		}

		if (empty($objData['address']) === false) {
			$properties['ADR'] = $objData['address'];
		}

		if (empty($objData['notes']) === false) {
			$properties['NOTE'] = $objData['notes'];
		}

		return $properties;
	}                           

	   
                                          
   
                                                     
                                          
   
                                         
    
	private function addContactProperties(array $properties, array $objData): array {
		if (empty($objData['role']) === false) {
			$properties['ROLE'] = $objData['role'];
		}

		if (empty($objData['client']) === false) {
			$orgName = $this->resolveClientName(clientId: $objData['client']);
			if ($orgName !== null) {
				$properties['ORG'] = $orgName;
			}
		}

		return $properties;
	}                            

	   
                                      
   
                                                     
   
                                            
    
	private function resolveClientName(string $clientId): ?string {
		$objectService = $this->getObjectService();
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');

		if ($registerId === '' || $schemaId === '') {
			return null;
		}

		try {
			$client = $objectService->find(
				$clientId,
				[],
				false,
				$registerId,
				$schemaId
			);
			$data = $this->serializeResult(result: $client);
			return $data['name'] ?? null;
		} catch (\Exception $e) {
			return null;
		}
	}                         

	   
                                                    
   
                                                 
   
                                        
    
	private function serializeResult(mixed $result): array {
		if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
			return $result->jsonSerialize();
		}

		if (is_array($result) === true) {
			return $result;
		}

		return [];
	}                       

	   
                                                         
   
                                      
    
	private function getObjectService(): object {
		return $this->objectService;
	}                        
}           
