<?php

   
                                
  
                                                                                  
                                                                                     
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Lifecycle\SchemaLifecycleGraph;
use OCP\IAppConfig;

   
                                                                            
  
                                                                                
                                                                
                                                                                
                                               
  
                                                                         
                                                             
                                                                               
                                                                                
                                                                                  
                                                                                
                                                                                 
                                                                         
  
                                                                                        
                                                     
   
class ForecastDealService {
	   
                                                                             
   
               
    
	private const LEAD_SCHEMA_SLUG = 'lead';

	   
                                                                         
   
               
    
	private const FORECAST_LIFECYCLE_KEY = 'x-pipelinq-forecast-lifecycle';

	   
                                                                               
                                                                                 
   
                           
    
	public const CLOSED_CATEGORIES = ['closed_won', 'closed_lost'];

	   
                                                                            
                                                               
   
               
    
	public const DEFAULT_CATEGORY = 'pipeline';

	   
                                                                            
                                                            
   
                           
    
	public const OPEN_CATEGORIES = ['commit', 'best_case', 'pipeline', 'omitted'];

	   
                                                                                  
   
               
    
	public const COMMIT_THRESHOLD_KEY = 'forecast_commit_threshold';

	   
                                                                   
   
            
    
	public const COMMIT_THRESHOLD_DEFAULT = 50000;

	   
                                                                    
   
            
    
	public const JUSTIFICATION_MIN_LENGTH = 10;

	   
                
   
                                                       
                                                                                                           
    
	public function __construct(
		private IAppConfig $appConfig,
		private SchemaLifecycleGraph $lifecycleGraph = new SchemaLifecycleGraph(),
	) {
	}                   

	   
                                                                            
   
                                                                                   
    
	private function forecastLifecycle(): array {
		$annotation = $this->lifecycleGraph->configurationFor(
			schemaSlug: self::LEAD_SCHEMA_SLUG,
			key: self::FORECAST_LIFECYCLE_KEY
		);

		if (is_array($annotation) === true) {
			return $annotation;
		}

		return [];
	}                         

	   
                                                                     
   
                                                                                  
    
	private function closedCategories(): array {
		$closed = ($this->forecastLifecycle()['closed'] ?? null);
		if (is_array($closed) === false || $closed === []) {
			return self::CLOSED_CATEGORIES;
		}

		return array_map(static fn ($value): string => (string)$value, $closed);
	}                        

	   
                                                          
   
                                                                                
    
	private function openCategories(): array {
		$open = ($this->forecastLifecycle()['open'] ?? null);
		if (is_array($open) === false || $open === []) {
			return self::OPEN_CATEGORIES;
		}

		return array_map(static fn ($value): string => (string)$value, $open);
	}                      

	   
                                                           
   
                                                                     
    
	private function defaultCategory(): string {
		$default = ($this->forecastLifecycle()['default'] ?? null);
		if (is_string($default) === true && $default !== '') {
			return $default;
		}

		return self::DEFAULT_CATEGORY;
	}                       

	   
                                                              
   
                                                      
   
                                                                     
   
                                                                               
    
	public function isClosedCategory(?string $category): bool {
		return $category !== null && in_array($category, $this->closedCategories(), true);
	}                        

	   
                                                                 
   
                                                                               
                                                                         
   
                                                            
   
                                                                                          
   
                                                                                  
    
	public function applyDefaultCategory(array $data): ?array {
		$current = $data['forecast_category'] ?? null;
		if (is_string($current) === true && $current !== '') {
			return null;
		}

		$data['forecast_category'] = $this->defaultCategory();
		return $data;
	}                            

	   
                                                               
   
                                                                               
          
                                                                              
                                                                            
                                                              
   
                                                                         
                                                                        
   
                                                                                      
   
                                                                                                  
    
	public function validateTransition(array $oldData, array $newData): ?string {
		$oldCategory = $oldData['forecast_category'] ?? null;
		$newCategory = $newData['forecast_category'] ?? null;

		if (is_string($oldCategory) === true
			&& is_string($newCategory) === true
			&& $oldCategory !== $newCategory
			&& $this->isClosedCategory(category: $oldCategory) === true
			&& in_array($newCategory, $this->openCategories(), true) === true
		) {
			return 'forecast.error.closed_deal_locked';
		}

		if ($newCategory === 'commit' && $this->requiresJustification(data: $newData) === true) {
			$justification = trim((string)($newData['commit_justification'] ?? ''));
			if (mb_strlen($justification) < self::JUSTIFICATION_MIN_LENGTH) {
				return 'forecast.error.justification_required';
			}
		}

		return null;
	}                          

	   
                                                           
   
                                                                         
   
                                                    
   
                                                       
   
                                                                                  
    
	public function requiresJustification(array $data): bool {
		$value = (float)($data['value'] ?? 0);
		return $value > (float)$this->getCommitThreshold();
	}                             

	   
                                                              
   
                                                        
   
                                                                                  
    
	public function getCommitThreshold(): int {
		$configured = $this->appConfig->getValueInt(
			Application::APP_ID,
			self::COMMIT_THRESHOLD_KEY,
			self::COMMIT_THRESHOLD_DEFAULT
		);

		if ($configured <= 0) {
			return self::COMMIT_THRESHOLD_DEFAULT;
		}

		return $configured;
	}                          

	   
                                                          
   
                                                                            
                                                              
   
                                                                         
                                                                        
   
                                                                                                 
   
                                                                                  
    
	public function applyReopenReset(array $oldData, array $newData): ?array {
		$newCategory = $newData['forecast_category'] ?? null;
		if ($this->isClosedCategory(category: $newCategory) === false) {
			return null;
		}

		$oldStatus = $oldData['status'] ?? null;
		$newStatus = $newData['status'] ?? null;
		$closedStatuses = ['won', 'lost'];

		$wasClosed = in_array($oldStatus, $closedStatuses, true);
		$nowOpen = $newStatus !== null && in_array($newStatus, $closedStatuses, true) === false;

		if ($wasClosed === true && $nowOpen === true) {
			$newData['forecast_category'] = $this->defaultCategory();
			return $newData;
		}

		return null;
	}                        
}           
