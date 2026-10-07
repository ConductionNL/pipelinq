<?php

   
                                   
  
                                                                             
                                                                             
                                                                           
                                                                             
                                                                            
          
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                  
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use RuntimeException;

   
                                            
  
                                                  
   
class InvoiceSequenceService {
	   
                                                                         
   
               
    
	private const COUNTER_KEY = 'receipt_invoice_counter';

	   
                                                           
   
               
    
	private const COUNTER_YEAR_KEY = 'receipt_invoice_counter_year';

	   
                                                                      
   
            
    
	private const MAX_RETRIES = 5;

	   
                
   
                                                     
                                                                  
                                              
    
	public function __construct(
		private IDBConnection $db,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                               
   
                                                                              
                                                                         
                                                                              
                                                                     
   
                                                
   
                                                                                  
   
                                                   
    
	public function next(): string {
		$year = (int)(new DateTimeImmutable())->format('Y');
		$attempt = 0;

		while ($attempt < self::MAX_RETRIES) {
			$attempt++;
			try {
				$next = $this->compareAndIncrement(year: $year);
				if ($next > 0) {
					return sprintf('%04d-%06d', $year, $next);
				}
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Pipelinq: invoice sequence allocation retry',
					['attempt' => $attempt, 'exception' => $e->getMessage()]
				);
			}         
		}           

		throw new RuntimeException('Could not allocate a unique invoice number.');
	}            

	   
                                                                         
   
                                                                       
                                                                              
                                                                               
                                                                              
                                                                            
                                                                              
                                                                       
   
                                               
   
                                                                               
   
                                                   
    
	private function compareAndIncrement(int $year): int {
		$storedYear = $this->appConfig->getValueInt(Application::APP_ID, self::COUNTER_YEAR_KEY, 0);
		$current = $this->appConfig->getValueInt(Application::APP_ID, self::COUNTER_KEY, 0);

		                                                                       
		                                                                       
		                                                                           
		                                                                      
		                     
		if ($storedYear !== $year) {
			$this->appConfig->setValueInt(Application::APP_ID, self::COUNTER_YEAR_KEY, $year);
			$this->appConfig->setValueInt(Application::APP_ID, self::COUNTER_KEY, 0);
			$current = 0;
		}

		$next = ($current + 1);
		$affected = $this->casCounter(expected: $current, next: $next);
		if ($affected === 1) {
			return $next;
		}

		                                                                     
		                                                                       
		if ($current === 0) {
			$seeded = $this->seedCounter();
			if ($seeded === 1) {
				return 1;
			}
		}

		return 0;
	}                           

	   
                                                                         
                                                                               
   
                                           
                                      
   
                                           
    
	protected function casCounter(int $expected, int $next): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update('appconfig')
			->set('configvalue', $qb->createNamedParameter((string)$next))
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter(self::COUNTER_KEY)))
			->andWhere($qb->expr()->eq('configvalue', $qb->createNamedParameter((string)$expected)));

		return $qb->executeStatement();
	}                  

	   
                                                               
   
                                                                             
                                                                              
                                                                    
   
                                                          
    
	protected function seedCounter(): int {
		$existing = $this->appConfig->getValueInt(Application::APP_ID, self::COUNTER_KEY, 0);
		if ($existing !== 0) {
			return 0;
		}

		$this->appConfig->setValueInt(Application::APP_ID, self::COUNTER_KEY, 1);

		return 1;
	}                   
}           
