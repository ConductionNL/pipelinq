<?php

   
                        
  
                                                                          
                                                                      
                                                                        
                                                                       
                                                
  
                                                               
                                                                      
                                                                        
                                                                        
                                                                             
                                                    
                                                                          
                                                                     
                                                                             
                                                                           
                                                                        
                                           
                                                                           
                                                                               
                              
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                            
  
                                                                                           
  
                                                                    
                                    
  
                                  
                                                                                
                                                                           
                                                                            
                                                                         
                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                   
  
                                                                          
                                                                          
                                                                              
                                                                                
                                                                            
         
  
                                                                                           
   
class NaviService {
	   
                                                           
   
            
    
	public const MAX_FOLLOWUPS = 3;

	   
                                                                          
                           
   
                           
    
	public const INTENTS = ['trend', 'breakdown', 'count', 'conversion', 'unknown'];

	   
                
   
                                                                           
                                          
                                                                               
                                                                                            
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly TicketService $ticketService,
		private readonly NaviConversationStore $conversationStore,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                                            
   
                                                                   
                                                                      
                                                                        
                                                                             
                                                     
   
                                                              
                                                                
                                                           
                                                               
                                                                            
                                                                         
                                                                      
   
                  
                    
                         
                                       
                                       
                           
                                             
                                  
     
   
                                                                   
    
	public function processQuery(string $query, string $userId, ?string $conversationId = null): array {
		$trimmed = trim($query);
		if ($trimmed === '') {
			return $this->formatResponse(
				query: $query,
				llmResponse: [
					'resultType' => 'text',
					'textResponse' => 'Please enter a question to get started.',
					'suggestedFollowUps' => $this->defaultFollowUps(),
				],
				rawData: []
			);
		}

		$history = $this->conversationStore->read(userId: $userId, conversationId: $conversationId);
		$carried = $this->conversationStore->carryForward(
			detectedIntent: $this->detectIntent(query: $trimmed),
			history: $history
		);
		$intent = $carried['intent'];
		$context = $this->buildContext(
			intent: $intent,
			params: ['query' => $trimmed, 'previousQuery' => $carried['query']]
		);

		$this->conversationStore->append(
			userId: $userId,
			conversationId: $conversationId,
			history: $history,
			turn: ['query' => $trimmed, 'intent' => $intent]
		);

		                                                                          
		if ($context === [] && $intent !== 'unknown') {
			return $this->formatResponse(
				query: $query,
				llmResponse: [
					'resultType' => 'text',
					'textResponse' => 'I could not find any matching data for that question.',
					'suggestedFollowUps' => $this->defaultFollowUps(),
				],
				rawData: []
			);
		}

		$llmResponse = $this->buildResponseForIntent(intent: $intent, query: $trimmed, context: $context);
		$this->logger->info(
			message: '[NaviService] processed query',
			context: ['userId' => $userId, 'intent' => $intent, 'queryLength' => strlen($trimmed)]
		);

		return $this->formatResponse(query: $query, llmResponse: $llmResponse, rawData: $context);
	}                    

	   
                                                          
   
                                                                        
                                                                          
   
                                                          
   
                                        
   
                                                                                            
    
	public function detectIntent(string $query): string {
		$lower = mb_strtolower($query);

		if (preg_match('/\b(trend|verloop|over time|groei|growth)\b/u', $lower) === 1) {
			return 'trend';
		}

		if (preg_match('/\b(conversie|conversion|win rate|win-rate|won|gewonnen|verloren)\b/u', $lower) === 1) {
			return 'conversion';
		}

		if (preg_match('/\b(groep|breakdown|per (categorie|stage|fase|kanaal|channel)|by category|by stage|by channel)\b/u', $lower) === 1) {
			return 'breakdown';
		}

		if (preg_match('/\b(hoeveel|aantal|count|how many)\b/u', $lower) === 1) {
			return 'count';
		}

		return 'unknown';
	}                    

	   
                                                                       
   
                                               
                                                                          
                                                                            
                                                                  
   
                                                              
   
                                                                   
    
	public function buildContext(string $intent, array $params): array {
		return match ($intent) {
			'trend', 'conversion' => $this->findObjects(schemaKey: 'lead_schema'),
			'breakdown' => $this->findTickets(ticketType: TicketService::TYPE_REQUEST),
			'count' => $this->buildCountContext(
				query: (string)($params['query'] ?? ''),
				previousQuery: (string)($params['previousQuery'] ?? '')
			),
			default => [],
		};
	}                    

	   
                                                                   
   
                                                                             
                                                                            
                                                
   
                                                                            
                                                                            
                                                       
   
                                                   
                                                                                  
   
                                                              
   
                                                                        
   
                                                                                                                          
    
	private function buildCountContext(string $query, string $previousQuery = ''): array {
		$ticketType = $this->ticketService->detectTypeInText(text: $query);
		if ($ticketType === null) {
			$ticketType = $this->ticketService->detectTypeInText(text: $previousQuery);
		}

		if ($ticketType !== null) {
			return $this->findTickets(ticketType: $ticketType);
		}

		                                                           
		return $this->findObjects(schemaKey: 'lead_schema');
	}                         

	   
                                                              
   
                                                                    
                                                                          
                                                               
   
                                                                         
   
                                                              
   
                                                                                                                          
    
	private function findTickets(string $ticketType): array {
		$objects = [];
		foreach ($this->ticketService->findByType(ticketType: $ticketType) as $result) {
			$normalized = $this->normalizeFindObjectsResult(result: $result);
			if ($normalized !== null) {
				$objects[] = $normalized;
			}
		}

		return $objects;
	}                   

	   
                                                             
   
                                                                 
                                                                
   
                                            
                                       
                                                                                   
   
                                                                     
    
	private function buildResponseForIntent(string $intent, string $query, array $context): array {
		return match ($intent) {
			'trend' => $this->buildTrendResponse(rows: $context),
			'conversion' => $this->buildConversionResponse(rows: $context),
			'breakdown' => $this->buildBreakdownResponse(rows: $context),
			'count' => $this->buildCountResponse(rows: $context, query: $query),
			default => [
				'resultType' => 'text',
				'textResponse' => 'I am not sure how to answer that yet. Try asking about leads, requests, or trends.',
				'suggestedFollowUps' => $this->defaultFollowUps(),
			],
		};
	}                              

	   
                                                   
   
                                                            
   
                                
    
	private function buildTrendResponse(array $rows): array {
		$buckets = [];
		foreach ($rows as $row) {
			$created = (string)($row['createdAt'] ?? $row['created'] ?? '');
			if ($created === '') {
				continue;
			}

			$timestamp = strtotime($created);
			if ($timestamp === false) {
				continue;
			}

			$week = date('o-\WW', $timestamp);
			$buckets[$week] = ($buckets[$week] ?? 0) + 1;
		}

		ksort($buckets);

		return [
			'resultType' => 'chart',
			'chartData' => [
				'type' => 'line',
				'labels' => array_keys($buckets),
				'series' => [
					['name' => 'Leads', 'data' => array_values($buckets)],
				],
			],
			'textResponse' => sprintf('Found %d leads in the dataset.', count($rows)),
			'suggestedFollowUps' => [
				'Which stage has the highest drop-off?',
				'What is my conversion rate this quarter?',
				'How many requests are open?',
			],
		];
	}                          

	   
                                                      
   
                                                            
   
                                
    
	private function buildConversionResponse(array $rows): array {
		$counts = ['open' => 0, 'won' => 0, 'lost' => 0];
		foreach ($rows as $row) {
			$status = (string)($row['status'] ?? '');
			if (isset($counts[$status]) === true) {
				$counts[$status]++;
			}
		}

		$total = array_sum($counts);
		$rate = 0.0;
		if ($total !== 0) {
			$rate = round(($counts['won'] * 100.0) / $total, 1);
		}

		return [
			'resultType' => 'table',
			'tableData' => [
				'columns' => ['Metric', 'Value'],
				'rows' => [
					['Open leads', $counts['open']],
					['Won leads', $counts['won']],
					['Lost leads', $counts['lost']],
					['Conversion rate (%)', $rate],
				],
			],
			'textResponse' => sprintf('Conversion rate: %.1f%% across %d leads.', $rate, $total),
			'suggestedFollowUps' => [
				'Show the trend of leads over time.',
				'Which stage has the highest drop-off?',
				'How many requests are open?',
			],
		];
	}                               

	   
                                               
   
                                                                      
   
                                
    
	private function buildBreakdownResponse(array $rows): array {
		$counts = [];
		foreach ($rows as $row) {
			                                                                  
			                                                              
			$cat = (string)($row['category'] ?? '');
			if ($cat === '') {
				continue;
			}

			$counts[$cat] = ($counts[$cat] ?? 0) + 1;
		}

		arsort($counts);

		return [
			'resultType' => 'chart',
			'chartData' => [
				'type' => 'bar',
				'labels' => array_keys($counts),
				'series' => [
					['name' => 'Requests', 'data' => array_values($counts)],
				],
			],
			'textResponse' => sprintf('%d categories detected.', count($counts)),
			'suggestedFollowUps' => [
				'Which channel performs best?',
				'How many requests are open?',
				'Show the lead conversion rate.',
			],
		];
	}                              

	   
                            
   
                                                              
                                       
   
                                
    
	private function buildCountResponse(array $rows, string $query): array {
		$count = count($rows);
		return [
			'resultType' => 'text',
			'textResponse' => sprintf('Found %d records for "%s".', $count, $query),
			'suggestedFollowUps' => [
				'Show the trend over time.',
				'Group by category.',
				'What is my conversion rate?',
			],
		];
	}                          

	   
                                          
   
                                             
                                                                   
                                                                             
   
                                
   
                                                                                            
    
	public function formatResponse(string $query, array $llmResponse, array $rawData): array {
		$followUps = array_slice(
			array: (array)($llmResponse['suggestedFollowUps'] ?? []),
			offset: 0,
			length: self::MAX_FOLLOWUPS
		);

		$envelope = [
			'query' => $query,
			'resultType' => (string)($llmResponse['resultType'] ?? 'text'),
			'textResponse' => (string)($llmResponse['textResponse'] ?? ''),
			'suggestedFollowUps' => array_values(array_map('strval', $followUps)),
		];

		if (isset($llmResponse['chartData']) === true) {
			$envelope['chartData'] = (array)$llmResponse['chartData'];
		}

		if (isset($llmResponse['tableData']) === true) {
			$envelope['tableData'] = (array)$llmResponse['tableData'];
		}

		                                                                 
		                                                               
		unset($rawData);

		return $envelope;
	}                      

	   
                                                   
   
                              
    
	private function defaultFollowUps(): array {
		return [
			'How many leads are open?',
			'What is my conversion rate?',
			'Show the trend over time.',
		];
	}                        

	   
                                                                            
   
                                                                         
   
                                                                  
   
                                            
   
                                                                        
    
	private function findObjects(string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			$this->logger->info(
				message: '[NaviService] register or schema not configured',
				context: ['schemaKey' => $schemaKey]
			);
			return [];
		}

		try {
			$results = $this->objectService->findAll(
				config: ['filters' => ['register' => $register, 'schema' => $schema]]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[NaviService] findAll failed',
				context: ['schemaKey' => $schemaKey, 'error' => $e->getMessage()]
			);
			throw new RuntimeException(message: 'Navi query failed', code: 0, previous: $e);
		}

		$objects = [];
		                                                                      
		                                                             
		foreach ($results as $result) {
			$normalized = $this->normalizeFindObjectsResult(result: $result);
			if ($normalized !== null) {
				$objects[] = $normalized;
			}
		}

		return $objects;
	}                   

	   
                                                                            
   
                                                                              
                                                                       
                                                                    
   
                                                                    
   
                                                                         
                                                                          
    
	private function normalizeFindObjectsResult(mixed $result): ?array {
		if (is_array($result) === true) {
			return $result;
		}

		if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
			$serialized = $result->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($result) === true && method_exists($result, 'getObject') === true) {
			$data = $result->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return null;
	}                                  
}           
