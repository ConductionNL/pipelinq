<?php

   
                                     
  
                                                                      
                                                                            
                                                                    
  
                                                                       
                                                                              
                                                                              
                                                                          
                                 
  
                    
                                          
  
                                                              
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                              
  
                                                                                     
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Settings;

   
                                                                  
  
                                                                                     
   
final class SchemaSlugMap {
	   
                                  
   
                                                                          
                                                                            
                                                                           
                                                                  
   
                              
    
	public const SLUG_TO_CONFIG_KEY = [
		'pipeline' => 'pipeline_schema',
		'skill' => 'skill_schema',
		'product' => 'product_schema',
		'productCategory' => 'productCategory_schema',
		'billingCategory' => 'billingCategory_schema',
		'posRole' => 'posRole_schema',
		'posTenderType' => 'posTenderType_schema',
		'receiptTemplate' => 'receiptTemplate_schema',
		'refundReason' => 'refundReason_schema',
		'loyaltyProgramme' => 'loyaltyProgramme_schema',
		'pointsRule' => 'pointsRule_schema',
		'berichtenboxTemplate' => 'berichtenboxTemplate_schema',
	];

	   
                                                                          
   
                                        
   
                                                                                   
   
                                                                                      
    
	public static function configKeyFor(string $slug): ?string {
		return (self::SLUG_TO_CONFIG_KEY[$slug] ?? null);
	}                    
}           
