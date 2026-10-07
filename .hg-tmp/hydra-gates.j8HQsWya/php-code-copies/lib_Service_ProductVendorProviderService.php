<?php

   
                                         
  
                                                                               
                                                                               
                                                         
  
                                                                                
                                                                                  
                                                                            
                                                                                 
  
                                                                                 
                                                                                
                                               
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                     
  
                                                                    
                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                                               
  
                                                                                      
             
                                                                                        
                                                                                        
  
                                          
                                                                                        
                                                                                
  
                                                     
   
class ProductVendorProviderService {
	   
                                                                    
                                                
   
               
    
	public const PROVIDER_SLUG = 'pipelinq-product-vendor';

	   
                                                                           
                                                                       
   
                 
    
	private const PRODUCT_PRIVATE_FIELDS = ['cost'];

	   
                
   
                                                       
                                              
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                      
   
                                                                          
                                                                            
                                                            
                                                                
   
                                                                        
                                                
   
                                                                      
                                                                               
                                                                                    
   
                                                                                     
   
                                                                               
                                                                               
                                                                             
                                          
   
                                                      
    
	public function getProduct(string $productId, string $consumerAppSlug = '', bool $authorisedConsumer = false): ?array {
		$productData = $this->fetchObjectBySchemaAndId(
			schema: 'product',
			idField: 'productId',
			idValue: $productId,
		);

		if ($productData === null) {
			$this->logger->warning(
				'ProductVendorProviderService: product not found',
				['productId' => $productId, 'consumer' => $consumerAppSlug]
			);
			return null;
		}

		$isAuthorised = $authorisedConsumer || $this->isAuthorisedForPrivateFields(
			consumerAppSlug: $consumerAppSlug
		);

		return $this->projectProductView(
			product: $productData,
			authorised: $isAuthorised,
		);
	}                  

	   
                                                                    
   
                                                                           
                                                                               
                                                                            
                                                  
   
                                                                     
                                                             
   
                                                                                         
   
                                                      
    
	public function resolveSupplier(string $contactsUid, string $consumerAppSlug = ''): ?array {
		$supplierData = $this->fetchObjectBySchemaAndId(
			schema: 'supplier',
			idField: 'contactsUid',
			idValue: $contactsUid,
		);

		if ($supplierData === null) {
			$this->logger->warning(
				'ProductVendorProviderService: supplier not found',
				['contactsUid' => $contactsUid, 'consumer' => $consumerAppSlug]
			);
			return null;
		}

		                                                                              
		                                                                                 
		return $supplierData;
	}                       

	   
                                                                           
   
                                                                          
                                                                     
   
                                                         
   
                                                                 
    
	private function isAuthorisedForPrivateFields(string $consumerAppSlug): bool {
		if ($consumerAppSlug === '') {
			return false;
		}

		$authorised = $this->appConfig->getValueString(
			Application::APP_ID,
			'product_vendor_cost_consumers',
			''
		);

		if ($authorised === '') {
			return false;
		}

		$slugs = array_map('trim', explode(',', $authorised));
		return in_array($consumerAppSlug, $slugs, true);
	}                                    

	   
                                                                   
                           
   
                                                                           
                                                                                  
   
                                                           
    
	private function projectProductView(array $product, bool $authorised): array {
		if ($authorised === true) {
			return $product;
		}

		foreach (self::PRODUCT_PRIVATE_FIELDS as $field) {
			unset($product[$field]);
		}

		return $product;
	}                          

	   
                                                                         
                                                                                
   
                                                   
                                                          
                                              
   
                                                                        
    
	private function fetchObjectBySchemaAndId(string $schema, string $idField, string $idValue): ?array {
		try {
			$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
			$schemaId = $this->appConfig->getValueString(Application::APP_ID, "{$schema}_schema", '');

			if ($registerId === '' || $schemaId === '') {
				$this->logger->warning(
					'ProductVendorProviderService: register or schema not configured',
					['schema' => $schema, 'registerId' => $registerId, 'schemaId' => $schemaId]
				);
				return null;
			}

			$results = $this->objectService->findAll(
				config: [
					'filters' => [
						$idField => $idValue,
						'register' => $registerId,
						'schema' => $schemaId,
					],
					'limit' => 1,
					'offset' => 0,
				]
			);

			if (empty($results) === true) {
				return null;
			}

			$first = reset($results);
			if (is_object($first) === true && method_exists($first, 'jsonSerialize') === true) {
				return $first->jsonSerialize();
			}

			if (is_array($first) === true) {
				return $first;
			}

			return null;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ProductVendorProviderService: lookup failed',
				['schema' => $schema, 'idField' => $idField, 'error' => $e->getMessage()]
			);
			return null;
		}         
	}                                
}           
