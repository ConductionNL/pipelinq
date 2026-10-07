<?php

   
                                  
  
                                                                      
  
                                                                         
                                                                               
                                                                       
                                                                               
                                       
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

   
                                                                 
  
                                                                                                                                                 
   
class SocialAdapterRegistry {
	   
                                                                            
                                                                       
                                                      
   
                           
    
	public const NETWORKS = ['mastodon', 'bluesky', 'linkedin', 'x', 'facebook', 'instagram', 'threads'];

	   
                                   
   
                                            
    
	private array $adapters = [];

	   
                
   
                                                                         
                                                             
   
                                                                 
                                              
                                           
                                                                       
                                
                                                         
                                                                             
                                           
   
                
    
	public function __construct(
		private readonly SocialBrokerGateway $gateway,
		MastodonAdapter $mastodon,
		BlueskyAdapter $bluesky,
		LinkedInAdapter $linkedin,
		XAdapter $xNetwork,
		FacebookPageAdapter $facebook,
		InstagramBusinessAdapter $instagram,
		ThreadsAdapter $threads,
	) {
		$all = [$mastodon, $bluesky, $linkedin, $xNetwork, $facebook, $instagram, $threads];
		foreach ($all as $adapter) {
			$this->adapters[$adapter->network()] = $adapter;
		}
	}                   

	   
                                                                     
   
                                            
   
                                                           
   
                                                                                                                                                
    
	public function forNetwork(string $network): ?SocialNetworkAdapter {
		return ($this->adapters[$network] ?? null);
	}                  

	   
                                                                       
                                          
   
                                                          
   
                                                                                                                                                
    
	public function missingNetworks(): array {
		return array_values(array_diff(self::NETWORKS, array_keys($this->adapters)));
	}                       

	   
                                                                        
   
                                                                                          
   
                                                                                                                                                  
    
	public function readiness(): array {
		$out = [];
		foreach (self::NETWORKS as $network) {
			$out[$network] = $this->readinessFor(network: $network);
		}

		return $out;
	}                 

	   
                                      
   
                                            
   
                                                                              
   
                                                                                                                                                  
    
	public function readinessFor(string $network): array {
		$adapter = $this->forNetwork(network: $network);
		if ($adapter === null) {
			return [
				'state' => SocialBrokerGateway::NOT_CONFIGURED,
				'reason' => 'Pipelinq does not know this network.',
			];
		}

		return $this->gateway->readiness(brokerProvider: $adapter->brokerProvider());
	}                    

	   
                                                                         
   
                                            
   
                                                               
   
                                                                                                                                           
    
	public function providerFor(string $network): string {
		return ($this->forNetwork(network: $network)?->brokerProvider() ?? '');
	}                   
}           
