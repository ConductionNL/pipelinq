<?php

   
                                
  
                                                                          
                                                               
  
                    
                                      
  
                                                              
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                                                 
  
                                                                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Demo;

   
                                                                              
  
                                                                                                      
   
class DemoMarketingSeeder {
	   
                
   
                                                                         
                                                                                       
                                                                                       
    
	public function __construct(
		private readonly DemoJourneySeeder $journeySeeder,
		private readonly DemoSocialSeeder $socialSeeder,
		private readonly DemoSearchSeeder $searchSeeder,
	) {
	}                   

	   
                                                                            
   
                                                                 
                                                                                                    
   
                                                                                                                 
   
                                                                                                       
    
	public function seed(array $definitions, array $uuids): array {
		$journeys = $this->journeySeeder->seed(definitions: ($definitions['journeys'] ?? []), uuids: $uuids);
		$social = $this->socialSeeder->seed(social: ($definitions['social'] ?? []));
		$search = $this->searchSeeder->seed(search: ($definitions['search'] ?? []));

		return [
			'created' => [
				'journeys' => $journeys['created'],
				'journeyRuns' => $journeys['runs'],
				'socialAccounts' => $social['accounts'],
				'socialPosts' => $social['posts'],
				'socialPublications' => $social['publications'],
				'searchQueryRows' => $search['rows'],
				'keywordTargets' => $search['targets'],
			],
			'skipped' => [
				'journeys' => $journeys['skipped'],
				'social' => $social['skipped'],
				'search' => $search['skipped'],
			],
		];
	}            

	   
                                                                  
   
                                                                 
   
                                                                              
   
                                                                                                       
    
	public function remove(array $definitions): array {
		$journeys = $this->journeySeeder->remove(definitions: ($definitions['journeys'] ?? []));
		$social = $this->socialSeeder->remove(social: ($definitions['social'] ?? []));
		$search = $this->searchSeeder->remove(search: ($definitions['search'] ?? []));

		return [
			'journeys' => $journeys['journeys'],
			'journeyRuns' => $journeys['runs'],
			'socialPublications' => $social['publications'],
			'socialPosts' => $social['posts'],
			'socialAccounts' => $social['accounts'],
			'searchQueryRows' => $search['rows'],
			'keywordTargets' => $search['targets'],
		];
	}              
}           
