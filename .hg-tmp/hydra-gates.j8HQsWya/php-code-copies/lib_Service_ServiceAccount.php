<?php

   
                           
  
                                                                               
                                                                               
                                                                           
                                                                              
                                                                         
                                                                              
                  
  
                                                                           
                                                                             
                                                                             
                                                
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                   
  
                                                                                         
                                                                                             
  
                                                                                                                      
   
abstract class ServiceAccount {
	   
                                                
   
               
    
	public const REASON_UNSET = 'unset';

	   
                                                             
   
               
    
	public const REASON_UNKNOWN = 'unknown';

	   
                                                            
   
               
    
	public const REASON_DISABLED = 'disabled';

	   
                                                                   
   
               
    
	public const REASON_NOT_IN_GROUP = 'not-in-group';

	   
                
   
                                                              
                                                                        
                                                                                 
                                                                                   
                                                            
    
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
		protected readonly LoggerInterface $logger,
	) {
	}                   

	   
                                       
   
                           
                                                                                                                       
    
	abstract protected function configKey(): string;

	   
                                        
   
                                
                                                                                                                       
    
	abstract public function group(): string;

	   
                                                                  
   
                                             
                                                        
   
                 
                                                                                                                       
    
	abstract protected function refuse(string $userId, string $reason): never;

	   
                                           
   
                           
                                                                                                                       
    
	public function configuredUserId(): string {
		return trim($this->appConfig->getValueString(Application::APP_ID, $this->configKey(), ''));
	}                        

	   
                                                                
   
                                                                                
                                                                                                                       
    
	public function status(): array {
		$userId = $this->configuredUserId();
		$reason = $this->problemWith(userId: $userId);

		return ['userId' => $userId, 'usable' => ($reason === null), 'reason' => $reason];
	}              

	   
                                           
   
                                                                     
                                                                                                                       
    
	public function require(): IUser {
		$userId = $this->configuredUserId();
		$reason = $this->problemWith(userId: $userId);
		$account = null;
		if ($reason === null) {
			$account = $this->userManager->get($userId);
		}

		if ($account === null) {
			$this->refuse(userId: $userId, reason: ($reason ?? self::REASON_UNKNOWN));
		}

		return $account;
	}               

	   
                                                                            
   
                                             
   
                                                 
                                                                                                                       
    
	public function runAs(callable $operation): mixed {
		$account = $this->require();
		$previous = $this->userSession->getUser();
		$this->userSession->setVolatileActiveUser($account);

		try {
			return $operation();
		} finally {
			$this->userSession->setVolatileActiveUser($previous);
		}
	}             

	   
                                                                               
   
                                  
   
                                                                                    
   
                                                                                                       
                                                                                                                       
    
	public function assign(string $userId): array {
		$userId = trim($userId);
		$account = null;
		if ($userId !== '') {
			$account = $this->userManager->get($userId);
		}

		if ($account === null) {
			throw new InvalidArgumentException('No account "'.$userId.'" exists.');
		}

		if ($account->isEnabled() === false) {
			throw new InvalidArgumentException('The account "'.$userId.'" is disabled.');
		}

		$group = $this->ensureGroup();
		if ($group === null) {
			throw new InvalidArgumentException('The group '.$this->group().' could not be created.');
		}

		if ($group->inGroup($account) === false) {
			$group->addUser($account);
		}

		$this->appConfig->setValueString(Application::APP_ID, $this->configKey(), $userId);

		return $this->status();
	}              

	   
                                                    
   
                                                                        
                                                                                                                       
    
	public function ensureGroup(): ?IGroup {
		$group = $this->groupManager->get($this->group());
		if ($group !== null) {
			return $group;
		}

		try {
			return $this->groupManager->createGroup($this->group());
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq: could not create a service group',
				['app' => Application::APP_ID, 'group' => $this->group(), 'exception' => $e->getMessage()]
			);
			return null;
		}
	}                   

	   
                                                              
   
                                  
   
                                                               
    
	private function problemWith(string $userId): ?string {
		if ($userId === '') {
			return self::REASON_UNSET;
		}

		$account = $this->userManager->get($userId);
		if ($account === null) {
			return self::REASON_UNKNOWN;
		}

		if ($account->isEnabled() === false) {
			return self::REASON_DISABLED;
		}

		if ($this->groupManager->isInGroup($userId, $this->group()) === false) {
			return self::REASON_NOT_IN_GROUP;
		}

		return null;
	}                   
}           
