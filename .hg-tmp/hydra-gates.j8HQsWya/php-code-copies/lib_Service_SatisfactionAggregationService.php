<?php

   
                                           
  
                                                                              
                                                                           
        
  
                                                                            
                                                                            
                                                                              
                                                                           
  
                                                                             
                                                                           
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                        
  
                                                                                                                                
   
class SatisfactionAggregationService {
	   
                                                      
   
            
    
	public const WINDOW_DAYS = 90;

	   
                                       
   
            
    
	public const VERBATIM_COUNT = 3;

	   
                                                     
   
            
    
	private const LIMIT = 500;

	   
                
   
                                                                        
                                                                               
                                              
    
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                                                   
                                                  
   
                                                                     
   
                                                                            
   
                                                                                                                                 
    
	public function npsOf(array $responses): ?float {
		$promoters = 0;
		$detractors = 0;
		$answered = 0;

		foreach ($responses as $response) {
			$score = ($response['npsScore'] ?? null);
			if (is_numeric($score) === false) {
				continue;
			}

			$answered++;
			$score = (int)$score;
			if ($score >= DetractorFollowUpService::NPS_PROMOTER_FLOOR) {
				$promoters++;
			} elseif ($score <= DetractorFollowUpService::NPS_DETRACTOR_CEILING) {
				$detractors++;
			}
		}

		if ($answered === 0) {
			                                                                
			                                                                
			return null;
		}

		return round(((($promoters - $detractors) / $answered) * 100), 1);
	}             

	   
                             
   
                                              
                                                                                
   
                                                                           
                      
   
                                                                                                                                 
    
	public function forClient(string $clientId, ?DateTimeImmutable $now = null): array {
		$responses = $this->responsesFor(clientId: $clientId);

		return $this->summarise(responses: $responses, now: $now);
	}                 

	   
                                 
   
                                                                          
   
                                                                     
                                                                                
   
                                           
   
                                                                                                                                 
    
	public function summarise(array $responses, ?DateTimeImmutable $now = null): array {
		$now = ($now ?? new DateTimeImmutable());

		if ($responses === []) {
			return [
				'empty' => true,
				'responseCount' => 0,
				'nps' => null,
				'averageRating' => null,
				'trend' => 'unknown',
				'verbatims' => [],
			];
		}

		$current = [];
		$previous = [];
		$currentFloor = $now->modify('-' . self::WINDOW_DAYS . ' days');
		$previousFloor = $now->modify('-' . (self::WINDOW_DAYS * 2) . ' days');

		foreach ($responses as $response) {
			$submitted = $this->submittedAt(response: $response);
			if ($submitted === null) {
				continue;
			}

			if ($submitted >= $currentFloor) {
				$current[] = $response;
				continue;
			}

			if ($submitted >= $previousFloor) {
				$previous[] = $response;
			}
		}

		$ratings = [];
		foreach ($responses as $response) {
			$rating = ($response['averageRating'] ?? null);
			if (is_numeric($rating) === true) {
				$ratings[] = (float)$rating;
			}
		}

		                                                                     
		$averageRating = null;
		if ($ratings !== []) {
			$averageRating = round((array_sum($ratings) / count($ratings)), 2);
		}

		return [
			'empty' => false,
			'responseCount' => count($responses),
			'nps' => $this->npsOf(responses: $responses),
			'averageRating' => $averageRating,
			'trend' => $this->trend(current: $current, previous: $previous),
			'verbatims' => $this->verbatims(responses: $responses),
		];
	}                 

	   
                                                          
   
                                                                     
                                                                           
   
                                             
    
	private function trend(array $current, array $previous): string {
		$now = $this->npsOf(responses: $current);
		$before = $this->npsOf(responses: $previous);

		if ($now === null || $before === null) {
			                                                            
			                             
			return 'unknown';
		}

		if ($now > $before) {
			return 'up';
		}

		if ($now < $before) {
			return 'down';
		}

		return 'flat';
	}             

	   
                                            
   
                                                                     
   
                                                            
    
	private function verbatims(array $responses): array {
		$withText = array_values(
			array_filter(
				$responses,
				static fn (array $response): bool => trim((string)($response['verbatim'] ?? '')) !== ''
			)
		);

		usort(
			$withText,
			static fn (array $a, array $b): int => strcmp(
				(string)($b['submittedAt'] ?? ''),
				(string)($a['submittedAt'] ?? '')
			)
		);

		$verbatims = [];
		foreach (array_slice($withText, 0, self::VERBATIM_COUNT) as $response) {
			$verbatims[] = [
				'text' => (string)$response['verbatim'],
				'submittedAt' => (string)($response['submittedAt'] ?? ''),
				'classification' => (string)($response['classification'] ?? ''),
			];
		}

		return $verbatims;
	}                 

	   
                                  
   
                                                       
   
                                                                      
    
	private function submittedAt(array $response): ?DateTimeImmutable {
		$value = trim((string)($response['submittedAt'] ?? ''));
		if ($value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $e) {
			return null;
		}
	}                   

	   
                                       
   
                                              
   
                                                           
    
	private function responsesFor(string $clientId): array {
		$clientId = trim($clientId);
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'surveyResponse_schema', '');
		if ($clientId === '' || $register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->objectService->findAll(
				[
					'filters' => ['register' => $register, 'schema' => $schema, 'clientRef' => $clientId],
					'limit' => self::LIMIT,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SatisfactionAggregationService: the response read failed',
				['client' => $clientId, 'exception' => $e->getMessage()]
			);

			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
				continue;
			}

			if (($row instanceof \JsonSerializable) === true) {
				$data = $row->jsonSerialize();
				if (is_array($data) === true) {
					$out[] = $data;
				}
			}
		}

		return $out;
	}                    
}           
