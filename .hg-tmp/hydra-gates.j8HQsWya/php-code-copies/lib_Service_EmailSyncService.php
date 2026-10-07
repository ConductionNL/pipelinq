<?php

   
                             
  
                                                            
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTime;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

   
                                                       
  
                                                                
                                     
  
                                                                             
   
class EmailSyncService {
	   
                
   
                                           
                                              
    
	public function __construct(
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                  
   
                                           
   
                                                       
                                                                                
    
	public function extractDomain(string $email): ?string {
		$parts = explode('@', $email);
		if (count($parts) !== 2) {
			return null;
		}

		return strtolower(trim($parts[1]));
	}                     

	   
                                                        
   
                                      
   
                                                 
                                                                                
    
	public function getSyncAccounts(string $userId): array {
		$value = $this->config->getUserValue(
			$userId,
			'pipelinq',
			'email_sync_accounts',
			'[]',
		);

		$accounts = json_decode($value, true);

		if (is_array($accounts) === true) {
			return $accounts;
		}

		return [];
	}                       

	   
                                                   
   
                                      
   
                                         
                                                                                
    
	public function isSyncEnabled(string $userId): bool {
		$value = $this->config->getUserValue(
			$userId,
			'pipelinq',
			'email_sync_enabled',
			'false',
		);

		return $value === 'true';
	}                     

	   
                                               
   
                                      
                                                 
   
                
                                                                                
    
	public function setSyncEnabled(string $userId, bool $enabled): void {
		$value = 'false';
		if ($enabled === true) {
			$value = 'true';
		}

		$this->config->setUserValue(
			$userId,
			'pipelinq',
			'email_sync_enabled',
			$value,
		);
	}                      

	   
                                             
   
                                      
                                                          
   
                
                                                                                
    
	public function setSyncAccounts(string $userId, array $accounts): void {
		$this->config->setUserValue(
			$userId,
			'pipelinq',
			'email_sync_accounts',
			json_encode($accounts),
		);
	}                       

	   
                                           
   
                                      
   
                                                                 
                                                                                
    
	public function getLastSyncTime(string $userId): ?string {
		$value = $this->config->getUserValue(
			$userId,
			'pipelinq',
			'email_sync_last',
			'',
		);

		if ($value !== '') {
			return $value;
		}

		return null;
	}                       

	   
                                              
   
                                      
   
                
                                                                                 
    
	public function updateLastSyncTime(string $userId): void {
		$this->config->setUserValue(
			$userId,
			'pipelinq',
			'email_sync_last',
			(new DateTime())->format(DateTime::ATOM),
		);
	}                          

	   
                                                           
   
                                                  
                                             
                                             
                                                     
                                       
                                                    
                                                  
                                                 
                                               
                                                       
   
                                                    
                                                                                
    
	public function buildEmailLinkData(
		string $messageId,
		string $subject,
		string $sender,
		array $recipients,
		string $date,
		string $linkedEntityType,
		string $linkedEntityId,
		string $direction,
		?string $threadId = null,
		?string $syncSource = null,
	): array {
		return [
			'messageId' => $messageId,
			'subject' => $subject,
			'sender' => $sender,
			'recipients' => $recipients,
			'date' => $date,
			'threadId' => $threadId,
			'linkedEntityType' => $linkedEntityType,
			'linkedEntityId' => $linkedEntityId,
			'direction' => $direction,
			'syncSource' => $syncSource,
			'excluded' => false,
			'deleted' => false,
		];
	}                          
}           
