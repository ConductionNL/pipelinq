<?php

   
                              
  
                                                                              
                             
  
                                                                         
                                                                               
                                                                            
                                                                             
                           
                                                                            
                                                     
  
                                                                              
                                                                               
                                                                              
                                                                      
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Search;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Throwable;

   
                                                                      
  
                                                                                                                                                                   
   
class HtmlTextExtractor {

	   
                                                              
   
                           
    
	private const IGNORED = ['script', 'style', 'noscript', 'template'];

	   
                                                                         
   
                                     
   
                                                                 
   
                                                                                                                                                                    
    
	public function headline(string $html): string {
		$xpath = $this->xpath(html: $html);
		if ($xpath === null) {
			return '';
		}

		$parts = [];
		foreach (['//title', '//h1', '//h2', '//h3'] as $query) {
			$nodes = $xpath->query($query);
			if ($nodes === false) {
				continue;
			}

			foreach ($nodes as $node) {
				$text = $this->textOf(node: $node);
				if ($text !== '') {
					$parts[] = $text;
				}
			}
		}

		return implode(' ', $parts);
	}                

	   
                                                                
   
                                                                         
                                                                       
                                                                         
                                
   
                                     
                                             
   
                                                                                     
   
                                                                                                                                                                               
    
	public function fragment(string $html, string $selector): ?string {
		$xpath = $this->xpath(html: $html);
		$expression = $this->toXPath(selector: $selector);
		if ($xpath === null || $expression === null) {
			return null;
		}

		$nodes = $xpath->query($expression);
		if ($nodes === false || $nodes->length === 0) {
			return null;
		}

		return $this->textOf(node: $nodes->item(0));
	}                

	   
                                                                
   
                                             
   
                                                                                   
   
                                                                                                                                                                               
    
	public function toXPath(string $selector): ?string {
		$parts = preg_split('/\s+/', trim($selector));
		if (is_array($parts) === false || $parts === [] || $parts === ['']) {
			return null;
		}

		$expression = '';
		foreach ($parts as $part) {
			$step = $this->step(part: $part);
			if ($step === null) {
				return null;
			}

			$expression .= ('//' . $step);
		}

		return $expression;
	}               

	   
                                                            
   
                                                                  
   
                                                                
    
	private function step(string $part): ?string {
		if (preg_match('/^([a-zA-Z][a-zA-Z0-9-]*)?(#[A-Za-z0-9_-]+)?((?:\.[A-Za-z0-9_-]+)*)$/', $part, $matches) !== 1) {
			return null;
		}

		                                                                   
		                                                          
		$tag = $matches[1];
		$id = $matches[2];
		$classes = $matches[3];
		if ($tag === '' && $id === '' && $classes === '') {
			return null;
		}

		$test = '*';
		if ($tag !== '') {
			$test = strtolower($tag);
		}

		if ($id !== '') {
			$test .= ('[@id=' . $this->literal(value: substr($id, 1)) . ']');
		}

		foreach (array_filter(explode('.', ltrim($classes, '.'))) as $class) {
			$test .= ('[contains(concat(" ", normalize-space(@class), " "), ' . $this->literal(value: (' ' . $class . ' ')) . ')]');
		}

		return $test;
	}            

	   
                                                               
   
                                   
   
                               
    
	private function literal(string $value): string {
		if (str_contains($value, '"') === false) {
			return ('"' . $value . '"');
		}

		return ("concat('" . str_replace('"', "', '\"', '", $value) . "')");
	}               

	   
                                                                         
   
                                    
   
                            
    
	private function textOf(mixed $node): string {
		if ($node instanceof DOMElement === false) {
			if (is_object($node) === true && property_exists($node, 'textContent') === true) {
				return $this->collapse(value: (string)$node->textContent);
			}

			return '';
		}

		$clone = $node->cloneNode(true);
		if ($clone instanceof DOMElement === true) {
			foreach (self::IGNORED as $tag) {
				$found = $clone->getElementsByTagName($tag);
				for ($index = ($found->length - 1); $index >= 0; $index--) {
					$unwanted = $found->item($index);
					if ($unwanted !== null && $unwanted->parentNode !== null) {
						$unwanted->parentNode->removeChild($unwanted);
					}
				}
			}

			return $this->collapse(value: (string)$clone->textContent);
		}

		return $this->collapse(value: (string)$node->textContent);
	}              

	   
                                 
   
                                  
   
                  
    
	private function collapse(string $value): string {
		$collapsed = preg_replace('/\s+/u', ' ', $value);
		if (is_string($collapsed) === false) {
			$collapsed = $value;
		}

		return trim($collapsed);
	}                

	   
                                                             
   
                                     
   
                                                                  
    
	private function xpath(string $html): ?DOMXPath {
		if (trim($html) === '') {
			return null;
		}

		$previous = libxml_use_internal_errors(true);
		try {
			$document = new DOMDocument();
			$loaded = $document->loadHTML(
				('<?xml encoding="UTF-8">' . $html),
				(LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)
			);
			if ($loaded === false) {
				return null;
			}

			return new DOMXPath($document);
		} catch (Throwable) {
			return null;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
	}             
}           
