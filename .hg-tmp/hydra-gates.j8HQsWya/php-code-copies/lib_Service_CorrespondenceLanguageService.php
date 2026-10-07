<?php

   
                                          
  
                                                                              
  
                                                                         
                                                                              
                                                                           
                                                                              
                         
  
                                                                        
                                                                               
                                                                           
                    
  
                                                                         
                                                                              
                                                              
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                                                                                           
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                
  
                                                                                                                                                                
   
class CorrespondenceLanguageService {
	   
                                                             
   
               
    
	public const RULE_PARTY = 'party';

	   
                                                           
   
               
    
	public const RULE_INSTANCE_DEFAULT = 'instanceDefault';

	   
                                                                            
   
               
    
	public const RULE_FALLBACK = 'fallback';

	   
                                                                         
                                                             
   
               
    
	public const FALLBACK = 'en';

	   
                
   
                                                                                     
                                                                                    
                                                                            
                                                                               
                                              
    
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IConfig $config,
		private readonly IFactory $l10nFactory,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                                        
   
                                                                           
                
   
                                                                                                                                                                        
    
	public function available(): array {
		try {
			$languages = $this->l10nFactory->findAvailableLanguages(Application::APP_ID);
		} catch (Throwable $e) {
			$this->logger->warning(
				'CorrespondenceLanguageService: could not read the available languages',
				['exception' => $e->getMessage()]
			);
			$languages = [];
		}

		$tags = [];
		foreach ($languages as $language) {
			$tag = trim((string)$language);
			                                                          
			                                                             
			if (preg_match('/^[a-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*$/', $tag) !== 1) {
				continue;
			}

			if (in_array($tag, $tags, true) === false) {
				$tags[] = $tag;
			}
		}

		                                                                     
		                                                                    
		                                              
		if (in_array(self::FALLBACK, $tags, true) === false) {
			$tags[] = self::FALLBACK;
		}

		sort($tags);

		return $tags;
	}                 

	   
                                                                  
   
                                      
   
                                                           
   
                                                                                                                                                                        
    
	public function validate(string $tag): array {
		$tag = trim($tag);
		if ($tag === '') {
			                                                                 
			                                                            
			return ['valid' => true, 'reason' => ''];
		}

		$available = $this->available();
		if (in_array($tag, $available, true) === true) {
			return ['valid' => true, 'reason' => ''];
		}

		return [
			'valid' => false,
			'reason' => "This instance cannot render {$tag}. Available: " . implode(', ', $available) . '.',
		];
	}                

	   
                                                          
   
                           
    
	public function instanceDefault(): string {
		return trim((string)$this->config->getSystemValue('default_language', ''));
	}                       

	   
                                                                       
   
                                                                       
                                                                      
                                                                         
   
                                                               
   
                                                                          
                 
   
                                                                                                                                                            
    
	public function resolveFor(array $party): array {
		$preference = trim((string)($party['correspondenceLanguage'] ?? ''));

		if ($preference !== '') {
			return [
				'language' => $preference,
				'rule' => self::RULE_PARTY,
				'partyPreference' => $preference,
			];
		}

		$default = $this->instanceDefault();
		if ($default !== '') {
			return [
				'language' => $default,
				'rule' => self::RULE_INSTANCE_DEFAULT,
				'partyPreference' => '',
			];
		}

		return [
			'language' => self::FALLBACK,
			'rule' => self::RULE_FALLBACK,
			'partyPreference' => '',
		];
	}                  

	   
                                            
   
                                                   
   
                                                                      
   
                                                                                                                                                                              
    
	public function resolve(string $partyId): array {
		$party = $this->readParty(partyId: $partyId);
		if ($party === null) {
			return ['status' => 403, 'error' => 'You may not read this party.'];
		}

		return array_merge(['status' => 200], $this->resolveFor(party: $party));
	}               

	   
                                      
   
                                                   
                                                         
   
                                                                      
   
                                                                                                                                                                        
    
	public function setPreference(string $partyId, string $tag): array {
		$party = $this->readParty(partyId: $partyId);
		if ($party === null) {
			return ['status' => 403, 'error' => 'You may not read this party.'];
		}

		$verdict = $this->validate(tag: $tag);
		if ($verdict['valid'] === false) {
			return ['status' => 422, 'error' => $verdict['reason']];
		}

		$party['correspondenceLanguage'] = trim($tag);

		try {
			$this->objectService->saveObject(
				object: $party,
				register: $this->appConfig->getValueString(Application::APP_ID, 'register', ''),
				schema: $this->appConfig->getValueString(Application::APP_ID, 'client_schema', ''),
				uuid: (string)($party['id'] ?? $party['uuid'] ?? $partyId),
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'CorrespondenceLanguageService: the preference could not be saved',
				['party' => $partyId, 'exception' => $e->getMessage()]
			);

			return ['status' => 500, 'error' => 'The correspondence language could not be saved.'];
		}

		return array_merge(['status' => 200], $this->resolveFor(party: $party));
	}                     

	   
                                                                
   
                                                                        
                                                                    
                                                                          
                                                                         
   
                                                             
                                                         
   
                                                                                   
                               
   
                                                                                                                                                    
    
	public function mergeAnswer(array $winner, array $loser): array {
		$a = trim((string)($winner['correspondenceLanguage'] ?? ''));
		$b = trim((string)($loser['correspondenceLanguage'] ?? ''));

		if ($a !== '' && $b !== '' && $a !== $b) {
			return ['needsChoice' => true, 'language' => '', 'options' => [$a, $b]];
		}

		$survivor = $b;
		if ($a !== '') {
			$survivor = $a;
		}

		return ['needsChoice' => false, 'language' => $survivor, 'options' => []];
	}                   

	   
                                 
   
                                                   
   
                                                         
    
	private function readParty(string $partyId): ?array {
		$partyId = trim($partyId);
		if ($partyId === '') {
			return null;
		}

		try {
			$entity = $this->objectService->find(id: $partyId);
		} catch (Throwable $e) {
			$this->logger->debug(
				'CorrespondenceLanguageService: the party could not be read',
				['party' => $partyId, 'exception' => $e->getMessage()]
			);

			return null;
		}

		if ($entity === null) {
			return null;
		}

		$data = $entity->jsonSerialize();

		if (is_array($data) === false) {
			return null;
		}

		return $data;
	}                 
}           
