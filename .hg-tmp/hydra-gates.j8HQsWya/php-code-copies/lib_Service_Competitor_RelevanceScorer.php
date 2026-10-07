<?php

   
                            
  
                                                                            
                                 
  
                                                                          
                                                                              
                                                                              
                                                                              
                                                                            
                                                                            
  
                                                                          
                                                                             
                                                           
  
                                                                               
                                                                              
  
                    
                                            
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Competitor;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                       
  
                                                                                                                                                             
   
class RelevanceScorer {

	   
                                                                 
   
               
    
	public const ENABLED_KEY = 'competitor.relevance';

	   
                                                                      
                                                                 
   
               
    
	public const CONTEXT_KEY = 'competitor.relevance_context';

	   
                                                            
   
               
    
	public const PROVIDER_CLASS = 'OCA\\Hermiq\\Service\\Llm\\ProviderFactory';

	   
                
   
                                                                                  
                                                     
                                          
   
                                                                                                                                                              
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                   
   
                
   
                                                                                                                                                              
    
	public function enabled(): bool {
		return ($this->appConfig->getValueString(Application::APP_ID, self::ENABLED_KEY, 'false') === 'true');
	}               

	   
                                                                  
                                                                          
                   
   
                                                     
                                                                  
   
                                                                     
   
                                                                                                                                                              
    
	public function fieldsFor(array $item, ?string $actingUserId = null): array {
		if ($this->enabled() === false) {
			return [];
		}

		$factory = $this->provider();
		if ($factory === null) {
			return [];
		}

		try {
			$answer = $factory->generateText($this->prompt(item: $item), $actingUserId, true, null);
		} catch (Throwable $e) {
			$this->logger->info('RelevanceScorer: hermiq did not answer', ['exception' => $e->getMessage()]);
			return [];
		}

		$parsed = $this->parse(answer: (string)$answer);
		if ($parsed === null) {
			return [];
		}

		return [
			'relevanceScore' => $parsed['score'],
			'relevanceReason' => $parsed['reason'],
			'agentAuthored' => true,
		];
	}                 

	   
                                           
   
                                              
   
                                                                                                 
   
                                                                                                                                                              
    
	public function parse(string $answer): ?array {
		$trimmed = trim($answer);
		if ($trimmed === '') {
			return null;
		}

		$decoded = json_decode($trimmed, true);
		if (is_array($decoded) === true && array_key_exists('score', $decoded) === true) {
			return $this->bounded(score: $decoded['score'], reason: (string)($decoded['reason'] ?? ''));
		}

		if (preg_match('/-?\d+/', $trimmed, $matches) !== 1) {
			return null;
		}

		return $this->bounded(score: $matches[0], reason: '');
	}             

	   
                                                                           
                                                                        
                                                 
   
                                            
                                                     
   
                                                  
    
	private function bounded(mixed $score, string $reason): ?array {
		if (is_numeric($score) === false) {
			return null;
		}

		$value = (int)$score;
		if ($value < 0 || $value > 100) {
			return null;
		}

		return ['score' => $value, 'reason' => mb_substr(trim($reason), 0, 500, 'UTF-8')];
	}               

	   
                                                                         
                                                                       
                                            
   
                                                     
   
                  
    
	private function prompt(array $item): string {
		$context = trim($this->appConfig->getValueString(Application::APP_ID, self::CONTEXT_KEY, ''));
		if ($context === '') {
			$context = 'the marketing and sales interests of this organisation';
		}

		return implode(
			"\n",
			[
				'Score how relevant this item is to ' . $context . '.',
				'Answer with JSON only: {"score": <integer 0 to 100>, "reason": "<one short sentence>"}.',
				'If you cannot judge it, answer {"score": 50, "reason": "not enough information"}.',
				'',
				'Title: ' . (string)($item['title'] ?? ''),
				'URL: ' . (string)($item['url'] ?? ''),
				'Summary: ' . mb_substr((string)($item['summary'] ?? ''), 0, 1000, 'UTF-8'),
			]
		);
	}              

	   
                                                             
   
                       
    
	private function provider(): ?object {
		try {
			$factory = $this->container->get(self::PROVIDER_CLASS);
		} catch (Throwable) {
			return null;
		}

		if (method_exists($factory, 'generateText') === false) {
			return null;
		}

		return $factory;
	}                
}           
