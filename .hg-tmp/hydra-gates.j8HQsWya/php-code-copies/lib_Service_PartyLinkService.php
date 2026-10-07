<?php

   
                             
  
                                                                             
                                                                           
                        
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                                                                                                                         
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                        
  
                                                                                                                                                                                        
   
class PartyLinkService {
	   
                
   
                                                                        
                                                                               
                                                                          
                                              
    
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly PartyKindRegistryService $registry,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                          
   
                                                                  
                                       
                                   
                                            
   
                                                                             
   
                                                                                                                                                                                          
    
	public function link(string $recordType, string $recordId, string $party, string $kind): array {
		$recordType = trim($recordType);
		$recordId = trim($recordId);
		$party = trim($party);
		$kind = trim($kind);

		if ($recordType === '' || $recordId === '' || $party === '') {
			return ['status' => 400, 'error' => 'A record type, a record and a party are required.'];
		}

		$answer = $this->registry->mayLink(
			recordType: $recordType,
			recordId: $recordId,
			kind: $kind,
			party: $party,
		);

		if ($answer['allowed'] === false) {
			                                                                
			                                                       
			return ['status' => 409, 'error' => $answer['reason']];
		}

		$link = [
			'party' => $party,
			'kind' => $kind,
			'recordType' => $recordType,
			'recordId' => $recordId,
			'validFrom' => (new DateTimeImmutable('today'))->format('Y-m-d'),
		];

		$saved = $this->write(object: $link);
		if ($saved === false) {
			return ['status' => 500, 'error' => 'The party link could not be saved.'];
		}

		return ['status' => 201, 'partyLink' => $link];
	}            

	   
                                                        
   
                                                                     
                                                                       
   
                                          
   
                                                                             
    
	public function end(string $linkId): array {
		$linkId = trim($linkId);
		if ($linkId === '') {
			return ['status' => 400, 'error' => 'A party link is required.'];
		}

		try {
			$entity = $this->objectService->find(id: $linkId);
		} catch (Throwable $e) {
			$this->logger->debug(
				'PartyLinkService: the link could not be read',
				['uuid' => $linkId, 'exception' => $e->getMessage()]
			);
			$entity = null;
		}

		if ($entity === null) {
			return ['status' => 404, 'error' => 'That party link could not be read.'];
		}

		$data = $entity->jsonSerialize();
		if (is_array($data) === false) {
			return ['status' => 404, 'error' => 'That party link could not be read.'];
		}

		$data['validUntil'] = (new DateTimeImmutable('today'))->format('Y-m-d');

		if ($this->write(object: $data, uuid: $linkId) === false) {
			return ['status' => 500, 'error' => 'The party link could not be ended.'];
		}

		return ['status' => 200, 'partyLink' => $data];
	}           

	   
                                                                            
   
                                                                  
                                       
                                                                                
   
                                                                                     
   
                                                                                                                                                                                          
    
	public function import(string $recordType, string $recordId, array $rows): array {
		$verdict = $this->registry->judgeBatch(
			recordType: trim($recordType),
			recordId: trim($recordId),
			rows: $rows,
		);

		$landed = [];
		foreach ($verdict['accepted'] as $row) {
			$link = [
				'party' => $row['party'],
				'kind' => $row['kind'],
				'recordType' => trim($recordType),
				'recordId' => trim($recordId),
				'validFrom' => (new DateTimeImmutable('today'))->format('Y-m-d'),
			];

			if ($this->write(object: $link) === true) {
				$landed[] = $link;
			}
		}

		return [
			'status' => 200,
			'linked' => $landed,
			'refused' => $verdict['refused'],
		];
	}              

	   
                        
   
                                                 
                                                            
   
                                            
    
	private function write(array $object, ?string $uuid = null): bool {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'partyLink_schema', '');
		if ($register === '' || $schema === '') {
			$this->logger->warning('PartyLinkService: the party link surface is not configured');

			return false;
		}

		try {
			$this->objectService->saveObject(
				object: $object,
				register: $register,
				schema: $schema,
				uuid: $uuid,
			);

			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				'PartyLinkService: the party link could not be saved',
				['exception' => $e->getMessage()]
			);

			return false;
		}
	}             
}           
