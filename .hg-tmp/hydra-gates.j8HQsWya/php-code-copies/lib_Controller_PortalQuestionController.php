<?php

   
                                      
  
                                                                         
                                                                           
                                                                           
                                                                  
                                                                               
                                                                            
                    
  
                                                                               
                                                                             
                                                                               
                                  
  
                       
                                    
  
                                                              
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                              
  
                                                                                                                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Portal\PortalAssertionVerifier;
use OCA\Pipelinq\Service\DossierQuestionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;
use RuntimeException;

   
                                                  
  
                                                                                                                                                                       
   
class PortalQuestionController extends Controller {
	   
                                                                         
   
                           
    
	public const AUDIENCES = ['citizen', 'client'];

	   
                                                                             
   
               
    
	private const THROTTLE_ACTION = 'pipelinq_portal_question';

	   
                                                          
   
            
    
	private const MAX_TEXT = 5000;

	   
                
   
                                                          
                                                                            
                                                                          
                                                                    
                                                     
   
                                                                                                                                                                        
    
	public function __construct(
		IRequest $request,
		private readonly PortalAssertionVerifier $verifier,
		private readonly DossierQuestionService $questions,
		private readonly IThrottler $throttler,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                        
   
                                                                           
   
                                                                                                                                                                        
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function ask(): JSONResponse {
		$subjectRef = $this->subjectRef();
		if ($subjectRef instanceof JSONResponse) {
			return $subjectRef;
		}

		$collectionId = $this->text(name: 'collectionId');
		$question = $this->text(name: 'question');
		if ($collectionId === '' || $question === '' || mb_strlen($question) > self::MAX_TEXT) {
			return new JSONResponse(['error' => 'invalid_request'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->questions->ask(
				subjectRef: $subjectRef,
				collectionId: $collectionId,
				question: $question,
				title: $this->text(name: 'title')
			);
		} catch (RuntimeException $e) {
			$this->logger->warning('Pipelinq: portal question not saved', ['reason' => $e->getMessage()]);
			return new JSONResponse(['error' => 'unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		if ($result === null) {
			$this->registerRejected();
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($result, Http::STATUS_CREATED);
	}           

	   
                                                                      
   
                                                                         
   
                                                                                                                                                    
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function reply(): JSONResponse {
		$subjectRef = $this->subjectRef();
		if ($subjectRef instanceof JSONResponse) {
			return $subjectRef;
		}

		$ticketId = $this->text(name: 'ticket');
		$message = $this->text(name: 'message');
		if ($ticketId === '' || $message === '' || mb_strlen($message) > self::MAX_TEXT) {
			return new JSONResponse(['error' => 'invalid_request'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->questions->reply(subjectRef: $subjectRef, ticketId: $ticketId, message: $message);
		} catch (RuntimeException $e) {
			$this->logger->warning('Pipelinq: portal reply not saved', ['reason' => $e->getMessage()]);
			return new JSONResponse(['error' => 'unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		if ($result === null) {
			$this->registerRejected();
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($result);
	}             

	   
                                                             
   
                               
    
	private function subjectRef(): string|JSONResponse {
		$claims = $this->verifier->verify((string)$this->request->getHeader(PortalAssertionVerifier::HEADER));
		if ($claims === null) {
			$this->registerRejected();
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		if (in_array((string)($claims['audience'] ?? ''), self::AUDIENCES, true) === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		return (string)$claims['sub'];
	}                  

	   
                                                                  
   
                                           
   
                  
    
	private function text(string $name): string {
		$value = $this->request->getParam($name);
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}            

	   
                                                         
   
                
    
	private function registerRejected(): void {
		try {
			$this->throttler->registerAttempt(action: self::THROTTLE_ACTION, ip: $this->request->getRemoteAddress());
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: registerAttempt failed: '.$e->getMessage());
		}
	}                        
}           
