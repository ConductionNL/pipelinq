<?php

   
                                  
  
                                                                              
                                                                             
                                                                          
                                                          
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                     
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                
  
                                                                             
                                                                                
                                                                             
                                                                                
                                                                                 
                                                    
  
                                                                           
                                                                         
                       
                                                                             
                                                                               
                                                                        
                                                                              
                                                                                
  
                                                     
   
class ProductCatalogService {
	   
                                                  
   
                           
    
	public const BTW_CLASS_RATES = [
		'high' => 21,
		'low' => 9,
		'zero' => 0,
		'exempt' => 0,
	];

	   
                
   
                                                
                                              
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                               
   
                                                                    
                                                                          
                                                                
   
                                                                           
   
                                              
   
                                                      
                                                                              
    
	public function btwClassToRate(?string $vatClass): int|float {
		                                                                     
		                                                                       
		$rates = (new VatRates(appConfig: $this->appConfig))->rates();
		$rate = $rates['high'];
		if ($this->isValidBtwClass(vatClass: $vatClass) === true) {
			$rate = $rates[$vatClass];
		}

		                                                                   
		                             
		if ($rate === floor($rate)) {
			return (int)$rate;
		}

		return $rate;
	}                      

	   
                                                         
   
                                                         
   
                                            
   
                                                      
    
	public function isValidBtwClass(?string $vatClass): bool {
		return $vatClass !== null && isset(self::BTW_CLASS_RATES[$vatClass]) === true;
	}                       

	   
                                                          
   
                                                                             
                                                         
   
                                                                                      
   
                                                                       
   
                                                      
    
	public function sortPriceTiers(array $tiers): array {
		$clean = [];
		foreach ($tiers as $tier) {
			if (is_array($tier) === false) {
				continue;
			}

			$minQuantity = (int)($tier['minQuantity'] ?? 0);
			if ($minQuantity < 1) {
				continue;
			}

			$tier['minQuantity'] = $minQuantity;
			$clean[] = $tier;
		}

		usort(
			$clean,
			static fn (array $a, array $b): int => ($a['minQuantity'] <=> $b['minQuantity'])
		);

		return $clean;
	}                      

	   
                                                                     
   
                                                                     
                                                                           
                    
   
                                                                   
                                                
   
                                                                   
   
                                                      
    
	public function resolveTier(array $tiers, float $quantity): ?array {
		$sorted = $this->sortPriceTiers(tiers: $tiers);
		$selected = null;

		foreach ($sorted as $tier) {
			if ($quantity >= (float)$tier['minQuantity']) {
				$selected = $tier;
				continue;
			}

			break;
		}

		return $selected;
	}                   

	   
                                                                        
   
                     
                                                                        
                                                   
                                                            
                                                                      
                            
   
                                                                  
                               
   
                                                            
                                                                  
                                                        
   
                                                                  
                                                                          
                                         
   
                                                      
    
	public function resolveEffectivePrice(array $product, float $quantity, ?string $variantSku = null): array {
		$quantity = max(0.0, $quantity);
		$basePrice = max(0.0, (float)($product['unitPrice'] ?? 0));
		$source = 'base';
		$tierLabel = '';

		                                                             
		if ($variantSku !== null && $variantSku !== '') {
			[$basePrice, $source] = $this->resolveVariantPrice(
				product: $product,
				sku: $variantSku,
				basePrice: $basePrice
			);
		}

		                                                                  
		$tiers = (array)($product['priceTiers'] ?? []);
		if (count($tiers) > 0 && $variantSku === null) {
			$tier = $this->resolveTier(tiers: $tiers, quantity: $quantity);
			if ($tier !== null) {
				$basePrice = max(0.0, (float)($tier['unitPrice'] ?? $basePrice));
				$source = 'tier';
				$tierLabel = (string)($tier['label'] ?? '');
			}
		}

		$vatClass = (string)($product['vatClass'] ?? '');
		$vatClassOrNull = $vatClass;
		if ($vatClass === '') {
			$vatClassOrNull = null;
		}

		return [
			'unitPrice' => round($basePrice, 2),
			'source' => $source,
			'tierLabel' => $tierLabel,
			'quantity' => $quantity,
			'vatClass' => $vatClass,
			'taxRate' => $this->btwClassToRate(vatClass: $vatClassOrNull),
		];
	}                             

	   
                                                            
   
                                                                           
                                                                 
   
                                                            
                                                 
                                                              
   
                                                                     
   
                                                               
                                                              
   
                                                      
    
	private function resolveVariantPrice(array $product, string $sku, float $basePrice): array {
		$variant = $this->findVariant(product: $product, sku: $sku);
		if ($variant === null) {
			throw new OCSNotFoundException('Variant niet gevonden.');
		}

		if (($variant['status'] ?? 'active') === 'inactive') {
			throw new OCSBadRequestException('Variant is niet beschikbaar.');
		}

		if (isset($variant['unitPrice']) === true) {
			return [max(0.0, (float)$variant['unitPrice']), 'variant'];
		}

		return [$basePrice, 'base'];
	}                           

	   
                                           
   
                                                            
                                       
   
                                                                            
   
                                                      
    
	public function findVariant(array $product, string $sku): ?array {
		foreach (((array)($product['variants'] ?? [])) as $variant) {
			if (is_array($variant) === true && (string)($variant['sku'] ?? '') === $sku) {
				return $variant;
			}
		}

		return null;
	}                   

	   
                                                                         
   
                                                                            
   
                                                                 
   
                                                      
    
	public function variantSkusUnique(array $variants): bool {
		$seen = [];
		foreach ($variants as $variant) {
			$sku = trim((string)(($variant['sku'] ?? '')));
			if ($sku === '' || isset($seen[$sku]) === true) {
				return false;
			}

			$seen[$sku] = true;
		}

		return true;
	}                         

	   
                                    
   
                                                                       
                                                                             
                                                                             
                
   
            
    
	public const BARCODE_MAX_LENGTH = 64;

	   
                                                                   
   
                                                                           
                                                                           
                                                                        
                                                                        
                                                                              
                                                         
   
                                                         
   
                                                                
   
                                                 
    
	public function isValidBarcode(string $barcode): bool {
		if ($barcode === '' || strlen($barcode) > self::BARCODE_MAX_LENGTH) {
			return false;
		}

		return preg_match('/^[A-Za-z0-9 .-]+$/', $barcode) === 1;
	}                      

	   
                                                                         
   
                                                                        
                                                                            
                                                                       
                                                                             
                                                                          
                                                                            
                 
   
                                                                             
                                                           
   
                                                                             
                                                                                                                      
   
                                                 
                                                 
    
	public function matchProductByBarcode(array $products, string $barcode): ?array {
		                                                                  
		foreach ($products as $product) {
			if (is_array($product) === true && (string)($product['barcode'] ?? '') === $barcode) {
				return ['product' => $product, 'variantIndex' => null];
			}
		}

		                                                                         
		                                                                   
		foreach ($products as $product) {
			$variantIndex = $this->matchActiveVariantIndex(product: $product, barcode: $barcode);
			if ($variantIndex !== null) {
				$variant = ((array)($product['variants'] ?? []))[$variantIndex];
				$product['matchedVariantSku'] = (string)($variant['sku'] ?? '');
				$product['matchedVariantIndex'] = $variantIndex;
				return ['product' => $product, 'variantIndex' => $variantIndex];
			}
		}

		return null;
	}                             

	   
                                                                         
                                     
   
                                                                             
                             
   
                                                             
                                                           
   
                                                           
   
                                                 
    
	private function matchActiveVariantIndex(array $product, string $barcode): ?int {
		$index = 0;
		foreach (((array)($product['variants'] ?? [])) as $variant) {
			if (is_array($variant) === true
				&& ($variant['status'] ?? 'active') !== 'inactive'
				&& (string)($variant['barcode'] ?? '') === $barcode
			) {
				return $index;
			}

			$index++;
		}

		return null;
	}                               

	   
                                                                  
   
                                                                             
                                                                            
                                                                        
                                                                              
                                                                            
                                                                             
   
                                                                   
                                                               
   
                                               
   
                                                                    
   
                                                                        
   
                                                      
                                                 
    
	public function lookupByBarcode(string $barcode): ?array {
		$barcode = trim($barcode);
		if ($barcode === '') {
			throw new OCSBadRequestException('Geef een barcode op.');
		}

		if ($this->isValidBarcode(barcode: $barcode) === false) {
			throw new OCSBadRequestException('Ongeldige barcode.');
		}

		[$register, $schema] = $this->config();

		$products = $this->fetchProducts(register: $register, schema: $schema);

		$match = $this->matchProductByBarcode(products: $products, barcode: $barcode);
		if ($match === null) {
			return null;
		}

		return $match['product'];
	}                       

	   
                                                                 
   
                                            
                                                
   
                                                                 
   
                                                      
    
	private function fetchProducts(string $register, string $schema): array {
		try {
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: failed to fetch products for barcode lookup', ['exception' => $e->getMessage()]);
			return [];
		}

		$products = [];
		foreach (($results ?? []) as $result) {
			$products[] = $this->toArray(object: $result);
		}

		return $products;
	}                     

	   
                                                                
   
                                                                   
   
                                                                             
   
                                                      
    
	private function config(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'product_schema', '');

		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('Productregister of -schema is niet geconfigureerd.');
		}

		return [$register, $schema];
	}              

	   
                                       
   
                                      
   
                                                      
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        

	   
                                                                
   
                                       
   
                                                        
    
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return (array)$object;
	}               
}           
