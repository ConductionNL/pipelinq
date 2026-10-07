<?php

   
                                     
  
                                                                           
                                                                          
                                         
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                     
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Service\ProductCatalogService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

   
                                                  
  
                                                                              
                                                                                
                                                                          
                                                                                
                                                                              
                                                                                
                                                           
  
                                                  
  
                                                     
                                                                   
   
class ProductCatalogController extends Controller {
	   
                
   
                                         
                                                                        
                                                      
                                                                
                                                
                                              
    
	public function __construct(
		IRequest $request,
		private ProductCatalogService $service,
		private IUserSession $userSession,
		private PosAccessPolicy $policy,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                              
   
                                                                        
   
                                                      
    
	#[NoAdminRequired]
	public function lookupBarcode(): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$barcode = (string)$this->request->getParam('barcode', '');

		return $this->run(
			action: function () use ($barcode): array {
				$product = $this->service->lookupByBarcode(barcode: $barcode);
				if ($product === null) {
					throw new OCSNotFoundException(
						$this->l10n->t('No product found for barcode %s', [$barcode])
					);
				}

				                                                                
				                                                              
				$variantIndex = null;
				if (isset($product['matchedVariantIndex']) === true) {
					$variantIndex = (int)$product['matchedVariantIndex'];
				}

				return ['product' => $product, 'variantIndex' => $variantIndex];
			},
			label: 'lookupBarcode'
		);
	}                     

	   
                                                                        
   
                                                                           
                                                                           
                                                                              
                                                
   
                                            
   
                                                      
    
	#[NoAdminRequired]
	public function resolvePrice(): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$product = (array)$this->request->getParam('product', []);
		$quantity = (float)$this->request->getParam('quantity', 1);

		                                                      
		  
		                                                                        
		                                                                        
		                                                                        
		                                                                        
		                                                                  
		                             
		if ($quantity < 0.0) {
			return new JSONResponse(
				['error' => 'quantity must not be negative'],
				Http::STATUS_BAD_REQUEST
			);
		}
		$variantSku = $this->request->getParam('variantSku');
		if ($variantSku !== null) {
			$variantSku = (string)$variantSku;
		}

		return $this->run(
			action: fn (): array => $this->service->resolveEffectivePrice(
				product: $product,
				quantity: $quantity,
				variantSku: $variantSku
			),
			label: 'resolvePrice'
		);
	}                    

	   
                                                               
   
                                                                             
                                                                             
                                                                            
                                                                       
   
                                                                           
   
                                                                    
    
	private function requireUserId(): string|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		$uid = $user->getUID();
		if ($this->policy->isPosUser(userId: $uid) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('POS access is required for the product catalogue')],
				Http::STATUS_FORBIDDEN
			);
		}

		return $uid;
	}                     

	   
                                             
   
                                              
                                                       
   
                                      
    
	private function run(callable $action, string $label): JSONResponse {
		try {
			return new JSONResponse($action());
		} catch (OCSNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (\Throwable $e) {
			$this->logger->error('ProductCatalogController::' . $label . ' failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['error' => $this->l10n->t('An unexpected error occurred')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}         
	}           
}           
