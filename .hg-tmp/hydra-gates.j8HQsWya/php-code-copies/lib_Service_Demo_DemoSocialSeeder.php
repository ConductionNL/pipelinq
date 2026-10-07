<?php

   
                             
  
                                                                        
                                     
  
                    
                                      
  
                                                              
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                                                 
  
                                                                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Demo;

use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCA\Pipelinq\Service\Social\SocialPublicationStore;
use OCA\Pipelinq\Service\SocialAccountService;
use OCA\Pipelinq\Service\SocialPostService;

   
                                                                                
  
                                                                                                      
   
class DemoSocialSeeder {
	   
                
   
                                                             
                                                         
    
	public function __construct(
		private readonly ListObjectStore $store,
		private readonly DemoSeedValues $values,
	) {
	}                   

	   
                                                                          
   
                                                                        
                                                                          
                                                                            
                                                           
   
                                                                            
                                       
   
                                                                                                    
   
                                                                                     
   
                                                                                                       
    
	public function seed(array $social): array {
		$counts = ['accounts' => 0, 'posts' => 0, 'publications' => 0, 'skipped' => 0];
		if ($social === []) {
			return $counts;
		}

		[$accountSchema, $postSchema, $publicationSchema] = $this->schemas();

		$accounts = $this->seedAccounts(definitions: ($social['accounts'] ?? []), schemaSlug: $accountSchema);
		$posts = $this->seedPosts(definitions: ($social['posts'] ?? []), schemaSlug: $postSchema, accountIds: $accounts['ids']);

		$counts['accounts'] = $accounts['created'];
		$counts['posts'] = $posts['created'];
		$counts['skipped'] = ($accounts['skipped'] + $posts['skipped']);
		$counts['publications'] = $this->seedPublications(
			definitions: ($social['publications'] ?? []),
			schemaSlug: $publicationSchema,
			postIds: $posts['ids'],
			accountIds: $accounts['ids'],
			networks: $accounts['networks'],
		);

		return $counts;
	}            

	   
                                                                    
   
                                                                          
                                     
   
                                                                                                    
   
                                                                       
   
                                                                                                       
    
	public function remove(array $social): array {
		$counts = ['accounts' => 0, 'posts' => 0, 'publications' => 0];
		if ($social === []) {
			return $counts;
		}

		[$accountSchema, $postSchema, $publicationSchema] = $this->schemas();

		$postIds = $this->demoIds(
			definitions: ($social['posts'] ?? []),
			schemaSlug: $postSchema,
			field: 'title',
		);

		foreach ($this->store->findAll(schemaSlug: $publicationSchema) as $publication) {
			if (in_array((string)($publication['postId'] ?? ''), $postIds, true) === true
				&& $this->store->delete(schemaSlug: $publicationSchema, id: $this->store->idOf(payload: $publication)) === true
			) {
				$counts['publications']++;
			}
		}

		$counts['posts'] = $this->deleteAll(schemaSlug: $postSchema, ids: $postIds);
		$counts['accounts'] = $this->deleteAll(
			schemaSlug: $accountSchema,
			ids: $this->demoIds(definitions: ($social['accounts'] ?? []), schemaSlug: $accountSchema, field: 'displayName'),
		);

		return $counts;
	}              

	   
                                                                   
   
                                                                                           
                                                 
   
                                                                                                          
                                                                    
    
	private function seedAccounts(array $definitions, string $schemaSlug): array {
		$result = ['created' => 0, 'skipped' => 0, 'ids' => [], 'networks' => []];
		$existing = $this->idsByField(schemaSlug: $schemaSlug, field: 'displayName');

		foreach ($definitions as $definition) {
			$data = $this->values->resolvePlaceholders(data: ($definition['data'] ?? []));
			$result['networks'][$definition['key']] = (string)($data['network'] ?? '');

			$id = ($existing[(string)($data['displayName'] ?? '')] ?? '');
			if ($id !== '') {
				$result['ids'][$definition['key']] = $id;
				$result['skipped']++;
				continue;
			}

			$id = $this->store->idOf(payload: $this->store->save(schemaSlug: $schemaSlug, payload: $data));
			if ($id !== '') {
				$result['ids'][$definition['key']] = $id;
				$result['created']++;
			}
		}

		return $result;
	}                    

	   
                                                                                 
   
                                                                                        
                                              
                                                                            
   
                                                                                                                     
    
	private function seedPosts(array $definitions, string $schemaSlug, array $accountIds): array {
		$result = ['created' => 0, 'skipped' => 0, 'ids' => []];
		$existing = $this->idsByField(schemaSlug: $schemaSlug, field: 'title');

		foreach ($definitions as $definition) {
			$data = $this->values->resolvePlaceholders(data: ($definition['data'] ?? []));
			if (($existing[(string)($data['title'] ?? '')] ?? '') !== '') {
				$result['skipped']++;
				continue;
			}

			$data['accountIds'] = array_values(
				array_filter(
					array_map(
						static fn (string $key): string => ($accountIds[$key] ?? ''),
						($definition['accountKeys'] ?? [])
					)
				)
			);

			$id = $this->store->idOf(payload: $this->store->save(schemaSlug: $schemaSlug, payload: $data));
			if ($id !== '') {
				$result['ids'][$definition['key']] = $id;
				$result['created']++;
			}
		}

		return $result;
	}                 

	   
                                                          
   
                                                                                               
                                                     
                                                                               
                                                                            
                                                                       
   
                                                 
    
	private function seedPublications(array $definitions, string $schemaSlug, array $postIds, array $accountIds, array $networks): int {
		$saved = 0;
		foreach ($definitions as $definition) {
			$postId = ($postIds[$definition['postKey'] ?? ''] ?? '');
			$accountId = ($accountIds[$definition['accountKey'] ?? ''] ?? '');
			if ($postId === '' || $accountId === '') {
				continue;
			}

			$payload = $this->values->resolvePlaceholders(data: ($definition['data'] ?? []));
			$payload['postId'] = $postId;
			$payload['accountId'] = $accountId;
			$payload['network'] = ($networks[$definition['accountKey']] ?? '');

			if ($this->store->save(schemaSlug: $schemaSlug, payload: $payload) !== null) {
				$saved++;
			}
		}

		return $saved;
	}                        

	   
                                                                             
   
                                                                          
                                                          
                                                                
   
                                           
    
	private function demoIds(array $definitions, string $schemaSlug, string $field): array {
		$rows = $this->idsByField(schemaSlug: $schemaSlug, field: $field);
		$ids = [];
		foreach ($definitions as $definition) {
			$value = (string)($definition['data'][$field] ?? '');
			if (str_starts_with($value, DemoSeedValues::DEMO_PREFIX) === true && ($rows[$value] ?? '') !== '') {
				$ids[] = $rows[$value];
			}
		}

		return $ids;
	}               

	   
                      
   
                                                          
                                               
   
                                      
    
	private function deleteAll(string $schemaSlug, array $ids): int {
		$deleted = 0;
		foreach ($ids as $id) {
			if ($this->store->delete(schemaSlug: $schemaSlug, id: $id) === true) {
				$deleted++;
			}
		}

		return $deleted;
	}                 

	   
                                                                                   
   
                                                                   
    
	private function schemas(): array {
		return [
			$this->store->schemaSlug(SocialAccountService::SCHEMA_CONFIG_KEY, SocialAccountService::SCHEMA),
			$this->store->schemaSlug(SocialPostService::SCHEMA_CONFIG_KEY, SocialPostService::SCHEMA),
			$this->store->schemaSlug(SocialPublicationStore::SCHEMA_CONFIG_KEY, SocialPublicationStore::SCHEMA),
		];
	}               

	   
                                                        
   
                                                 
                                               
   
                                                                              
    
	private function idsByField(string $schemaSlug, string $field): array {
		$index = [];
		foreach ($this->store->findAll(schemaSlug: $schemaSlug) as $row) {
			$value = (string)($row[$field] ?? '');
			if ($value !== '' && isset($index[$value]) === false) {
				$index[$value] = $this->store->idOf(payload: $row);
			}
		}

		return $index;
	}                  
}           
