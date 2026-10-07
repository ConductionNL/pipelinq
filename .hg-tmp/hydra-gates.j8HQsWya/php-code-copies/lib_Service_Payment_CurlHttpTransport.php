<?php

   
                              
  
                                                                           
                                                                           
                                                                             
                                                                           
                                                                             
                                    
  
                    
                                         
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Payment;

use Psr\Log\LoggerInterface;

   
                              
  
                                                                                                             
   
class CurlHttpTransport implements HttpTransport {
	   
                               
   
            
    
	private const TIMEOUT = 5;

	   
                
   
                                              
    
	public function __construct(
		private LoggerInterface $logger,
	) {
	}                   

	   
                            
   
                                          
                               
                                                      
                                          
   
                                                                       
   
                                                                                                              
    
	public function request(string $method, string $url, array $headers = [], ?string $body = null): array {
		                                                                                  
		                                                                                   
		                                                                                
		                                                                                 
		                                                                                  
		                                                                         
		foreach ($headers as $value) {
			if (is_string($value) === true
				&& str_contains($value, AbstractPaymentAdapter::BROKER_MANAGED_SECRET) === true
			) {
				$this->logger->error(
					'Pipelinq POS payment: refusing to send a PSP request directly — it carries the '
					. 'broker-managed placeholder, which means it bypassed the credential broker.'
				);
				return [
					'status' => 0,
					'body' => [],
					'raw' => '',
				];
			}
		}

		if (function_exists('curl_init') === false) {
			$this->logger->warning('Pipelinq POS payment: cURL not available');
			return [
				'status' => 0,
				'body' => [],
				'raw' => '',
			];
		}

		$handle = curl_init($url);
		if ($handle === false) {
			return [
				'status' => 0,
				'body' => [],
				'raw' => '',
			];
		}

		$headerList = [];
		foreach ($headers as $name => $value) {
			$headerList[] = $name . ': ' . $value;
		}

		curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($handle, CURLOPT_TIMEOUT, self::TIMEOUT);
		curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT);
		curl_setopt($handle, CURLOPT_HTTPHEADER, $headerList);
		if ($body !== null) {
			curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
		}

		$raw = curl_exec($handle);
		$status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
		$rawText = '';
		if (is_string($raw) === true) {
			$rawText = $raw;
		}

		curl_close($handle);

		$decoded = [];
		if ($rawText !== '') {
			$maybe = json_decode($rawText, true);
			if (is_array($maybe) === true) {
				$decoded = $maybe;
			}
		}

		return [
			'status' => $status,
			'body' => $decoded,
			'raw' => $rawText,
		];
	}               
}           
