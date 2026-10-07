<?php

   
                                  
  
                                                                             
                                                                            
                                     
  
                                                                           
                                                                                
                                                                             
                                                                          
                                                                                
                                                                               
                                                                             
                                                                     
                                                                             
  
                                                                      
                                                                                
                                     
                                                  
                                                                             
                                                                                
                              
                                                                               
                                                                               
                                                                             
            
  
                                                                               
                                                                              
                                                                               
            
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                            
  
                                                                  
  
                                                                    
                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

   
                                                          
  
                                                                  
   
class NaviConversationStore {
	   
                                                                
   
               
    
	public const PREFERENCE_KEY = 'navi_conversation';

	   
                                                                          
                                                               
   
            
    
	public const TTL = 3600;

	   
                                                                      
                                                                
   
            
    
	public const MAX_TURNS = 10;

	   
                                                                         
                                                                          
   
            
    
	public const MAX_QUERY_LENGTH = 240;

	   
                
   
                                                                      
                                                                   
                                          
    
	public function __construct(
		private readonly IConfig $config,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                                                
   
                                                                         
                                                                         
                                                          
   
                                                
                                                                        
   
                                                                          
   
                                                                   
    
	public function read(string $userId, ?string $conversationId): array {
		if ($conversationId === null || $conversationId === '') {
			return [];
		}

		$raw = $this->config->getUserValue(
			userId: $userId,
			appName: Application::APP_ID,
			key: self::PREFERENCE_KEY,
			default: ''
		);
		if ($raw === '') {
			return [];
		}

		$record = json_decode($raw, true);
		if (is_array($record) === false) {
			return [];
		}

		if ($this->isUsable(record: $record, userId: $userId, conversationId: $conversationId) === false) {
			return [];
		}

		return $this->turnsOf(record: $record);
	}            

	   
                                                             
   
                                                                        
                                                          
   
                                                
                                                                                         
                                                                            
                                                         
   
                
   
                                                                   
    
	public function append(string $userId, ?string $conversationId, array $history, array $turn): void {
		if ($conversationId === null || $conversationId === '') {
			return;
		}

		$history[] = $turn;
		if (count($history) > self::MAX_TURNS) {
			$history = array_slice($history, -self::MAX_TURNS);
		}

		$compacted = [];
		foreach ($history as $entry) {
			$compacted[] = $this->compactTurn(turn: $entry);
		}

		$record = [
			'owner' => $userId,
			'conversationId' => $conversationId,
			'updatedAt' => $this->timeFactory->getTime(),
			'turns' => $compacted,
		];

		$this->config->setUserValue(
			userId: $userId,
			appName: Application::APP_ID,
			key: self::PREFERENCE_KEY,
			value: (string)json_encode($record)
		);
	}              

	   
                                                                 
   
                                                                            
                                                                             
                                                                             
                                                                           
                                                                   
   
                                                                       
                                                                          
                                                   
   
                                                                                
                                                                                  
   
                                                                             
                                                                                                
   
                                                                   
    
	public function carryForward(string $detectedIntent, array $history): array {
		$carried = ['intent' => $detectedIntent, 'query' => ''];
		if ($history === []) {
			return $carried;
		}

		$turn = $history[(count($history) - 1)];
		$carried['query'] = (string)($turn['query'] ?? '');

		$previousIntent = (string)($turn['intent'] ?? 'unknown');
		$usable = in_array($previousIntent, NaviService::INTENTS, true);
		if ($detectedIntent === 'unknown' && $usable === true) {
			$carried['intent'] = $previousIntent;
		}

		return $carried;
	}                    

	   
                                                                       
   
                                                       
                                                
                                                                 
   
                
    
	private function isUsable(array $record, string $userId, string $conversationId): bool {
		if (($record['owner'] ?? null) !== $userId) {
			$this->logger->warning(
				message: '[NaviConversationStore] record owner mismatch, ignored',
				context: ['userId' => $userId]
			);
			return false;
		}

		if (($record['conversationId'] ?? null) !== $conversationId) {
			return false;
		}

		$updatedAt = (int)($record['updatedAt'] ?? 0);
		return ($this->timeFactory->getTime() - $updatedAt) <= self::TTL;
	}                

	   
                                                                            
                                                                      
   
                                                               
   
                                                
    
	private function compactTurn(array $turn): array {
		return [
			'query' => mb_substr((string)($turn['query'] ?? ''), 0, self::MAX_QUERY_LENGTH),
			'intent' => (string)($turn['intent'] ?? 'unknown'),
		];
	}                   

	   
                                                
   
                                                       
   
                                            
    
	private function turnsOf(array $record): array {
		$turns = ($record['turns'] ?? []);
		if (is_array($turns) === false) {
			return [];
		}

		return array_values(array_filter($turns, 'is_array'));
	}               
}           
