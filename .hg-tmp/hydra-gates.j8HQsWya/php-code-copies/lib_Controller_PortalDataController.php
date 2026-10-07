<?php

   
                                 
  
                                                                          
                                                                              
                                                                               
                                                                             
                                                                          
                                      
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Service\Portal\AbstractPortalReadFacade;
use OCA\Pipelinq\Service\Portal\PortalContractService;
use OCA\Pipelinq\Service\Portal\PortalInvoiceService;
use OCA\Pipelinq\Service\Portal\PortalOrderService;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCA\Pipelinq\Service\Portal\PortalTenantService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

   
                                                      
  
                                                                               
                                                        
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalDataController extends PortalApiController {
	   
                
   
                                         
                                                      
                                              
                                                             
                                                                
                                                       
                                                          
    
	public function __construct(
		IRequest $request,
		PortalRequestGuard $guard,
		LoggerInterface $logger,
		private PortalInvoiceService $invoices,
		private PortalContractService $contracts,
		private PortalOrderService $orders,
		private PortalTenantService $tenant,
	) {
		parent::__construct(request: $request, guard: $guard, logger: $logger);
	}                   

	   
                                 
   
                                                
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 120, period: 60)]
	public function invoices(): JSONResponse {
		return $this->requireListAccess(facade: $this->invoices, feature: 'invoices');
	}                

	   
                          
   
                                     
   
                                             
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 120, period: 60)]
	public function invoice(string $id): JSONResponse {
		return $this->requireObjectAccess(facade: $this->invoices, feature: 'invoices', id: $id);
	}               

	   
                                  
   
                                                 
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 120, period: 60)]
	public function contracts(): JSONResponse {
		return $this->requireListAccess(facade: $this->contracts, feature: 'contracts');
	}                 

	   
                           
   
                                      
   
                                              
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 120, period: 60)]
	public function contract(string $id): JSONResponse {
		return $this->requireObjectAccess(facade: $this->contracts, feature: 'contracts', id: $id);
	}                

	   
                               
   
                                              
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 120, period: 60)]
	public function orders(): JSONResponse {
		return $this->requireListAccess(facade: $this->orders, feature: 'orders');
	}              

	   
                        
   
                                   
   
                                           
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 120, period: 60)]
	public function order(string $id): JSONResponse {
		return $this->requireObjectAccess(facade: $this->orders, feature: 'orders', id: $id);
	}             

	   
                                                              
   
                                                            
                                           
   
                                            
    
	private function requireListAccess(AbstractPortalReadFacade $facade, string $feature): JSONResponse {
		return $this->guarded(
			handler: function () use ($facade, $feature): array {
				$ctx = $this->requireSession();
				$this->tenant->requireFeature(tenantId: $ctx['tenantId'], feature: $feature);
				$page = $facade->getForAccount(
					$ctx['account'],
					$this->intParam(name: 'page', default: 1),
					$this->intParam(name: 'perPage', default: 10)
				);
				return [$page, Http::STATUS_OK];
			}
		);
	}                         

	   
                                                                    
   
                                                            
                                           
                                    
   
                                            
    
	private function requireObjectAccess(AbstractPortalReadFacade $facade, string $feature, string $id): JSONResponse {
		return $this->guarded(
			handler: function () use ($facade, $feature, $id): array {
				$ctx = $this->requireSession();
				$this->tenant->requireFeature(tenantId: $ctx['tenantId'], feature: $feature);
				$object = $facade->getOneForAccount($ctx['account'], $id);
				if ($object === null) {
					return [['errorCode' => 'notFound', 'message' => 'Niet gevonden.'], Http::STATUS_NOT_FOUND];
				}

				return [$object, Http::STATUS_OK];
			}
		);
	}                           
}           
