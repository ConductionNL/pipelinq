<?php

   
                             
  
                                                             
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                    
                                                                                        
                                                                                                
                                                                                    
  
                                                                    
                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateInterval;
use DateTimeImmutable;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

   
                                              
  
                                                                               
                                                         
  
                                                                             
                                                                          
                                                                              
                                                                           
  
                                                                    
   
class ReportingService {
	   
                        
   
                                            
    
	private const DEFAULT_SLA_TARGETS = [
		'telefoon' => ['wait_seconds' => 30, 'target_percent' => 90, 'handle_minutes' => 5],
		'email' => ['response_hours' => 8, 'target_percent' => 90, 'resolution_hours' => 24],
		'balie' => ['wait_minutes' => 5, 'target_percent' => 90, 'handle_minutes' => 10],
		'chat' => ['response_seconds' => 30, 'target_percent' => 90, 'handle_minutes' => 10],
	];

	   
                
   
                                                
                                              
                                                                                             
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private TicketService $ticketService,
	) {
	}                   

	   
                                          
   
                                                                          
                                                                 
   
                                                        
                                                    
   
                                                                                                      
   
                                                                     
    
	public function getKpis(string $from, string $to): array {
		try {
			$contactmomenten = $this->fetchContactmomenten(from: $from, to: $to);

			$total = count($contactmomenten);
			$fcrRate = $this->calculateFcr(contactmomenten: $contactmomenten);
			$avgHandlingTime = $this->calculateAverageHandlingTime(
				durations: array_values(
					array_filter(
						array_column($contactmomenten, 'duration'),
						static fn ($v) => $v !== null && $v !== '',
					)
				)
			);

			                                              
			$slaCompliance = 0.0;
			if ($total > 0) {
				                                                                               
				$channelGroups = [];
				foreach ($contactmomenten as $moment) {
					$channelName = $moment['channel'] ?? 'unknown';
					$channelGroups[$channelName][] = $moment;
				}

				$totalWithin = 0;
				foreach ($channelGroups as $channel => $items) {
					$totalWithin += $this->countWithinSla(channel: $channel, moments: $items);
				}

				$slaCompliance = round(($totalWithin / $total) * 100, 1);
			}

			return [
				'total' => $total,
				'fcrRate' => $fcrRate,
				'avgHandlingTime' => $avgHandlingTime,
				'slaCompliance' => $slaCompliance,
			];
		} catch (\Throwable $e) {
			$this->logger->error('ReportingService::getKpis failed', ['exception' => $e]);
			return [
				'total' => 0,
				'fcrRate' => 0.0,
				'avgHandlingTime' => '0:00',
				'slaCompliance' => 0.0,
			];
		}         
	}               

	   
                                                      
   
                                            
                                        
   
                                                               
   
                                                                     
    
	public function getChannelDistribution(string $from, string $to): array {
		try {
			$contactmomenten = $this->fetchContactmomenten(from: $from, to: $to);
			return $this->groupByChannel(contactmomenten: $contactmomenten);
		} catch (\Throwable $e) {
			$this->logger->error('ReportingService::getChannelDistribution failed', ['exception' => $e]);
			return [];
		}         
	}                              

	   
                                                    
   
                                                                           
   
                                            
                                        
                                                   
   
                                                                                           
   
                                                                     
    
	public function getChannelTrend(string $from, string $to, string $granularity = 'daily'): array {
		try {
			$contactmomenten = $this->fetchContactmomenten(from: $from, to: $to);
			$trend = [];

			foreach ($contactmomenten as $moment) {
				$channel = $moment['channel'] ?? 'unknown';
				$contactedAt = $moment['contactedAt'] ?? null;
				if ($contactedAt === null) {
					continue;
				}

				try {
					$dateTime = new DateTimeImmutable($contactedAt);
					$key = $dateTime->format('Y-m-d');
					if ($granularity === 'weekly') {
						$key = $dateTime->format('o-W');
					}
				} catch (\Exception) {
					continue;
				}

				$trend[$channel][$key] = ($trend[$channel][$key] ?? 0) + 1;
			}

			return $trend;
		} catch (\Throwable $e) {
			$this->logger->error('ReportingService::getChannelTrend failed', ['exception' => $e]);
			return [];
		}         
	}                       

	   
                                                               
   
                                            
                                        
   
                                                                                                        
   
                                                                     
    
	public function getAgentPerformance(string $from, string $to): array {
		try {
			$contactmomenten = $this->fetchContactmomenten(from: $from, to: $to);
			$byAgent = [];

			foreach ($contactmomenten as $moment) {
				$agent = $moment['agent'] ?? 'unknown';
				if (isset($byAgent[$agent]) === false) {
					$byAgent[$agent] = [];
				}

				$byAgent[$agent][] = $moment;
			}

			$result = [];
			foreach ($byAgent as $agent => $moments) {
				$durations = array_values(
					array_filter(
						array_column($moments, 'duration'),
						static fn ($v) => $v !== null && $v !== '',
					)
				);

				$result[$agent] = [
					'count' => count($moments),
					'fcrRate' => $this->calculateFcr(contactmomenten: $moments),
					'avgHandlingTime' => $this->calculateAverageHandlingTime(durations: $durations),
				];
			}

			                                    
			uasort($result, static fn ($a, $b) => $b['count'] <=> $a['count']);

			return $result;
		} catch (\Throwable $e) {
			$this->logger->error('ReportingService::getAgentPerformance failed', ['exception' => $e]);
			return [];
		}         
	}                           

	   
                                                                     
   
                                                                   
   
                                            
                                            
                                        
   
                                                     
   
                                                                     
    
	public function getSlaCompliance(string $channel, string $from, string $to): float {
		try {
			$contactmomenten = $this->fetchContactmomenten(from: $from, to: $to);
			$channelItems = array_values(
				array_filter(
					$contactmomenten,
					static fn ($moment) => ($moment['channel'] ?? '') === $channel,
				)
			);

			$total = count($channelItems);
			if ($total === 0) {
				return 100.0;
			}

			$within = $this->countWithinSla(channel: $channel, moments: $channelItems);
			return round(($within / $total) * 100, 1);
		} catch (\Throwable $e) {
			$this->logger->error('ReportingService::getSlaCompliance failed', ['exception' => $e]);
			return 0.0;
		}         
	}                        

	   
                                                                                
   
                                                            
                                     
   
                                                                                           
   
                                              
   
                                                                     
                                                                                     
    
	public function calculateFcr(array $contactmomenten): float {
		$total = count($contactmomenten);
		if ($total === 0) {
			return 0.0;
		}

		$resolved = count(
			array_filter(
				$contactmomenten,
				static fn ($moment) => ($moment['outcome'] ?? '') === 'resolved',
			)
		);

		return round(($resolved / $total) * 100, 1);
	}                    

	   
                                    
   
                                                                        
   
                                                                     
    
	public function getSlaTargets(): array {
		return $this->getAllSlaTargets();
	}                     

	   
                                           
   
                                            
                                                             
                                                             
   
                                                                             
   
                                                                                         
    
	public function calculateSlaCompliance(
		string $channel,
		int $totalContacts,
		int $withinSla,
	): array {
		$target = $this->getSlaTarget(channel: $channel);
		$compliance = 0.0;
		if ($totalContacts > 0) {
			$compliance = round(($withinSla / $totalContacts) * 100, 1);
		}

		$status = 'green';
		if ($compliance < $target - 5) {
			$status = 'red';
		} elseif ($compliance < $target) {
			$status = 'orange';
		}

		return [
			'compliance' => $compliance,
			'target' => $target,
			'status' => $status,
		];
	}                              

	   
                                            
   
                                            
   
                                        
   
                                                                                         
    
	public function getSlaTarget(string $channel): float {
		$key = 'sla_' . $channel . '_target_percent';
		$default = self::DEFAULT_SLA_TARGETS[$channel]['target_percent'] ?? 90;

		return (float)$this->appConfig->getValueString(
			'pipelinq',
			$key,
			(string)$default,
		);
	}                    

	   
                              
   
                                                                        
   
                                                                                         
    
	public function getAllSlaTargets(): array {
		$targets = [];

		foreach (self::DEFAULT_SLA_TARGETS as $channel => $defaults) {
			$targets[$channel] = [];
			foreach ($defaults as $metric => $default) {
				$key = 'sla_' . $channel . '_' . $metric;
				$targets[$channel][$metric] = $this->appConfig->getValueString(
					'pipelinq',
					$key,
					(string)$default,
				);
			}
		}

		return $targets;
	}                        

	   
                                    
   
                                                                              
                                                                             
                                   
   
                                            
                                          
                                          
   
                                                                      
   
                                                                                         
    
	public function setSlaTarget(string $channel, string $metric, string $value): bool {
		                                          
		if (isset(self::DEFAULT_SLA_TARGETS[$channel]) === false) {
			return false;
		}

		                                                                
		if (array_key_exists($metric, self::DEFAULT_SLA_TARGETS[$channel]) === false) {
			return false;
		}

		$key = 'sla_' . $channel . '_' . $metric;
		$this->appConfig->setValueString('pipelinq', $key, $value);
		return true;
	}                    

	   
                                   
   
                                                                    
   
                                                     
                                                    
   
                                   
   
                                                                                                 
    
	public function generateCsv(array $headers, array $rows): string {
		$bom = "\xEF\xBB\xBF";
		$output = $bom . implode(';', array_map($this->neutralizeCsvCell(...), $headers)) . "\n";

		foreach ($rows as $row) {
			$output .= implode(
				';',
				array_map($this->neutralizeCsvCell(...), $row)
			) . "\n";
		}

		return $output;
	}                   

	   
                                                   
   
                                                              
   
                                                      
   
                                                                                     
    
	public function calculateAverageHandlingTime(array $durations): string {
		if (count($durations) === 0) {
			return '0:00';
		}

		$totalSeconds = 0;
		$counted = 0;
		foreach ($durations as $duration) {
			try {
				$interval = new DateInterval($duration);
				$totalSeconds += ($interval->h * 3600) + ($interval->i * 60) + $interval->s;
				$counted++;
			} catch (\Exception) {
				                          
				continue;
			}
		}

		if ($counted === 0) {
			return '0:00';
		}

		$avgSeconds = (int)($totalSeconds / $counted);
		$minutes = (int)($avgSeconds / 60);
		$seconds = $avgSeconds % 60;

		return $minutes . ':' . str_pad((string)$seconds, 2, '0', STR_PAD_LEFT);
	}                                    

	   
                                                                          
   
                                                                            
                                                                              
                                                                    
                                                                        
                                                                              
                                                         
   
                                                                       
                                                                            
                                             
   
                                                             
                                                         
   
                                                                             
   
                                                                                                                                       
    
	private function fetchContactmomenten(string $from, string $to): array {
		$rows = $this->ticketService->findByType(ticketType: TicketService::TYPE_CONTACTMOMENT);
		if ($rows === []) {
			return [];
		}

		$moments = [];
		foreach ($rows as $row) {
			$data = $row;
			if (is_array($data) === false) {
				if (($row instanceof \JsonSerializable) === false) {
					continue;
				}

				$data = $row->jsonSerialize();
				if (is_array($data) === false) {
					continue;
				}
			}

			$occurredAt = (string)($data['occurredAt'] ?? '');
			if ($this->withinRange(occurredAt: $occurredAt, from: $from, to: $to) === false) {
				continue;
			}

			$moments[] = [
				                                                                     
				'contactedAt' => $occurredAt,
				'agent' => ($data['assignee'] ?? 'unknown'),
				                                              
				'channel' => ($data['channel'] ?? 'unknown'),
				'outcome' => ($data['outcome'] ?? ''),
				'duration' => ($data['duration'] ?? null),
				'channelMetadata' => ($data['channelMetadata'] ?? []),
			];
		}             

		return $moments;
	}                            

	   
                                                                          
   
                                                                            
                                                                   
                                                            
   
                                                                            
                                                                    
                                                                
   
                                                              
    
	private function withinRange(string $occurredAt, string $from, string $to): bool {
		if ($occurredAt === '') {
			return false;
		}

		$date = substr($occurredAt, 0, 10);
		if ($from !== '' && $date < substr($from, 0, 10)) {
			return false;
		}

		if ($to !== '' && $date > substr($to, 0, 10)) {
			return false;
		}

		return true;
	}                   

	   
                                     
   
                                                                               
   
                                                       
    
	private function groupByChannel(array $contactmomenten): array {
		$groups = [];
		foreach ($contactmomenten as $moment) {
			$channel = $moment['channel'] ?? 'unknown';
			$groups[$channel] = ($groups[$channel] ?? 0) + 1;
		}

		return $groups;
	}                      

	   
                                            
   
                                                                       
                                                                        
                                                          
   
                                       
                                                                                        
   
                                                    
    
	private function countWithinSla(string $channel, array $moments): int {
		$within = 0;
		$targets = self::DEFAULT_SLA_TARGETS[$channel] ?? [];
		$threshold = match ($channel) {
			'telefoon' => (int)($targets['wait_seconds'] ?? 30),
			'balie' => (int)($targets['wait_minutes'] ?? 5) * 60,
			'chat' => (int)($targets['response_seconds'] ?? 30),
			'email' => (int)($targets['response_hours'] ?? 8) * 3600,
			default => 0,
		};

		if ($threshold === 0) {
			return count($moments);
		}

		foreach ($moments as $moment) {
			$metadata = $moment['channelMetadata'] ?? [];
			$measured = match ($channel) {
				'email' => (int)(($metadata['responseTimeHours'] ?? 0) * 3600),
				default => (int)($metadata['waitTime'] ?? $threshold + 1),
			};

			if ($measured <= $threshold) {
				$within++;
			}
		}

		return $within;
	}                      

	   
                                                             
   
                                                                     
                                                               
   
                                           
   
                                                             
    
	private function neutralizeCsvCell(mixed $value): string {
		$str = (string)$value;
		if (preg_match('/^[=+\-@\t\r]/', $str) === 1) {
			$str = "'" . $str;
		}

		return '"' . str_replace('"', '""', $str) . '"';
	}                         
}           
