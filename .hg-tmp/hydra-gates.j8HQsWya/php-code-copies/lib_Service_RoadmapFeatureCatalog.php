<?php

   
                                  
  
                                                                     
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\App\IAppManager;
use OCP\ICacheFactory;

   
                                                              
  
                                                                          
                                                                       
                
  
                                                
   
class RoadmapFeatureCatalog {
	   
                
   
                                                                               
                                                       
    
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ICacheFactory $cacheFactory,
	) {
	}                   

	   
                                                                           
                                                                         
                                                                            
                                                                            
                                                          
   
                                                                                        
   
                                                 
    
	public function load(): array {
		$version = (string)$this->appManager->getAppVersion('pipelinq');
		$cache = $this->cacheFactory->createLocal('pipelinq_features');
		$cacheKey = 'v' . $version;

		$cached = $cache->get($cacheKey);
		if (is_array($cached) === true) {
			return $cached;
		}

		$features = $this->extractFeaturesFromSpecs(specsDir: __DIR__ . '/../../openspec/specs');
		if ($features === []) {
			$path = __DIR__ . '/../../docs/features.json';
			if (is_file($path) === true) {
				$decoded = json_decode((string)file_get_contents($path), associative: true);
				if (is_array($decoded) === true) {
					$features = $decoded;
				}
			}
		}

		$cache->set($cacheKey, $features, 86400);
		return $features;
	}            

	   
                                                                           
                                                                           
                                                                       
                                                                  
                                                                               
   
                                                            
   
                                                                                        
   
                                                 
    
	public function extractFeaturesFromSpecs(string $specsDir): array {
		if (is_dir($specsDir) === false) {
			return [];
		}

		$paths = glob($specsDir . '/*/spec.md');
		if ($paths === false) {
			return [];
		}

		$entries = [];
		foreach ($paths as $specPath) {
			$text = (string)file_get_contents($specPath);
			if (preg_match('/^---\s*\n(.*?\n)---\s*\n(.*)$/s', $text, $matches) !== 1) {
				continue;
			}

			$front = $matches[1];
			$body = $matches[2];
			if (preg_match('/^status:\s*(.+?)\s*$/m', $front, $statusMatch) !== 1) {
				continue;
			}

			if (strtolower(trim($statusMatch[1], " \t\"'")) !== 'done') {
				continue;
			}

			$slug = basename(dirname($specPath));
			$title = $slug;
			if (preg_match('/^#\s+(.+?)\s*$/m', $body, $titleMatch) === 1) {
				$title = trim((string)preg_replace('/\s+specification\s*$/i', '', trim($titleMatch[1])));
			}

			$entries[] = [
				'slug' => $slug,
				'title' => $title,
				'summary' => $this->extractSummary(body: $body),
				'docsUrl' => 'openspec/specs/' . $slug . '/spec.md',
			];
		}             

		                                                                
		                                                          
		usort($entries, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));
		return $entries;
	}                                

	   
                                                                          
   
                                                                  
   
                                                                       
    
	private function extractSummary(string $body): string {
		if (preg_match('/^##\s+Purpose\s*$/m', $body, $purposeMatch, PREG_OFFSET_CAPTURE) !== 1) {
			return '';
		}

		$rest = substr($body, ($purposeMatch[0][1] + strlen($purposeMatch[0][0])));
		$nextPos = strlen($rest);
		if (preg_match('/\n##\s/', $rest, $nextMatch, PREG_OFFSET_CAPTURE) === 1) {
			$nextPos = $nextMatch[0][1];
		}

		$section = trim(substr($rest, 0, $nextPos));
		$para = (preg_split('/\n\s*\n/', $section)[0] ?? '');
		return trim((string)preg_replace('/\s+/', ' ', $para));
	}                      
}           
