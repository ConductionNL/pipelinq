<?php

   
                           
  
                                                                             
                                                                      
  
                                                                             
                                                                          
                                                                             
                                                                          
                                                                             
                                                                              
                                                                               
                                        
  
                                                                              
                                                                              
                                                                      
                                                                            
                                                    
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Marketing\ListObjectStore;

   
                                                                        
  
                                                                                                                                                       
  
                                                                           
                                                                           
                                                                           
                                                                              
                                                                             
                                                                             
                                                                     
                                                                             
                                                                          
                                                                         
                                                                       
                                       
   
class ArticleService {
	   
                                                                       
   
               
    
	public const ARTICLES_MARKER = '{{articles}}';

	   
                                           
   
               
    
	public const FORMAT_HTML = 'html';

	   
                                                
   
               
    
	public const FORMAT_TEXT = 'text';

	   
                                                          
   
                                                                        
                                                                    
                         
   
                              
    
	private const READ_MORE_LABELS = [
		'en' => 'Read more',
		'nl' => 'Lees verder',
		'de' => 'Weiterlesen',
		'fr' => 'Lire la suite',
	];

	   
                                                                
   
               
    
	private const DEFAULT_ARTICLE_SCHEMA_SLUG = 'article';

	   
                                         
   
               
    
	private const DEFAULT_TEMPLATE_SCHEMA_SLUG = 'campaignTemplate';

	   
                              
   
               
    
	private const DEFAULT_BLAST_SCHEMA_SLUG = 'blast';

	   
                                                                
   
                           
    
	private const STATUSES = ['draft', 'review', 'published', 'archived'];

	   
                                                                        
                                                 
   
                                                                         
                                                                           
                               
   
                                                                   
    
	private const TRANSITIONS = [
		'submitForReview' => ['from' => ['draft'], 'to' => 'review'],
		'returnToDraft' => ['from' => ['review'], 'to' => 'draft'],
		'publish' => ['from' => ['draft', 'review'], 'to' => 'published'],
		'archive' => ['from' => ['draft', 'review', 'published'], 'to' => 'archived'],
		'restore' => ['from' => ['archived'], 'to' => 'draft'],
	];

	   
                                                                        
                                                                          
                                                            
   
                           
    
	private const WRITABLE_FIELDS = [
		'title',
		'slug',
		'summary',
		'body',
		'heroImage',
		'language',
		'portalPageRef',
	];

	   
                
   
                                                                
   
                                                                                                                                                        
    
	public function __construct(
		private ListObjectStore $store,
	) {
	}                   

	   
                                                           
   
                                                           
                                                 
   
                                                                                                                           
   
                                                                                                                                                        
    
	public function listArticles(int $page, int $limit): array {
		$page = max(1, $page);
		$limit = min(100, max(1, $limit));
		$all = $this->store->findAll(schemaSlug: $this->articleSchemaSlug());
		usort(
			$all,
			static fn (array $left, array $right): int => strcmp(
				(string)($right['createdAt'] ?? ''),
				(string)($left['createdAt'] ?? ''),
			)
		);
		$total = count($all);

		return [
			'data' => array_slice($all, (($page - 1) * $limit), $limit),
			'pagination' => [
				'page' => $page,
				'limit' => $limit,
				'total' => $total,
				'pages' => (int)max(1, (int)ceil(($total / $limit))),
			],
		];
	}                    

	   
                                      
   
                                                  
   
                                                                                  
   
                                                                                                                                                        
    
	public function getArticleById(string $articleId): ?array {
		return $this->store->find(schemaSlug: $this->articleSchemaSlug(), id: $articleId);
	}                      

	   
                                 
   
                                                                          
                                                             
                                                                        
                                                                   
   
                                                         
                                                   
                                                                                     
   
                                                                 
   
                                                                                                                                                        
    
	public function createArticle(array $payload, string $authorUid, string $agent = ''): array {
		$clean = $this->sanitise(payload: $payload);
		$title = trim((string)($clean['title'] ?? ''));
		if ($title === '') {
			return ['error' => 'A title is required'];
		}

		$slug = $this->resolveSlug(candidate: (string)($clean['slug'] ?? ''), title: $title);
		if ($this->slugTaken(slug: $slug, exceptId: '') === true) {
			return ['error' => 'That slug is already in use'];
		}

		$now = $this->nowIso();
		$record = array_merge(
			$clean,
			[
				'title' => $title,
				'slug' => $slug,
				'status' => 'draft',
				'author' => $authorUid,
				'agentAuthored' => ($agent !== ''),
				'agentAuthoredBy' => $agent,
				'createdAt' => $now,
				'updatedAt' => $now,
			]
		);

		$saved = $this->store->save(schemaSlug: $this->articleSchemaSlug(), payload: $record);
		if ($saved === null) {
			return ['error' => 'The article could not be saved'];
		}

		return ['article' => $saved];
	}                     

	   
                                       
   
                                                  
                                                        
                                                                                     
   
                                                                 
   
                                                                                                                                                        
    
	public function updateArticle(string $articleId, array $patch, string $agent = ''): array {
		$existing = $this->getArticleById(articleId: $articleId);
		if ($existing === null) {
			return ['error' => 'Not found'];
		}

		$clean = $this->sanitise(payload: $patch);
		if (isset($clean['title']) === true && trim((string)$clean['title']) === '') {
			return ['error' => 'A title is required'];
		}

		if (isset($clean['slug']) === true) {
			$slug = $this->resolveSlug(
				candidate: (string)$clean['slug'],
				title: (string)($clean['title'] ?? ($existing['title'] ?? '')),
			);
			if ($this->slugTaken(slug: $slug, exceptId: $this->store->idOf(payload: $existing)) === true) {
				return ['error' => 'That slug is already in use'];
			}

			$clean['slug'] = $slug;
		}

		$merged = array_merge($existing, $clean);
		$merged['updatedAt'] = $this->nowIso();
		$merged['agentAuthored'] = ($agent !== '');
		$merged['agentAuthoredBy'] = $agent;

		$saved = $this->store->save(
			schemaSlug: $this->articleSchemaSlug(),
			payload: $merged,
			id: $this->store->idOf(payload: $existing),
		);
		if ($saved === null) {
			return ['error' => 'The article could not be saved'];
		}

		return ['article' => $saved];
	}                     

	   
                                                                         
   
                                                  
   
                                                                 
   
                                                                                                                                           
    
	public function publishArticle(string $articleId): array {
		return $this->applyTransition(articleId: $articleId, transition: 'publish');
	}                      

	   
                                                                       
   
                                                  
   
                                                                 
   
                                                                                                                                           
    
	public function archiveArticle(string $articleId): array {
		return $this->applyTransition(articleId: $articleId, transition: 'archive');
	}                      

	   
                                                
   
                                                                          
                                                                        
                                                     
   
                                                  
                                                                          
   
                                                                 
   
                                                                                                                                           
    
	public function applyTransition(string $articleId, string $transition): array {
		$rule = (self::TRANSITIONS[$transition] ?? null);
		if ($rule === null) {
			return ['error' => 'Unknown transition'];
		}

		$existing = $this->getArticleById(articleId: $articleId);
		if ($existing === null) {
			return ['error' => 'Not found'];
		}

		$status = (string)($existing['status'] ?? 'draft');
		if (in_array($status, self::STATUSES, true) === false) {
			$status = 'draft';
		}

		if (in_array($status, $rule['from'], true) === false) {
			return ['error' => 'That change is not allowed from the current status'];
		}

		$merged = $existing;
		$merged['status'] = $rule['to'];
		$merged['updatedAt'] = $this->nowIso();
		if ($rule['to'] === 'published' && trim((string)($existing['publishedAt'] ?? '')) === '') {
			$merged['publishedAt'] = $merged['updatedAt'];
		}

		$saved = $this->store->save(
			schemaSlug: $this->articleSchemaSlug(),
			payload: $merged,
			id: $this->store->idOf(payload: $existing),
		);
		if ($saved === null) {
			return ['error' => 'The article could not be saved'];
		}

		return ['article' => $saved];
	}                       

	   
                                                         
   
                                                                  
                                                                            
                                                                         
                                                      
   
                                                  
   
                                                                                                                              
   
                                                                                                                                       
    
	public function listUsages(string $articleId): array {
		$article = $this->getArticleById(articleId: $articleId);
		if ($article === null) {
			return ['data' => [], 'counts' => ['template' => 0, 'blast' => 0]];
		}

		$identities = $this->identitiesOf(object: $article);
		$usages = [];
		$templateIds = [];
		foreach ($this->store->findAll(schemaSlug: $this->templateSchemaSlug()) as $template) {
			if ($this->namesArticle(template: $template, identities: $identities) === false) {
				continue;
			}

			$templateId = $this->store->idOf(payload: $template);
			$templateIds[] = $templateId;
			foreach ($this->identitiesOf(object: $template) as $identity) {
				$templateIds[] = $identity;
			}

			$usages[] = [
				'kind' => 'template',
				'id' => $templateId,
				'name' => (string)($template['name'] ?? ''),
				'status' => (string)($template['channel'] ?? ''),
			];
		}

		foreach ($this->store->findAll(schemaSlug: $this->blastSchemaSlug()) as $blast) {
			if (in_array((string)($blast['templateId'] ?? ''), $templateIds, true) === false) {
				continue;
			}

			$usages[] = [
				'kind' => 'blast',
				'id' => $this->store->idOf(payload: $blast),
				'name' => (string)($blast['name'] ?? ''),
				'status' => (string)($blast['status'] ?? ''),
			];
		}

		return [
			'data' => $usages,
			'counts' => [
				'template' => count(array_filter($usages, static fn (array $usage): bool => $usage['kind'] === 'template')),
				'blast' => count(array_filter($usages, static fn (array $usage): bool => $usage['kind'] === 'blast')),
			],
		];
	}                  

	   
                                                                   
   
                                                                       
                                                                       
   
                                                                
   
                                                                   
   
                                                                                                                                 
    
	public function loadArticlesByIds(array $articleIds): array {
		$out = [];
		foreach ($articleIds as $id) {
			if (is_scalar($id) === false || (string)$id === '') {
				continue;
			}

			$article = $this->getArticleById(articleId: (string)$id);
			if ($article !== null) {
				$out[] = $article;
			}
		}

		return $out;
	}                         

	   
                                                                           
   
                                                                       
                                                         
   
                                          
                                                                              
                                                                               
   
                                                     
   
                                                                                                                                 
    
	public function expandArticlesMarker(string $body, array $articles, string $format): string {
		if (str_contains($body, self::ARTICLES_MARKER) === false) {
			return $body;
		}

		return str_replace(
			self::ARTICLES_MARKER,
			$this->renderArticlesBlock(articles: $articles, format: $format),
			$body,
		);
	}                            

	   
                                                   
   
                                                                     
                                                                          
                                                                         
                                                                           
                                                                         
                                         
   
                                                                              
                                                                               
   
                                                                        
   
                                                                                                                                 
    
	public function renderArticlesBlock(array $articles, string $format): string {
		$parts = [];
		foreach ($articles as $article) {
			if (is_array($article) === false) {
				continue;
			}

			if ($format === self::FORMAT_TEXT) {
				$parts[] = $this->renderArticleText(article: $article);
				continue;
			}

			$parts[] = $this->renderArticleHtml(article: $article);
		}

		if ($parts === []) {
			return '';
		}

		if ($format === self::FORMAT_TEXT) {
			return implode("\n\n", $parts);
		}

		return '<div class="pipelinq-articles">' . implode('', $parts) . '</div>';
	}                           

	   
                                                                
   
                                                     
   
                                      
    
	private function renderArticleHtml(array $article): string {
		$title = $this->escape(value: (string)($article['title'] ?? ''));
		$summary = $this->escape(value: (string)($article['summary'] ?? ''));
		$html = '<div class="pipelinq-article">';

		$hero = $this->mailableHero(article: $article);
		if ($hero !== '') {
			$html .= '<img src="' . $this->escape(value: $hero) . '" alt="' . $title . '" />';
		}

		$html .= '<h2>' . $title . '</h2>';
		if ($summary !== '') {
			$html .= '<p>' . $summary . '</p>';
		}

		$link = trim((string)($article['portalPageRef'] ?? ''));
		if ($link !== '') {
			$label = $this->escape(value: $this->readMoreLabel(article: $article));
			$html .= '<p><a href="' . $this->escape(value: $link) . '">' . $label . '</a></p>';
		}

		return ($html . '</div>');
	}                         

	   
                                     
   
                                                     
   
                                            
    
	private function renderArticleText(array $article): string {
		$lines = [trim((string)($article['title'] ?? ''))];
		$summary = trim((string)($article['summary'] ?? ''));
		if ($summary !== '') {
			$lines[] = $summary;
		}

		$link = trim((string)($article['portalPageRef'] ?? ''));
		if ($link !== '') {
			$lines[] = ($this->readMoreLabel(article: $article) . ': ' . $link);
		}

		return implode("\n", $lines);
	}                         

	   
                                                      
   
                                                                        
                                                                            
                                                                           
                                                                            
                                       
   
                                                     
   
                             
    
	private function readMoreLabel(array $article): string {
		$language = strtolower(substr(trim((string)($article['language'] ?? '')), 0, 2));

		return (self::READ_MORE_LABELS[$language] ?? self::READ_MORE_LABELS['en']);
	}                     

	   
                                                                       
   
                                                     
   
                                                               
    
	private function mailableHero(array $article): string {
		$hero = trim((string)($article['heroImage'] ?? ''));
		if (str_starts_with($hero, 'https://') === true || str_starts_with($hero, 'http://') === true) {
			return $hero;
		}

		return '';
	}                    

	   
                                        
   
                                       
   
                                     
    
	private function escape(string $value): string {
		return htmlspecialchars($value, (ENT_QUOTES | ENT_SUBSTITUTE), 'UTF-8');
	}              

	   
                                                                          
   
                                                                
                                                                      
   
                                                          
    
	private function namesArticle(array $template, array $identities): bool {
		$ids = ($template['articleIds'] ?? []);
		if (is_array($ids) === false) {
			return false;
		}

		foreach ($ids as $id) {
			if (is_scalar($id) === true && in_array((string)$id, $identities, true) === true) {
				return true;
			}
		}

		return false;
	}                    

	   
                                                                         
                                       
   
                                                                           
                                                                       
                                                                           
   
                                                   
   
                                                     
    
	private function identitiesOf(array $object): array {
		$out = [];
		$self = ($object['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		foreach (['uuid', 'id', 'slug'] as $key) {
			foreach ([$object, $self] as $source) {
				$value = ($source[$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					$out[] = (string)$value;
				}
			}
		}

		return array_values(array_unique($out));
	}                    

	   
                                          
   
                                                         
   
                                                              
    
	private function sanitise(array $payload): array {
		$clean = [];
		foreach (self::WRITABLE_FIELDS as $key) {
			if (array_key_exists($key, $payload) === false) {
				continue;
			}

			$clean[$key] = trim((string)$payload[$key]);
		}

		foreach (['links', 'tags'] as $key) {
			if (isset($payload[$key]) === true && is_array($payload[$key]) === true) {
				$clean[$key] = $payload[$key];
			}
		}

		return $clean;
	}                

	   
                                                                        
                   
   
                                                               
                                           
   
                                   
    
	private function resolveSlug(string $candidate, string $title): string {
		$slug = $this->slugify(value: $candidate);
		if ($slug !== '') {
			return $slug;
		}

		$slug = $this->slugify(value: $title);
		if ($slug !== '') {
			return $slug;
		}

		return ('article-' . substr(sha1($title . microtime(true)), 0, 8));
	}                   

	   
                                                 
   
                                                                         
                                                                        
                                                                          
                                                         
   
                                       
   
                                                                
    
	private function slugify(string $value): string {
		$ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $value);
		if ($ascii === false) {
			$ascii = $value;
		}

		$lower = strtolower($ascii);
		$dashed = preg_replace('/[^a-z0-9]+/', '-', $lower);
		if (is_string($dashed) === false) {
			return '';
		}

		return trim($dashed, '-');
	}               

	   
                                                 
   
                                                                         
                                                                          
                                                              
   
                                          
                                                                   
   
                                             
    
	private function slugTaken(string $slug, string $exceptId): bool {
		foreach ($this->store->findAll(schemaSlug: $this->articleSchemaSlug()) as $row) {
			if ((string)($row['slug'] ?? '') !== $slug) {
				continue;
			}

			if ($exceptId !== '' && $this->store->idOf(payload: $row) === $exceptId) {
				continue;
			}

			return true;
		}

		return false;
	}                 

	   
                            
   
                               
    
	private function articleSchemaSlug(): string {
		return $this->store->schemaSlug(
			configKey: 'article_schema',
			default: self::DEFAULT_ARTICLE_SCHEMA_SLUG,
		);
	}                         

	   
                                     
   
                               
    
	private function templateSchemaSlug(): string {
		return $this->store->schemaSlug(
			configKey: 'campaignTemplate_schema',
			default: self::DEFAULT_TEMPLATE_SCHEMA_SLUG,
		);
	}                          

	   
                          
   
                               
    
	private function blastSchemaSlug(): string {
		return $this->store->schemaSlug(
			configKey: 'blast_schema',
			default: self::DEFAULT_BLAST_SCHEMA_SLUG,
		);
	}                       

	   
                                                 
   
                             
    
	private function nowIso(): string {
		return gmdate('c');
	}              
}           
