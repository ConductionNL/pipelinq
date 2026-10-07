<?php

   
                             
  
                                                                           
                                                                         
                                                                           
                                                                             
                                   
  
                                                                            
                                                                               
                                                                             
                                                                            
                                                                               
                                                                            
                                                                      
                                                   
  
                                                                               
                                                                               
                                                                           
                                                                        
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Search;

   
                                             
  
                                                                                                                                                                             
   
final class ExpectedCtrCurve {

	   
                                                                          
   
                          
    
	public const CURVE = [
		1 => 0.280,
		2 => 0.150,
		3 => 0.110,
		4 => 0.080,
		5 => 0.060,
		6 => 0.050,
		7 => 0.040,
		8 => 0.034,
		9 => 0.029,
		10 => 0.025,
		11 => 0.021,
		12 => 0.018,
		13 => 0.016,
		14 => 0.014,
		15 => 0.013,
		16 => 0.012,
		17 => 0.011,
		18 => 0.010,
		19 => 0.009,
		20 => 0.008,
	];

	   
                                                                       
                                                                      
                       
   
            
    
	public const TAIL_POSITION = 20;

	   
                                                     
   
              
    
	public const TAIL_RATE = 0.008;

	   
                                                                          
                       
   
                                                                        
   
                                             
   
                                                                                                                                                                              
    
	public static function rateAt(float $position): float {
		if ($position <= 1.0) {
			return self::CURVE[1];
		}

		if ($position >= (float)self::TAIL_POSITION) {
			return self::TAIL_RATE;
		}

		$lower = (int)floor($position);
		$upper = ($lower + 1);
		$fraction = ($position - (float)$lower);
		$low = (self::CURVE[$lower] ?? self::TAIL_RATE);
		$high = (self::CURVE[$upper] ?? self::TAIL_RATE);

		return ($low + (($high - $low) * $fraction));
	}              
}           
