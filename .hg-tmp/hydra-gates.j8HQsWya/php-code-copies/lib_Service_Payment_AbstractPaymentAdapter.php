<?php

   
                                   
  
                                                                    
                                                                            
                                                                     
                                                                            
                                                          
  
                    
                                         
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Payment;

use Psr\Log\LoggerInterface;
use Throwable;

   
                                                           
  
                                                                                                             
   
abstract class AbstractPaymentAdapter implements PaymentProviderInterface {
	   
                                                                             
   
                                                                                
                                                                                
                                                                 
   
                                                                                      
                                                                                       
                                                                           
                                                                                     
                                                        
   
                                                                           
                                                                                 
                                                                                  
                                  
   
               
    
	public const BROKER_MANAGED_SECRET = '__managed_by_credential_broker__';

	   
                
   
                                                                                                      
                                                                                                          
                                              
                                                                               
    
	public function __construct(
		protected array $credentials,
		protected array $config,
		protected LoggerInterface $logger,
		protected ?HttpTransport $http = null,
	) {
	}                   

	   
                                
   
                  
   
                                                                                                              
    
	abstract public function getName(): string;

	   
                                                                  
   
                                                       
   
                
   
                                                                                                              
    
	public function setHttpTransport(?HttpTransport $transport): void {
		$this->http = $transport;
	}                        

	   
                                                     
   
                                            
   
                                            
    
	protected function toCents(float $amount): int {
		return (int)round(($amount * 100));
	}               

	   
                                                                
   
                                              
   
                                      
    
	protected function centsToDecimal(int $cents): string {
		$sign = '';
		$absolute = $cents;
		if ($cents < 0) {
			$sign = '-';
			$absolute = -$cents;
		}

		$major = intdiv($absolute, 100);
		$minor = ($absolute % 100);
		return sprintf('%s%d.%02d', $sign, $major, $minor);
	}                      

	   
                                        
   
                                                    
                                                           
   
                                                
    
	protected function safeEquals(string $expected, string $provided): bool {
		if ($expected === '' || $provided === '') {
			return false;
		}

		return hash_equals($expected, $provided);
	}                  

	   
                                                                           
   
                         
    
	protected function transport(): HttpTransport {
		if ($this->http === null) {
			$this->http = new CurlHttpTransport(logger: $this->logger);
		}

		return $this->http;
	}                 

	   
                                                            
   
                                             
   
                                                                                             
    
	protected function failedInitiate(string $message): array {
		return [
			'sessionId' => '',
			'redirectUrl' => null,
			'status' => 'failed',
			'error' => $message,
		];
	}                      

	   
                                    
   
                                            
                                             
   
                                                                   
    
	protected function failedCapture(string $sessionId, string $message): array {
		return [
			'sessionId' => $sessionId,
			'status' => 'failed',
			'error' => $message,
		];
	}                     

	   
                                   
   
                                            
                                             
   
                                                                                     
    
	protected function failedRefund(string $sessionId, string $message): array {
		return [
			'sessionId' => $sessionId,
			'refundId' => '',
			'status' => 'failed',
			'error' => $message,
		];
	}                    

	   
                                                       
   
                                                                    
                                      
   
                
    
	protected function logProviderError(string $context, Throwable $e): void {
		$this->logger->warning(
			'Pipelinq POS payment [' . $this->getName() . ']: ' . $context,
			[
				'provider' => $this->getName(),
				                                                         
				'class' => get_class($e),
			]
		);
	}                        

	   
                                                                 
   
                                                                             
   
                                                       
    
	protected function credential(string $key): string {
		$value = ($this->credentials[$key] ?? '');
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}                  

	   
                                 
   
                                      
                                       
   
                             
    
	protected function configValue(string $key, string $default = ''): string {
		if (isset($this->config[$key]) === false) {
			return $default;
		}

		$value = $this->config[$key];
		if (is_string($value) === false) {
			return $default;
		}

		return $value;
	}                   

	   
                                                         
   
                
    
	protected function isSandbox(): bool {
		$env = $this->configValue(key: 'environment', default: 'sandbox');
		return ($env !== 'live');
	}                 
}           
