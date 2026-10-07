<?php

   
                                 
  
                                                                            
                                                                           
                                                                           
                                                                               
                                                                       
                                                                     
  
                                                                            
                                                       
                                                                            
                                                          
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use RuntimeException;

   
                                                                      
  
                                                                
   
class SystemServiceAccount {
	   
                          
    
	public const USER_ID = 'pipelinq-system';

	   
                               
    
	public const DISPLAY_NAME = 'Pipelinq system';

	   
                
   
                                                                   
                                                               
                                                                      
    
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IUserSession $userSession,
		private readonly ISecureRandom $random,
	) {
	}                   

	   
                                                                         
                        
   
                                               
   
                                             
   
                                                                 
    
	public function runAsCurrentOrSystem(callable $operation): mixed {
		$previous = $this->userSession->getUser();
		if ($previous !== null) {
			return $operation();
		}

		$this->userSession->setVolatileActiveUser($this->account());
		try {
			return $operation();
		} finally {
			$this->userSession->setVolatileActiveUser($previous);
		}
	}                            

	   
                                                      
   
                              
   
                                                                
   
                                                                 
    
	public function account(): IUser {
		$account = $this->userManager->get(self::USER_ID);
		if ($account !== null) {
			return $account;
		}

		$password = $this->random->generate(64);
		$account  = $this->userManager->createUser(self::USER_ID, $password);
		if ($account === false) {
			throw new RuntimeException('The '.self::USER_ID.' account could not be created.');
		}

		$account->setDisplayName(self::DISPLAY_NAME);
		$account->setEnabled(false);
		return $account;
	}               
}           
