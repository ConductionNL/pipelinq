<?php

   
                         
  
                                                                          
                                                                               
                                                                            
                                                                     
                                                                            
                                                                          
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                            
  
                                                        
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                            
  
                                                        
  
                                                                                   
                                                                                  
                      
   
class XWikiService {
	   
                                                                      
   
            
    
	private const DEFAULT_CACHE_TTL = 300;

	   
                                                                            
                                                         
   
                     
    
	private ?bool $availabilityMemo = null;

	   
                                              
   
                    
    
	private ?ICache $cache = null;

	   
                
   
                                                             
                                                     
                                                                 
                                                                      
                                                                         
                                                                        
                                                                     
                                                                        
                                          
    
	public function __construct(
		private IClientService $clientService,
		private IAppConfig $appConfig,
		private ICacheFactory $cacheFactory,
		private ContainerInterface $container,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
	}                   

	   
                       
   
                                                       
                                                    
                                                                      
                                                                    
                                           
                                           
                                               
   
                                                                                      
   
                                            
    
	public function search(string $query, ?string $space = null, array $tags = [], int $limit = 10, int $offset = 0): array {
		$limit = max(1, min(100, $limit));
		$offset = max(0, $offset);

		$cacheKey = $this->makeCacheKey(bucket: 'search', parts: [$query, $space ?? '', implode(',', $tags), $limit, $offset]);
		$cached = $this->fromCache(key: $cacheKey);
		if ($cached !== null) {
			return $cached;
		}

		                                                                       
		                                                                         
		                                                                  
		                                                                         
		                                                                         
		                                                                 
		                                                                    
		$orResults = $this->searchViaOpenRegister(query: $query, limit: $limit, offset: $offset);
		if ($orResults !== null) {
			return $this->finishSearch(results: $orResults, space: $space, tags: $tags, limit: $limit, offset: $offset, cacheKey: $cacheKey);
		}

		$base = $this->getBaseUrl();
		if ($base === '') {
			return $this->emptyResult(limit: $limit, offset: $offset);
		}

		$params = [
			'q' => $query,
			'number' => $limit,
			'start' => $offset,
			'media' => 'xml',
		];
		if ($space !== null && $space !== '') {
			$params['scope'] = 'name,content,title';
			$params['wikis'] = 'xwiki';
		}

		$url = $base . '/rest/wikis/query?' . http_build_query($params);
		$xml = $this->fetchXml(url: $url);
		$results = $this->parseSearchResults(xml: $xml);

		return $this->finishSearch(results: $results, space: $space, tags: $tags, limit: $limit, offset: $offset, cacheKey: $cacheKey);
	}              

	   
                                                                            
                                                                         
   
                                                                           
                                                                      
   
                                                                              
                                                    
                                                       
                                  
                                         
                                                     
   
                                
   
                                            
    
	private function finishSearch(array $results, ?string $space, array $tags, int $limit, int $offset, string $cacheKey): array {
		if ($space !== null && $space !== '') {
			$results = array_values(array_filter($results, static fn (array $item): bool => ($item['space'] ?? '') === $space));
		}

		if (count($tags) > 0) {
			$results = array_values(
				array_filter(
					$results,
					static function (array $item) use ($tags): bool {
						$itemTags = $item['tags'] ?? [];
						foreach ($tags as $tag) {
							if (in_array($tag, $itemTags, true) === true) {
								return true;
							}
						}

						return false;
					}
				)
			);
		}

		$payload = [
			'results' => array_slice($results, 0, $limit),
			'total' => count($results),
			'limit' => $limit,
			'offset' => $offset,
		];

		$this->toCache(key: $cacheKey, value: $payload);
		return $payload;
	}                    

	   
                                                                            
   
                                                                             
                                                                            
                                                                         
                                                                            
                                                                            
                                                                              
                                                                         
                                                                              
                                           
   
                                         
                                  
                                         
   
                                                                                      
   
                                            
    
	private function searchViaOpenRegister(string $query, int $limit, int $offset): ?array {
		$params = http_build_query(['q' => $query, 'limit' => $limit, 'offset' => $offset]);
		$url = $this->urlGenerator->getAbsoluteURL('/apps/openregister/api/integrations/xwiki/search?' . $params);

		try {
			$client = $this->clientService->newClient();
			$response = $client->get(
				$url,
				[
					'timeout' => 10,
					'connect_timeout' => 3,
					'headers' => ['OCS-APIREQUEST' => 'true', 'Accept' => 'application/json'],
					'nextcloud' => ['allow_local_address' => true],
				]
			);
		} catch (Throwable $e) {
			                                                                     
			                                                                       
			$this->logger->debug('xWiki OR search unavailable, falling back to direct path', ['exception' => $e]);
			return null;
		}

		$status = $response->getStatusCode();
		if ($status !== 200) {
			$this->logger->debug('xWiki OR search returned non-200, falling back', ['status' => $status]);
			return null;
		}

		$decoded = json_decode((string)$response->getBody(), true);
		if (is_array($decoded) === false || isset($decoded['results']) === false || is_array($decoded['results']) === false) {
			return null;
		}

		return $this->mapOpenRegisterRows(rows: $decoded['results']);
	}                             

	   
                                                                    
   
                                                                        
   
                                                             
    
	private function mapOpenRegisterRows(array $rows): array {
		$mapped = [];
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$tags = [];
			if (isset($row['tags']) === true && is_array($row['tags']) === true) {
				$tags = $row['tags'];
			}

			$mapped[] = [
				'id' => (string)($row['id'] ?? $row['reference'] ?? ''),
				'title' => (string)($row['title'] ?? $row['id'] ?? $row['reference'] ?? ''),
				'space' => (string)($row['space'] ?? ''),
				'modified' => (string)($row['modified'] ?? ''),
				'url' => (string)($row['url'] ?? ''),
				'tags' => $tags,
			];
		}

		return $mapped;
	}                           

	   
                          
   
                                                              
                                  
                              
   
                                
   
                                                         
    
	public function getPages(string $space, int $limit = 10, int $offset = 0): array {
		$limit = max(1, min(100, $limit));
		$offset = max(0, $offset);

		$cacheKey = $this->makeCacheKey(bucket: 'pages', parts: [$space, $limit, $offset]);
		$cached = $this->fromCache(key: $cacheKey);
		if ($cached !== null) {
			return $cached;
		}

		$base = $this->getBaseUrl();
		if ($base === '' || $space === '') {
			return $this->emptyResult(limit: $limit, offset: $offset);
		}

		$wiki = 'xwiki';
		$url = sprintf('%s/rest/wikis/%s/spaces/%s/pages?media=xml&number=%d&start=%d', $base, $wiki, rawurlencode($space), $limit, $offset);
		$xml = $this->fetchXml(url: $url);
		$results = $this->parsePageSummaries(xml: $xml, space: $space);

		$payload = [
			'results' => $results,
			'total' => count($results),
			'limit' => $limit,
			'offset' => $offset,
		];
		$this->toCache(key: $cacheKey, value: $payload);
		return $payload;
	}                

	   
                                          
   
                                                          
                                                                                  
   
                                                                           
   
                                                         
    
	public function getPageContent(string $wiki, string $page): array {
		$wiki = trim($wiki);
		if ($wiki === '') {
			$wiki = 'xwiki';
		}

		$page = trim($page);

		$cacheKey = $this->makeCacheKey(bucket: 'page', parts: [$wiki, $page]);
		$cached = $this->fromCache(key: $cacheKey);
		if ($cached !== null) {
			return $cached;
		}

		$base = $this->getBaseUrl();
		if ($base === '' || $page === '') {
			return [
				'title' => '',
				'content' => '',
				'url' => '',
				'modified' => '',
				'space' => '',
				'id' => '',
			];
		}

		$segments = array_map('rawurlencode', explode('.', $page));
		$space = (string)($segments[0] ?? '');
		$pageName = (string)end($segments);
		$spacesPath = implode('/spaces/', array_slice($segments, 0, -1));
		$url = sprintf('%s/rest/wikis/%s/spaces/%s/pages/%s?media=xml', $base, rawurlencode($wiki), $spacesPath, $pageName);
		$xml = $this->fetchXml(url: $url);
		$page = $this->parsePage(xml: $xml);

		                                                                      
		                                                                        
		                               
		$viewUrl = sprintf('%s/bin/view/%s', $base, implode('/', $segments));
		$rawHtml = $this->fetchRaw(url: $viewUrl);
		$content = $this->extractAndSanitiseHtml(html: $rawHtml);

		$page['content'] = $content;
		$page['url'] = $viewUrl;
		$page['space'] = $space;

		$this->toCache(key: $cacheKey, value: $page);
		return $page;
	}                      

	   
                                      
   
                                                                                                    
   
                                                         
    
	public function getStatus(): array {
		$base = $this->getBaseUrl();
		if ($base === '') {
			return [
				'available' => false,
				'version' => '',
				'baseUrl' => '',
				'source' => 'unconfigured',
			];
		}

		if ($this->availabilityMemo !== null) {
			return [
				'available' => $this->availabilityMemo,
				'version' => '',
				'baseUrl' => $base,
				'source' => $this->getInstanceSource(),
			];
		}

		try {
			$client = $this->clientService->newClient();
			$response = $client->get($base . '/rest', ['timeout' => 5, 'connect_timeout' => 3]);
			$body = (string)$response->getBody();
			$version = '';
			if (preg_match('#<version>([^<]+)</version>#', $body, $matches) === 1) {
				$version = (string)$matches[1];
			}

			$this->availabilityMemo = true;
			return [
				'available' => true,
				'version' => $version,
				'baseUrl' => $base,
				'source' => $this->getInstanceSource(),
			];
		} catch (Throwable $e) {
			$this->logger->debug('xWiki status check failed', ['exception' => $e]);
			$this->availabilityMemo = false;
			return [
				'available' => false,
				'version' => '',
				'baseUrl' => $base,
				'source' => $this->getInstanceSource(),
			];
		}         
	}                 

	   
                                                                          
                                                               
   
                                                                           
    
	private function getBaseUrl(): string {
		                                                                     
		try {
			$cls = '\\OCA\\Xwiki\\SettingsManager';
			if (class_exists($cls) === true && $this->container->has($cls) === true) {
				$manager = $this->container->get($cls);
				if (method_exists($manager, 'getBaseUrl') === true) {
					$url = (string)$manager->getBaseUrl();
					if ($url !== '') {
						return rtrim($url, '/');
					}
				}
			}
		} catch (Throwable $e) {
			$this->logger->debug('xWiki SettingsManager unavailable', ['exception' => $e]);
		}

		                                                                         
		                                                                       
		return '';
	}                  

	   
                                                                 
   
                  
    
	private function getInstanceSource(): string {
		try {
			if (class_exists('\\OCA\\Xwiki\\SettingsManager') === true && $this->container->has('\\OCA\\Xwiki\\SettingsManager') === true) {
				return 'xwiki-app';
			}
		} catch (Throwable $e) {
			                          
		}

		return 'direct-url';
	}                         

	   
                             
   
               
    
	private function getCacheTtl(): int {
		$raw = (int)$this->appConfig->getValueString(Application::APP_ID, 'xwiki_cache_ttl', (string)self::DEFAULT_CACHE_TTL);
		if ($raw > 0) {
			return $raw;
		}

		return self::DEFAULT_CACHE_TTL;
	}                   

	   
                             
   
                                            
                                  
   
                  
    
	private function makeCacheKey(string $bucket, array $parts): string {
		return $bucket . ':' . sha1(implode('|', array_map('strval', $parts)));
	}                    

	   
                                                 
   
                                 
   
                      
    
	private function fromCache(string $key): ?array {
		if ($this->cache === null) {
			$this->cache = $this->cacheFactory->createDistributed('pipelinq-xwiki');
		}

		$raw = $this->cache->get($key);
		if (is_string($raw) === true) {
			$decoded = json_decode($raw, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}

			return null;
		}

		if (is_array($raw) === true) {
			return $raw;
		}

		return null;
	}                 

	   
                   
   
                                 
                                       
   
                
    
	private function toCache(string $key, array $value): void {
		if ($this->cache === null) {
			$this->cache = $this->cacheFactory->createDistributed('pipelinq-xwiki');
		}

		$this->cache->set($key, json_encode($value), $this->getCacheTtl());
	}               

	   
                                                                     
                                                                            
   
                                    
   
                  
    
	private function fetchXml(string $url): string {
		try {
			$client = $this->clientService->newClient();
			$response = $client->get(
				$url,
				[
					'timeout' => 10,
					'connect_timeout' => 3,
					'headers' => ['Accept' => 'application/xml'],
				]
			);
			return (string)$response->getBody();
		} catch (Throwable $e) {
			$this->logger->debug('xWiki XML fetch failed', ['url' => $url, 'exception' => $e]);
			return '';
		}
	}                

	   
                                       
   
                                    
   
                  
    
	private function fetchRaw(string $url): string {
		try {
			$client = $this->clientService->newClient();
			$response = $client->get(
				$url,
				[
					'timeout' => 10,
					'connect_timeout' => 3,
				]
			);
			return (string)$response->getBody();
		} catch (Throwable $e) {
			$this->logger->debug('xWiki raw fetch failed', ['url' => $url, 'exception' => $e]);
			return '';
		}
	}                

	   
                                                 
   
                                    
   
                                            
   
                                                         
    
	public function parseSearchResults(string $xml): array {
		if ($xml === '') {
			return [];
		}

		$previous = libxml_use_internal_errors(true);
		try {
			$doc = simplexml_load_string($xml);
			if ($doc === false) {
				return [];
			}

			$results = [];
			foreach ($doc->xpath('//searchResult') as $node) {
				$results[] = $this->resultNodeToArray(node: $node);
			}

			return $results;
		} finally {
			libxml_use_internal_errors($previous);
		}
	}                          

	   
                                     
   
                               
                                                             
   
                                            
   
                                                         
    
	public function parsePageSummaries(string $xml, string $space): array {
		if ($xml === '') {
			return [];
		}

		$previous = libxml_use_internal_errors(true);
		try {
			$doc = simplexml_load_string($xml);
			if ($doc === false) {
				return [];
			}

			$results = [];
			foreach ($doc->xpath('//pageSummary') as $node) {
				$item = [
					'id' => (string)($node->id ?? ''),
					'title' => (string)($node->title ?? ''),
					'space' => $space,
					'modified' => (string)($node->modified ?? ''),
					'url' => (string)($node->xwikiAbsoluteUrl ?? ''),
					'tags' => [],
				];
				$results[] = $item;
			}

			return $results;
		} finally {
			libxml_use_internal_errors($previous);
		}         
	}                          

	   
                                    
   
                               
   
                                
   
                                                         
    
	public function parsePage(string $xml): array {
		if ($xml === '') {
			return ['title' => '', 'modified' => '', 'id' => '', 'space' => '', 'content' => '', 'url' => ''];
		}

		$previous = libxml_use_internal_errors(true);
		try {
			$doc = simplexml_load_string($xml);
			if ($doc === false) {
				return ['title' => '', 'modified' => '', 'id' => '', 'space' => '', 'content' => '', 'url' => ''];
			}

			return [
				'id' => (string)($doc->id ?? ''),
				'title' => (string)($doc->title ?? ''),
				'modified' => (string)($doc->modified ?? ''),
				'space' => (string)($doc->space ?? ''),
				'content' => '',
				'url' => (string)($doc->xwikiAbsoluteUrl ?? ''),
			];
		} finally {
			libxml_use_internal_errors($previous);
		}
	}                 

	   
                                                                               
   
                                      
   
                                           
   
                                                         
    
	public function extractAndSanitiseHtml(string $html): string {
		if ($html === '') {
			return '';
		}

		$fragment = $html;
		$pattern = '#<div[^>]*id=["\']xwikicontent["\'][^>]*>(.*?)</div>\s*'
			. '(?:<!--\s*end\s*xwikicontent|<div[^>]*id=["\']xwikidocfooter)#is';
		if (preg_match($pattern, $html, $matches) === 1) {
			$fragment = (string)$matches[1];
		}

		return $this->sanitiseHtml(html: $fragment);
	}                              

	   
                                                                             
                                                                           
                    
   
                                 
   
                  
   
                                                         
    
	public function sanitiseHtml(string $html): string {
		if ($html === '') {
			return '';
		}

		                                                     
		$clean = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? '';

		                                              
		$clean = preg_replace('#\s+on[a-z]+\s*=\s*"[^"]*"#i', '', $clean) ?? $clean;
		$clean = preg_replace("#\\s+on[a-z]+\\s*=\\s*'[^']*'#i", '', $clean) ?? $clean;

		                               
		$clean = preg_replace('#(href|src)\s*=\s*"\s*javascript:[^"]*"#i', '$1="#"', $clean) ?? $clean;
		$clean = preg_replace("#(href|src)\\s*=\\s*'\\s*javascript:[^']*'#i", '$1="#"', $clean) ?? $clean;

		return $clean;
	}                    

	   
                                   
   
                            
                              
   
                                
    
	private function emptyResult(int $limit, int $offset): array {
		return ['results' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
	}                   

	   
                                                         
   
                                                      
   
                                
    
	private function resultNodeToArray(\SimpleXMLElement $node): array {
		$space = (string)($node->space ?? '');
		$title = (string)($node->title ?? '');
		$id = (string)($node->id ?? '');
		if ($title === '' && $id !== '') {
			$title = $id;
		}

		return [
			'id' => $id,
			'title' => $title,
			'space' => $space,
			'modified' => (string)($node->modified ?? ''),
			'url' => (string)($node->pageUrl ?? $node->xwikiAbsoluteUrl ?? ''),
			'tags' => [],
		];
	}                         
}           
