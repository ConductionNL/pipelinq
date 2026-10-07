<?php

   
                                  
  
                                                               
                                                                         
                                                                        
                                                             
                                                                         
                                                                 
                                                                        
                 
  
                                                                         
                                                                          
                                                                         
                                                                    
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;

   
                                                                        
  
                                                                                                                                                       
   
class CampaignLinkDecorator {

	   
                                                                       
                                                                     
   
               
    
	public const AUTO_CONFIG_KEY = 'blast.utm_auto';

	   
                                                                 
   
                           
    
	private const SKIPPED_SCHEMES = ['mailto:', 'tel:', 'sms:', 'javascript:', 'data:'];

	   
                                           
   
            
    
	private const SLUG_MAX_LENGTH = 80;

	   
                                                                      
   
               
    
	private const CHANNEL_DEFAULT = 'email';

	   
                
   
                                                     
   
                                                                                                                                                        
    
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}                   

	   
                                                      
   
                                                         
   
                                                                                                                                                        
    
	public function isEnabled(): bool {
		$raw = strtolower(trim($this->appConfig->getValueString(Application::APP_ID, self::AUTO_CONFIG_KEY, 'true')));
		return in_array($raw, ['false', '0', 'no', 'off'], true) === false;
	}                 

	   
                                                                       
                                                                        
                                                  
   
                                                     
                                                                                  
                                                                                                 
   
                                                                   
   
                                                                                                                                                         
    
	public function campaignFor(array $blast, array $template = [], array $campaign = []): string {
		$candidates = [
			(string)($campaign['utmCampaign'] ?? ''),
			(string)($blast['name'] ?? ''),
			(string)($template['name'] ?? ''),
			$this->blastId(blast: $blast),
		];
		foreach ($candidates as $candidate) {
			$slug = self::slugify(value: $candidate);
			if ($slug !== '') {
				return $slug;
			}
		}

		return '';
	}                   

	   
                                             
   
                                                                    
                                                                  
                                                                    
                                                                   
   
                                                                      
                                                                
   
                                                     
                                                                                  
                                                                                                 
   
                                                                                            
   
                                                                                                                                                         
    
	public function utmFor(array $blast, array $template = [], array $campaign = []): array {
		return [
			'utm_source' => $this->fromCampaign(campaign: $campaign, key: 'utmSource', fallback: self::CHANNEL_DEFAULT),
			'utm_medium' => $this->fromCampaign(campaign: $campaign, key: 'utmMedium', fallback: self::CHANNEL_DEFAULT),
			'utm_campaign' => $this->campaignFor(blast: $blast, template: $template, campaign: $campaign),
			'utm_content' => $this->blastId(blast: $blast),
		];
	}              

	   
                                                           
   
                                                                         
                                                               
                                                                 
   
                             
    
	private function fromCampaign(array $campaign, string $key, string $fallback): string {
		$value = strtolower(trim((string)($campaign[$key] ?? '')));
		if ($value !== '') {
			return $value;
		}

		return $fallback;
	}                    

	   
                                                       
   
                                                      
                                                     
                                                                                  
                                                                                                 
   
                                                                                    
   
                                                                                                                                                         
    
	public function decorate(string $html, array $blast, array $template = [], array $campaign = []): string {
		if ($html === '' || $this->isEnabled() === false) {
			return $html;
		}

		$utm = array_filter(
			$this->utmFor(blast: $blast, template: $template, campaign: $campaign),
			static fn (string $value): bool => $value !== ''
		);
		if ($utm === []) {
			return $html;
		}

		$rewritten = preg_replace_callback(
			'/<a\b([^>]*?)\shref=(["\'])(.*?)\2([^>]*)>/i',
			function (array $matches) use ($utm): string {
				return $this->rewriteAnchor(matches: $matches, utm: $utm);
			},
			$html
		);

		if (is_string($rewritten) === false) {
			return $html;
		}

		return $rewritten;
	}                

	   
                                                                
   
                                                                      
                                                                        
   
                                                                         
                                                                        
   
                                                                        
   
                                                                                                                                                        
    
	public function decorateUrl(string $url, array $utm): ?string {
		$trimmed = trim($url);
		if ($this->isDecoratable(url: $trimmed) === false) {
			return null;
		}

		[$base, $fragment] = $this->splitOnce(value: $trimmed, separator: '#');
		[$path, $query] = $this->splitOnce(value: $base, separator: '?');

		$additions = $this->missingParameters(query: $query, utm: $utm);
		if ($additions === []) {
			return null;
		}

		$parts = array_filter([$query, implode('&', $additions)], static fn (string $part): bool => $part !== '');
		$result = ($path . '?' . implode('&', $parts));
		if ($fragment !== '') {
			$result .= ('#' . $fragment);
		}

		return $result;
	}                   

	   
                                                                        
                                                 
   
                                    
   
                                                         
   
                                                                                                                                                          
    
	public function slug(string $value): string {
		return self::slugify(value: $value);
	}            

	   
                                                              
   
                                    
   
                                                         
   
                                                                                                                                                        
    
	public static function slugify(string $value): string {
		$clean = $value;
		if (preg_match('//u', $clean) !== 1) {
			$clean = (string)preg_replace('/[\x80-\xFF]/', '', $clean);
		}

		$ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $clean);
		if ($ascii === false) {
			$ascii = $clean;
		}

		$slug = (string)preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii));
		$slug = trim($slug, '-');

		return substr($slug, 0, self::SLUG_MAX_LENGTH);
	}               

	   
                                                         
   
                                                                                                                      
                                                                        
   
                                                           
    
	private function rewriteAnchor(array $matches, array $utm): string {
		$decorated = $this->decorateUrl(url: html_entity_decode($matches[3], (ENT_QUOTES | ENT_HTML5)), utm: $utm);
		if ($decorated === null) {
			return $matches[0];
		}

		$escaped = str_replace('&', '&amp;', $decorated);

		return ('<a' . $matches[1] . ' href=' . $matches[2] . $escaped . $matches[2] . $matches[4] . '>');
	}                     

	   
                                                         
   
                                        
   
                                                                          
    
	private function isDecoratable(string $url): bool {
		if ($url === '' || str_starts_with($url, '#') === true || str_contains($url, '{{') === true) {
			return false;
		}

		$lower = strtolower($url);
		foreach (self::SKIPPED_SCHEMES as $scheme) {
			if (str_starts_with($lower, $scheme) === true) {
				return false;
			}
		}

		$hasScheme = (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1);

		return $hasScheme === false || preg_match('/^https?:/i', $url) === 1;
	}                     

	   
                                                                     
                                                             
   
                                                                 
                                                            
   
                                                                    
    
	private function missingParameters(string $query, array $utm): array {
		$present = [];
		foreach (explode('&', $query) as $pair) {
			if ($pair === '') {
				continue;
			}

			$present[strtolower(urldecode((string)strtok($pair, '=')))] = true;
		}

		$additions = [];
		foreach ($utm as $key => $value) {
			if (isset($present[$key]) === false) {
				$additions[] = ($key . '=' . rawurlencode($value));
			}
		}

		return $additions;
	}                         

	   
                                                                     
   
                                                     
   
                                   
    
	private function blastId(array $blast): string {
		$sources = [$blast];
		if (is_array($blast['@self'] ?? null) === true) {
			$sources[] = $blast['@self'];
		}

		foreach ($sources as $source) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				$value = ($source[$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					return (string)$value;
				}
			}
		}

		return '';
	}               

	   
                                                          
   
                                    
                                           
   
                                                                               
    
	private function splitOnce(string $value, string $separator): array {
		$position = strpos($value, $separator);
		if ($position === false) {
			return [$value, ''];
		}

		return [substr($value, 0, $position), substr($value, ($position + 1))];
	}                 
}           
