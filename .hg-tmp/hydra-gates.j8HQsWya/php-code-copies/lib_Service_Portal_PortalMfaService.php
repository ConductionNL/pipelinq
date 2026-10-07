<?php

   
                             
  
                                                                       
                                                                             
                                                                        
                                                                        
                                                                         
  
                                                                                
                                                                            
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;

   
                                       
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalMfaService {
	   
                                                   
   
               
    
	private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	   
                         
   
            
    
	private const PERIOD = 30;

	   
                               
   
            
    
	private const DIGITS = 6;

	   
                                 
   
            
    
	private const SKEW = 1;

	   
                
   
                                                  
                                                  
                                               
    
	public function __construct(
		private ISecureRandom $secureRandom,
		private ICrypto $crypto,
		private ITimeFactory $time,
	) {
	}                   

	   
                                                 
   
                                             
                                                                                         
                                                                            
                                                                            
                               
    
	public function generateSecret(): string {
		$raw = $this->secureRandom->generate(20, ISecureRandom::CHAR_ALPHANUMERIC);
		$secret = '';
		$buffer = 0;
		$bits = 0;
		foreach (str_split($raw) as $char) {
			$buffer = (($buffer << 8) | ord($char));
			$bits += 8;
			while ($bits >= 5) {
				$bits -= 5;
				$secret .= self::BASE32[(($buffer >> $bits) & 31)];
			}
		}

		if ($bits > 0) {
			$secret .= self::BASE32[(($buffer << (5 - $bits)) & 31)];
		}

		return $secret;
	}                      

	   
                                                                          
   
                                            
                                                                      
                                                                     
   
                                   
                                                                                         
                                                                            
                                                                            
                               
    
	public function provisioningUri(string $secret, string $accountLabel, string $issuer): string {
		$label = rawurlencode($issuer) . ':' . rawurlencode($accountLabel);
		return sprintf(
			'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
			$label,
			$secret,
			rawurlencode($issuer),
			self::DIGITS,
			self::PERIOD
		);
	}                       

	   
                                                       
   
                                            
   
                                  
                                                                                         
                                                                            
                                                                            
                               
    
	public function encryptSecret(string $secret): string {
		return $this->crypto->encrypt($secret);
	}                     

	   
                                                                       
   
                                                                            
                                                     
   
                                                                    
                                                
   
                                                                         
                                                                                         
                                                                            
                                                                            
                               
    
	public function verifyCode(?string $encryptedSecret, ?string $code): bool {
		if ($encryptedSecret === null || $encryptedSecret === '' || $code === null) {
			return false;
		}

		$code = trim($code);
		if (preg_match('/^\d{6}$/', $code) !== 1) {
			return false;
		}

		try {
			$secret = $this->crypto->decrypt($encryptedSecret);
		} catch (\Throwable $e) {
			return false;
		}

		$counter = intdiv($this->time->getTime(), self::PERIOD);
		for ($offset = (-self::SKEW); $offset <= self::SKEW; $offset++) {
			if (hash_equals($this->codeForCounter(base32Secret: $secret, counter: ($counter + $offset)), $code) === true) {
				return true;
			}
		}

		return false;
	}                  

	   
                                                              
   
                                                  
                                              
   
                                                
    
	private function codeForCounter(string $base32Secret, int $counter): string {
		$key = $this->base32Decode(secret: $base32Secret);
		$binary = pack('N*', 0) . pack('N*', $counter);
		$hash = hash_hmac('sha1', $binary, $key, true);
		$offset = (ord($hash[19]) & 0x0F);
		$byte0 = ((ord($hash[$offset]) & 0x7F) << 24);
		$byte1 = ((ord($hash[($offset + 1)]) & 0xFF) << 16);
		$byte2 = ((ord($hash[($offset + 2)]) & 0xFF) << 8);
		$byte3 = (ord($hash[($offset + 3)]) & 0xFF);
		$part = ($byte0 | $byte1 | $byte2 | $byte3);

		return str_pad((string)($part % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
	}                      

	   
                                        
   
                                            
   
                                     
    
	private function base32Decode(string $secret): string {
		$secret = strtoupper(rtrim($secret, '='));
		$buffer = 0;
		$bits = 0;
		$output = '';
		foreach (str_split($secret) as $char) {
			$index = strpos(self::BASE32, $char);
			if ($index === false) {
				continue;
			}

			$buffer = (($buffer << 5) | $index);
			$bits += 5;
			if ($bits >= 8) {
				$bits -= 8;
				$output .= chr((($buffer >> $bits) & 0xFF));
			}
		}

		return $output;
	}                    
}           
