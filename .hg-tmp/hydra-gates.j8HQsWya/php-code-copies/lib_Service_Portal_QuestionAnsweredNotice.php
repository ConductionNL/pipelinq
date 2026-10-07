<?php

   
                                    
  
                                                                              
                                                                                
                                                                              
                                                                                
                                                                            
            
  
                                                                                
                                                                 
                                               
  
                                                                              
                                                                        
  
                    
                                        
  
                                                              
                                  
                                                                                    
  
                         
  
                              
  
                                                                                                                                                
  
                                                                    
                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Portal\PortalContributionProvider;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                  
  
                                                                                                                                                
   
class QuestionAnsweredNotice {

	   
                                
    
	private const LANGUAGE = 'nl';

	   
                                                                 
    
	public const PORTAL_MESSAGE_REGISTER = 'portaliq';

	   
                                                             
    
	public const PORTAL_MESSAGE_SCHEMA = 'portalMessage';

	   
                                                                          
                                                             
    
	public const SITE = '/index.php/apps/portaliq/site';

	   
                                                              
    
	public const COLLECTION = 'myQuestions';

	   
                
   
                                                                                  
                                                                                                 
                                                                                                 
                                                                                             
    
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IFactory $l10nFactory,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                                            
   
                                                                         
                                                                            
                                                            
   
                                                                   
                                                                  
   
                
   
                                                                                                                                                 
    
	public function isAnswered(array $before, array $after): bool {
		$answer = trim((string)($after['customerMessage'] ?? ''));

		return ($after['ticketType'] ?? null) === 'request'
			&& ($after['channel'] ?? null) === 'portal'
			&& trim((string)($after['portalSubject'] ?? '')) !== ''
			&& $answer !== ''
			&& $answer !== trim((string)($before['customerMessage'] ?? ''));
	}                  

	   
                                                
   
                                                                        
                                  
   
                                                                 
                                                          
   
                                               
   
                                                                                                                                                 
    
	public function tell(array $ticket, string $ticketId): bool {
		$subjectRef = trim((string)($ticket['portalSubject'] ?? ''));
		if ($subjectRef === '' || $ticketId === '') {
			return false;
		}

		try {
			$l10n = $this->l10nFactory->get(Application::APP_ID, self::LANGUAGE);
			$link = $this->urlGenerator->getAbsoluteURL(self::SITE)
				.'#open='.Application::APP_ID.'/'.self::COLLECTION.'/'.rawurlencode($ticketId);

			$message = [
				'subjectRef' => $subjectRef,
				'subject' => $l10n->t('Your question has been answered'),
				'body' => $l10n->t('There is an answer to your question "%1$s".', [(string)($ticket['title'] ?? '')])
					."\n\n".$l10n->t('Read the answer here: %1$s', [$link]),
				'read' => false,
				'receivedAt' => gmdate('c'),
				'ruleKey' => PortalContributionProvider::RULE_QUESTION_ANSWERED,
				'recordLink' => ['app' => Application::APP_ID, 'collection' => self::COLLECTION, 'id' => $ticketId],
			];

			$this->objectService->runAsSystem(
				fn (): mixed => $this->objectService->saveObject(
					object: $message,
					register: self::PORTAL_MESSAGE_REGISTER,
					schema: self::PORTAL_MESSAGE_SCHEMA,
					_rbac: false,
					_multitenancy: false
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'QuestionAnsweredNotice: the resident could not be told their question was answered',
				['app' => Application::APP_ID, 'ticket' => $ticketId, 'error' => $e->getMessage()]
			);
			return false;
		}         

		return true;
	}            
}           
