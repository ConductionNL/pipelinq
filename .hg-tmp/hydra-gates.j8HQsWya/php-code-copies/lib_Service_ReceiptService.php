<?php

   
                           
  
                                                                              
                                                                        
                                                                             
                                                               
                                                                    
                                                                   
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                  
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IL10N;

   
                                                       
  
                                                                            
                                                                               
                                                                           
                                                                           
                                                                         
  
                                                                              
                                                                          
                                                                               
                                                                      
                                                                      
                                                                              
                                                                            
                                                                        
                                                                                
                                                                               
  
                                                  
   
class ReceiptService {
	   
                                                                          
                                                                       
   
              
    
	public const INVOICE_THRESHOLD_EUR = 100.0;

	   
                                                     
   
            
    
	public const DEFAULT_LAYOUT_WIDTH = 42;

	   
                                                
   
               
    
	private const ESC_INIT = "\x1B\x40";

	   
                                      
   
               
    
	private const ESC_BOLD_ON = "\x1B\x45\x01";

	   
                                       
   
               
    
	private const ESC_BOLD_OFF = "\x1B\x45\x00";

	   
                                               
   
               
    
	private const ESC_ALIGN_CENTER = "\x1B\x61\x01";

	   
                                             
   
               
    
	private const ESC_ALIGN_LEFT = "\x1B\x61\x00";

	   
                                     
   
               
    
	private const ESC_FULL_CUT = "\x1D\x56\x00";

	   
                                                                          
   
               
    
	private const ESC_CODEPAGE_CP858 = "\x1B\x74\x13";

	   
                
   
                                                                            
                                                
    
	public function __construct(
		private IAppConfig $appConfig,
		private IL10N $l10n,
	) {
	}                   

	   
                                                              
   
                                                                            
                                                    
   
                                                                       
   
                                                               
   
                                                   
    
	public function isInvoiceTransaction(array $transaction): bool {
		$total = (float)($transaction['total'] ?? 0);

		return $total >= self::INVOICE_THRESHOLD_EUR;
	}                            

	   
                                   
   
                                
   
                                                   
    
	public function invoiceThreshold(): float {
		return self::INVOICE_THRESHOLD_EUR;
	}                        

	   
                                                      
   
                                                                          
                                                                             
                                                                          
                                                                    
   
                                                                       
                                                                                   
   
                                                   
   
                                                   
    
	public function renderText(array $transaction, array $template = []): string {
		$width = $this->layoutWidth(template: $template, transaction: $transaction);
		$invoice = $this->renderAsInvoice(transaction: $transaction, template: $template);
		$context = $this->buildContext(transaction: $transaction);

		$lines = array_merge(
			$this->headerLines(context: $context, width: $width, invoice: $invoice),
			$this->bodyLines(context: $context, width: $width),
			$this->summaryLines(context: $context, width: $width, invoice: $invoice),
			$this->footerLines(context: $context, width: $width, invoice: $invoice)
		);

		return implode("\n", $lines) . "\n";
	}                  

	   
                                                
   
                                                                      
   
                                                             
                                                       
   
                                                 
    
	private function renderAsInvoice(array $transaction, array $template): bool {
		if ($this->isInvoiceTransaction(transaction: $transaction) === true) {
			return true;
		}

		return ($template['isInvoiceMode'] ?? false) === true;
	}                       

	   
                                                                          
   
                                                            
                                       
                                                    
   
                                                
    
	private function headerLines(array $context, int $width, bool $invoice): array {
		$lines = [];
		$lines[] = $this->center(text: $context['company']['name'], width: $width);
		if ($context['company']['address'] !== '') {
			$lines[] = $this->center(text: $context['company']['address'], width: $width);
		}

		if ($context['company']['phone'] !== '') {
			$lines[] = $this->center(text: 'Tel: ' . $context['company']['phone'], width: $width);
		}

		$lines[] = str_repeat('=', $width);
		$lines[] = $this->center(text: $this->headingLabel(invoice: $invoice), width: $width);

		if ($invoice === true && $context['invoiceNumber'] !== '') {
			$lines[] = $this->center(
				text: $this->l10n->t('Invoice no.') . ' ' . $context['invoiceNumber'],
				width: $width
			);
		}

		$lines[] = $context['reference'];
		$lines[] = $context['date'];
		$lines[] = str_repeat('-', $width);

		return $lines;
	}                   

	   
                             
   
                                                            
                                       
   
                                             
    
	private function bodyLines(array $context, int $width): array {
		$lines = [];
		foreach ($context['lines'] as $line) {
			$lines[] = $this->lineRow(line: $line, width: $width);
		}

		return $lines;
	}                 

	   
                                         
   
                                                            
                                       
                                                    
   
                                                 
    
	private function summaryLines(array $context, int $width, bool $invoice): array {
		$lines = [];
		$lines[] = str_repeat('-', $width);
		$lines[] = $this->amountRow(label: $this->l10n->t('Subtotal'), amount: $context['subtotal'], width: $width);
		if ($context['discountTotal'] > 0) {
			$lines[] = $this->amountRow(
				label: $this->l10n->t('Discount'),
				amount: -$context['discountTotal'],
				width: $width
			);
		}

		foreach ($this->taxLines(context: $context, width: $width, invoice: $invoice) as $taxLine) {
			$lines[] = $taxLine;
		}

		$lines[] = str_repeat('=', $width);
		$lines[] = $this->amountRow(label: $this->l10n->t('Total'), amount: $context['total'], width: $width);
		$lines[] = str_repeat('=', $width);

		return $lines;
	}                    

	   
                                                                  
   
                                                            
                                       
                                                    
   
                                             
    
	private function taxLines(array $context, int $width, bool $invoice): array {
		if ($invoice === false) {
			return [$this->amountRow(label: $this->l10n->t('VAT'), amount: $context['totalTax'], width: $width)];
		}

		$lines = [];
		foreach ($context['btwLines'] as $vat) {
			$lines[] = $this->amountRow(label: $vat['label'], amount: $vat['tax'], width: $width);
		}

		return $lines;
	}                

	   
                                                                              
   
                                                            
                                       
                                                    
   
                                                
    
	private function footerLines(array $context, int $width, bool $invoice): array {
		if ($invoice === false) {
			return ['', $this->center(text: $this->l10n->t('Thank you for your purchase'), width: $width)];
		}

		return array_merge([''], $this->complianceFooter(context: $context));
	}                   

	   
                                                         
   
                                                    
   
                                     
    
	private function headingLabel(bool $invoice): string {
		if ($invoice === true) {
			return $this->l10n->t('INVOICE');
		}

		return $this->l10n->t('RECEIPT');
	}                    

	   
                                                                       
   
                                                                       
                                                                        
   
                                                                        
   
                                                   
    
	public function renderHtml(array $transaction, array $template = []): string {
		$text = $this->renderText(transaction: $transaction, template: $template);

		                                                                          
		                                                                         
		                                                   
		$escaped = htmlspecialchars($text, (ENT_QUOTES | ENT_HTML5), 'UTF-8');

		return '<pre style="font-family:monospace;font-size:13px;line-height:1.35;'
			. 'white-space:pre-wrap;margin:0;">' . $escaped . '</pre>';
	}                  

	   
                                                           
   
                                                                        
                                                                          
                                                                            
                                                                            
   
                                                                       
                                                                        
   
                                               
   
                                                   
    
	public function renderEscPos(array $transaction, array $template = []): string {
		$invoice = $this->renderAsInvoice(transaction: $transaction, template: $template);
		$body = $this->renderText(transaction: $transaction, template: $template);
		$heading = $this->headingLabel(invoice: $invoice);

		                                                                       
		                                                                        
		$out = self::ESC_INIT;
		$out .= self::ESC_CODEPAGE_CP858;
		$out .= self::ESC_ALIGN_CENTER . self::ESC_BOLD_ON;
		$out .= $this->encodeForPrinter(text: $heading) . "\n";
		$out .= self::ESC_BOLD_OFF . self::ESC_ALIGN_LEFT;
		$out .= $this->encodeForPrinter(text: $body);
		$out .= "\n\n\n";
		$out .= self::ESC_FULL_CUT;

		return $out;
	}                    

	   
                                                                    
   
                                                                              
                                                                              
                                                                  
   
                                                                       
   
                                                    
   
                                                   
    
	public function buildContext(array $transaction): array {
		$vatRows = $transaction['invoiceBreakdown'] ?? $transaction['taxBreakdown'] ?? [];
		$vatLines = [];
		if (is_array($vatRows) === true) {
			foreach ($vatRows as $row) {
				if (is_array($row) === false) {
					continue;
				}

				$rate = (float)($row['rate'] ?? 0);
				$label = (string)($row['description'] ?? '');
				if ($label === '') {
					$label = rtrim(rtrim((string)$rate, '0'), '.') . '% ' . $this->l10n->t('VAT');
				}

				$vatLines[] = [
					'rate' => $rate,
					'base' => (float)($row['base'] ?? 0),
					'tax' => (float)($row['tax'] ?? 0),
					'label' => $label,
				];
			}             
		}        

		$lines = [];
		foreach (($transaction['lines'] ?? []) as $line) {
			if (is_array($line) === false) {
				continue;
			}

			$lines[] = [
				'description' => (string)($line['description'] ?? ''),
				'quantity' => (float)($line['quantity'] ?? 0),
				'lineTotal' => (float)($line['lineTotal'] ?? 0),
			];
		}

		return [
			'reference' => (string)($transaction['reference'] ?? ($transaction['id'] ?? '')),
			'date' => $this->formatDate(value: (string)($transaction['confirmedAt'] ?? $transaction['createdAt'] ?? '')),
			'subtotal' => (float)($transaction['subtotal'] ?? 0),
			'discountTotal' => (float)($transaction['discountTotal'] ?? 0),
			'totalTax' => (float)($transaction['totalTax'] ?? 0),
			'total' => (float)($transaction['total'] ?? 0),
			'btwLines' => $vatLines,
			'lines' => $lines,
			'company' => $this->companyDetails(),
			'invoiceNumber' => (string)($transaction['invoiceNumber'] ?? ''),
		];
	}                    

	   
                                                                   
   
                                                                                           
                                                                                                                
   
                                                   
    
	public function companyDetails(): array {
		return [
			'name' => $this->appConfig->getValueString(Application::APP_ID, 'receipt_company_name', 'Conduction B.V.'),
			'address' => $this->companyAddress(),
			'phone' => $this->appConfig->getValueString(Application::APP_ID, 'receipt_company_phone', ''),
			'vatId' => $this->appConfig->getValueString(Application::APP_ID, 'receipt_company_vat', ''),
			'kvk' => $this->appConfig->getValueString(Application::APP_ID, 'receipt_company_kvk', ''),
		];
	}                      

	   
                                                                           
                                                                            
   
                                                                      
   
                                                                                      
    
	public function companyAddress(): string {
		$oneLine = $this->appConfig->getValueString(Application::APP_ID, 'receipt_company_address', '');
		if (trim($oneLine) !== '') {
			return $oneLine;
		}

		$read = fn (string $key): string => trim($this->appConfig->getValueString(Application::APP_ID, $key, ''));
		$town = trim($read('receipt_company_postcode') . ' ' . $read('receipt_company_city'));
		$parts = array_filter(
			[$read('receipt_company_street'), $town, $read('receipt_company_country')],
			static fn (string $part): bool => $part !== ''
		);

		return implode(', ', $parts);
	}                      

	   
                                                                       
   
                                                               
                                                             
   
                                             
    
	private function layoutWidth(array $template, array $transaction): int {
		$width = (int)($template['layoutWidth'] ?? 0);
		if ($width < 20) {
			$width = self::DEFAULT_LAYOUT_WIDTH;
		}

		                                                                     
		if ($this->isInvoiceTransaction(transaction: $transaction) === true
			&& ($template['layoutWidth'] ?? 0) === 0
		) {
			$width = 80;
		}

		return min(120, $width);
	}                   

	   
                                                          
   
                                                            
   
                                                
   
                                                   
    
	private function complianceFooter(array $context): array {
		$company = $context['company'];
		$footer = [];

		if ($company['vatId'] !== '') {
			$footer[] = $this->l10n->t('VAT no.') . ': ' . $company['vatId'];
		}

		if ($company['kvk'] !== '') {
			$footer[] = $this->l10n->t('Chamber of Commerce no.') . ': ' . $company['kvk'];
		}

		if ($context['invoiceNumber'] !== '') {
			$footer[] = $this->l10n->t('Invoice no.') . ': ' . $context['invoiceNumber'];
		}

		$footer[] = $this->l10n->t('Invoice date') . ': ' . $context['date'];
		$footer[] = $this->l10n->t('Transaction reference') . ': ' . $context['reference'];

		return $footer;
	}                        

	   
                                  
   
                                                       
                                       
   
                           
    
	private function lineRow(array $line, int $width): string {
		$qty = rtrim(rtrim(number_format((float)$line['quantity'], 2, '.', ''), '0'), '.');
		$label = (string)$line['description'] . ' x' . $qty;
		$price = $this->money(value: (float)$line['lineTotal']);

		return $this->amountRow(label: $label, amount: (float)$line['lineTotal'], width: $width, prefix: '', display: $price);
	}               

	   
                                                                
   
                                        
                                    
                                       
                                                   
                                                                        
   
                           
    
	private function amountRow(string $label, float $amount, int $width, string $prefix = '', ?string $display = null): string {
		$right = $display ?? ('€' . $this->money(value: $amount));
		$left = $prefix . $label;
		$space = ($width - mb_strlen($left) - mb_strlen($right));
		if ($space < 1) {
			$space = 1;
		}

		return $left . str_repeat(' ', $space) . $right;
	}                 

	   
                                            
   
                                 
                                
   
                                    
    
	private function center(string $text, int $width): string {
		$len = mb_strlen($text);
		if ($len >= $width) {
			return mb_substr($text, 0, $width);
		}

		$pad = (int)floor((($width - $len) / 2));

		return str_repeat(' ', $pad) . $text;
	}              

	   
                                         
   
                                  
   
                                        
    
	private function money(float $value): string {
		return number_format(round($value, 2), 2, ',', '.');
	}             

	   
                                                                       
   
                                           
   
                                    
    
	private function formatDate(string $value): string {
		$timestamp = false;
		if ($value !== '') {
			$timestamp = strtotime($value);
		}

		if ($timestamp === false) {
			if ($value !== '') {
				return $value;
			}

			$timestamp = (new DateTimeImmutable())->getTimestamp();
		}

		return date('d-m-Y H:i', $timestamp);
	}                  

	   
                                                                
   
                                                                            
                                                                          
                                                                            
                                                                            
                                                                             
   
                                       
   
                                                        
    
	private function encodeForPrinter(string $text): string {
		if (function_exists('iconv') === false) {
			return $text;
		}

		set_error_handler(static fn (): bool => true);
		try {
			$encoded = iconv('UTF-8', 'CP858//TRANSLIT', $text);
		} finally {
			restore_error_handler();
		}

		if ($encoded === false) {
			return $text;
		}

		return $encoded;
	}                        
}           
