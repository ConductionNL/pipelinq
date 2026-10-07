<?php

   
                                   
  
                                                                        
                                                                            
                                                                              
                                                                              
                   
  
                                                                       
                                                                        
                                                                           
                                                                            
  
                    
                                        
  
                                                              
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                              
  
                                                                                                                                                                                        
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCA\Pipelinq\Service\WooRequestConversionService;
use OCP\IL10N;

   
                                                                
  
                                                                                                                                                                                        
   
class QuestionDetailService {
	   
                                                    
   
               
    
	private const TICKET = 'ticket';

	   
                                                                          
                                                                               
                                                                           
                                                     
   
               
    
	public const WOO_REQUEST_LINK = '/index.php/apps/portaliq/site#open=dossiq/mijnZaken/';

	   
                
   
                                                                 
                                                                               
   
                                                                                                                                                                                         
    
	public function __construct(
		private readonly MainRegisterReader $tickets,
		private readonly IL10N $l10n,
	) {
	}                   

	   
                                                                       
                                                                       
   
                                                                          
                                                                         
                                                                        
                                                                   
   
                                                                              
   
                                                                                                         
   
                                                                                                                                                                                         
    
	public function timeline(string $ticketId): array {
		$ticket = $this->question(ticketId: $ticketId);
		if ($ticket === null) {
			return [];
		}

		$updated = $this->text(value: ($ticket['@self']['updated'] ?? ''));
		$entries = [];
		$question = $this->text(value: ($ticket['description'] ?? ''));
		if ($question !== '') {
			$asked = $this->text(value: ($ticket['occurredAt'] ?? ''));
			$entries[] = $this->entry(id: 'question', moment: $asked, message: $this->l10n->t('You asked: %s', [$question]));
		}

		$entries = array_merge($entries, $this->answers(ticket: $ticket, updated: $updated), $this->replies(ticket: $ticket));
		if ($this->isConverted(ticket: $ticket) === true) {
			$entries[] = $this->entry(id: 'converted', moment: $updated, message: $this->l10n->t('Your question is now a Woo request.'));
		}

		return $entries;
	}                

	   
                                                                           
                    
   
                                                      
                                                                  
   
                                                                              
    
	private function answers(array $ticket, string $updated): array {
		$entries = [];
		$last = '';
		foreach ($this->listOf(value: ($ticket['portalAnswers'] ?? null)) as $index => $answer) {
			$last = $this->text(value: ($answer['message'] ?? ''));
			if ($last !== '') {
				$sent = $this->text(value: ($answer['createdAt'] ?? ''));
				$entries[] = $this->entry(id: 'answer-'.$index, moment: $sent, message: $this->l10n->t('Answer: %s', [$last]));
			}
		}

		$current = $this->text(value: ($ticket['customerMessage'] ?? ''));
		if ($current !== '' && $current !== $last) {
			$entries[] = $this->entry(id: 'answer', moment: $updated, message: $this->l10n->t('Answer: %s', [$current]));
		}

		return $entries;
	}               

	   
                                                 
   
                                                     
   
                                                                              
    
	private function replies(array $ticket): array {
		$entries = [];
		foreach ($this->listOf(value: ($ticket['portalReplies'] ?? null)) as $index => $reply) {
			$message = $this->text(value: ($reply['message'] ?? ''));
			if ($message !== '') {
				$sent = $this->text(value: ($reply['createdAt'] ?? ''));
				$entries[] = $this->entry(id: 'reply-'.$index, moment: $sent, message: $this->l10n->t('Your reply: %s', [$message]));
			}
		}

		return $entries;
	}               

	   
                                                                           
                                                                           
                                         
   
                                                                              
   
                                                                                              
   
                                                                                                                                                              
    
	public function dossierItems(string $ticketId): array {
		$ticket = $this->question(ticketId: $ticketId);
		if ($ticket === null) {
			return [];
		}

		$items = [];
		$case = $this->text(value: ($ticket['caseReference'] ?? ''));
		if ($case !== '' && $this->isConverted(ticket: $ticket) === true) {
			$items[] = [
				'id' => $case,
				'title' => $this->l10n->t('Your Woo request'),
				'url' => self::WOO_REQUEST_LINK.rawurlencode($case),
				'note' => $this->l10n->t('Your question is now this Woo request. Follow it under your cases.'),
			];
		}

		$reference = ($ticket['subjectReference'] ?? []);
		foreach ($this->listOf(value: ($reference['items'] ?? null)) as $item) {
			$title = $this->text(value: ($item['title'] ?? ''));
			if ($title !== '') {
				$items[] = ['id' => '', 'title' => $title, 'url' => $this->text(value: ($item['url'] ?? '')), 'note' => ''];
			}
		}

		return $items;
	}                    

	   
                                                                               
   
                                          
   
                                     
    
	private function question(string $ticketId): ?array {
		$ticket = $this->tickets->find(schemaKey: self::TICKET, id: $ticketId);
		if ($ticket === null || is_array($ticket['subjectReference'] ?? null) === false
			|| $this->text(value: ($ticket['portalSubject'] ?? '')) === ''
		) {
			return null;
		}

		return $ticket;
	}                

	   
                                                               
   
                                                   
   
                
    
	private function isConverted(array $ticket): bool {
		return ($ticket['status'] ?? null) === WooRequestConversionService::STATUS_CONVERTED;
	}                   

	   
                       
   
                                                             
                                                                    
                                                   
   
                                                                  
    
	private function entry(string $id, string $moment, string $message): array {
		return ['id' => $id, 'occurredAt' => $moment, 'message' => $message];
	}             

	   
                                                          
   
                                           
   
                                            
    
	private function listOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter($value, 'is_array'));
	}              

	   
                                                      
   
                                  
   
                  
    
	private function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}            
}           
