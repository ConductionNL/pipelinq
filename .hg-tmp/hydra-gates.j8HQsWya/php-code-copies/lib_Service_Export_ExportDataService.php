<?php

   
                              
  
                                                                             
                                                                               
                                                                             
                                                                               
                                                                                
                                                    
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                    
                                               
  
                         
  
                                                 
  
                                                                                
                                                                                
                                                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

use OCP\AppFramework\OCS\OCSBadRequestException;

   
                                                           
  
                                                                                   
                                                                             
                                                            
                                                                               
                                                                                
                             
                                                                                     
                                                                                
                                                                    
                                                                                          
                                                       
                                                                                  
                                                                                  
  
                                                                                
   
class ExportDataService extends AbstractExportService {
	   
                                                                           
                                                                       
   
                           
    
	public const SENSITIVE_COLUMNS = [
		'email',
		'phone',
		'telephone',
		'mobile',
		'ssn',
		'bsn',
		'iban',
		'bankaccount',
		'passport',
		'dateofbirth',
		'dob',
	];

	   
                                         
   
            
    
	public const TEST_RUN_ROW_CAP = 100;

	   
                                                                   
   
            
    
	private const PAGE_SIZE = 500;

	   
                                                            
   
                                                                             
                                                                              
   
                                                 
   
                                                                    
                                                                                                                                   
    
	public function schemaKeyForSlug(string $slug): ?string {
		$slug = trim($slug);
		if ($slug === '' || preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $slug) !== 1) {
			return null;
		}

		return $slug . '_schema';
	}                        

	   
                                                                           
   
                                        
   
                                                                         
                                                                                                                                   
    
	public function schemaExists(string $slug): bool {
		$key = $this->schemaKeyForSlug(slug: $slug);
		if ($key === null) {
			return false;
		}

		$schemaId = $this->appConfig->getValueString('pipelinq', $key, '');
		return $schemaId !== '';
	}                    

	   
                                                          
   
                                                                             
                                                                          
                                                                           
                                                                         
   
                                                                  
                                                         
                                                                                   
                                                                   
   
                                                                                     
                                                                                         
   
                                                                                               
   
                                                                                 
                                                                                 
    
	public function extractSchema(array $job, string $schemaSlug, ?string $watermarkFrom = null, ?int $rowCap = null): array {
		$schemaKey = $this->schemaKeyForSlug(slug: $schemaSlug);
		if ($schemaKey === null || $this->schemaExists(slug: $schemaSlug) === false) {
			throw new OCSBadRequestException("Schema '{$schemaSlug}' does not exist.");
		}

		$mode = ($job['mode'] ?? 'full');
		$watermark = (string)($job['incrementalWatermarkColumn'] ?? '');
		if ($mode === 'incremental' && $watermark === '') {
			throw new OCSBadRequestException('Watermark column is required for incremental mode.');
		}

		$rows = $this->fetchRows(
			schemaKey: $schemaKey,
			mode: (string)$mode,
			watermarkColumn: $watermark,
			watermarkFrom: $watermarkFrom,
			rowCap: $rowCap
		);

		$watermarkTo = null;
		if ($mode === 'incremental' && $watermark !== '') {
			$watermarkTo = $this->maxWatermark(rows: $rows, watermarkColumn: $watermark);
			$rows = $this->addTombstoneMarker(rows: $rows);
		}

		$filter = (string)($job['rowFilterExpression'] ?? '');
		if ($filter !== '') {
			$rows = $this->applyRowFilter(rows: $rows, expression: $filter);
		}

		$allowlist = $job['columnAllowlist'] ?? null;
		$droppedColumns = [];
		if (is_array($allowlist) === true && $allowlist !== []) {
			                                                           
			$effective = $allowlist;
			if ($mode === 'incremental') {
				$effective[] = '_deleted';
			}

			[$rows, $droppedColumns] = $this->applyColumnAllowlist(rows: $rows, allowlist: $effective);
		}

		$format = (string)($job['format'] ?? 'csv');
		$compression = (string)($job['compression'] ?? 'none');
		$contents = $this->format(rows: $rows, format: $format);
		$contents = $this->compress(contents: $contents, compression: $compression);

		return [
			'schema' => $schemaSlug,
			'rows' => count($rows),
			'contents' => $contents,
			'size_bytes' => strlen($contents),
			'sha256' => hash('sha256', $contents),
			'compression_used' => $compression,
			'watermark_to' => $watermarkTo,
			'dropped_columns' => $droppedColumns,
			'column_definitions' => $this->columnDefinitions(rows: $rows),
		];
	}                     

	   
                                               
   
                                                           
                                            
   
                                         
   
                                                                                 
    
	public function format(array $rows, string $format): string {
		switch ($format) {
			case 'jsonl':
				return $this->formatJsonl(rows: $rows);
			case 'parquet':
				return $this->formatParquet(rows: $rows);
			case 'csv':
			default:
				return $this->formatCsv(rows: $rows);
		}
	}              

	   
                                                            
   
                                                           
   
                                   
   
                                                                                    
    
	public function formatCsv(array $rows): string {
		$columns = $this->collectColumns(rows: $rows);
		if ($columns === []) {
			return '';
		}

		$lines = [];
		$lines[] = $this->csvRow(values: $columns);
		foreach ($rows as $row) {
			$values = [];
			foreach ($columns as $column) {
				$values[] = $this->stringify(value: ($row[$column] ?? null));
			}

			$lines[] = $this->csvRow(values: $values);
		}

		return implode("\r\n", $lines) . "\r\n";
	}                 

	   
                                                                  
   
                                                           
   
                                          
   
                                                                                    
    
	public function formatJsonl(array $rows): string {
		$lines = [];
		foreach ($rows as $row) {
			$encoded = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if ($encoded === false) {
				$encoded = '{}';
			}

			$lines[] = $encoded;
		}

		if ($lines === []) {
			return '';
		}

		return implode("\n", $lines) . "\n";
	}                   

	   
                                                                              
   
                                                                             
                                                                             
                                                                            
                                                                            
                                                                       
                                                                        
                     
   
                                                           
   
                                                
   
                                                                                    
    
	public function formatParquet(array $rows): string {
		$columns = $this->collectColumns(rows: $rows);
		$schema = [];
		foreach ($columns as $column) {
			$schema[$column] = $this->inferType(rows: $rows, column: $column);
		}

		$envelope = [
			'format' => 'parquet',
			'version' => '2.0',
			'schema' => $schema,
			'rows' => array_values($rows),
		];

		$encoded = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($encoded === false) {
			return '';
		}

		return $encoded;
	}                     

	   
                                               
   
                                                                        
                                                                            
                                                                              
                  
   
                                        
                                                     
   
                                                     
   
                                                                                    
    
	public function compress(string $contents, string $compression): string {
		switch ($compression) {
			case 'gzip':
				$gz = gzencode($contents, 6);
				if ($gz === false) {
					return $contents;
				}
				return $gz;
			case 'zstd':
				if (function_exists('zstd_compress') === true) {
					return (string)zstd_compress($contents);
				}
				return $contents;
			case 'snappy':
				if (function_exists('snappy_compress') === true) {
					return (string)snappy_compress($contents);
				}
				return $contents;
			case 'none':
			default:
				return $contents;
		}            
	}                

	   
                                                         
   
                                                                            
                                                                           
                                                                
   
                                                           
                                                    
   
                                                               
   
                                                                                    
    
	public function applyRowFilter(array $rows, string $expression): array {
		if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*(!=|<>|==|=)\s*(.+?)\s*$/', $expression, $m) !== 1) {
			return $rows;
		}

		$column = $m[1];
		$operator = $m[2];
		$expected = trim($m[3], " \t'\"");
		$negate = ($operator === '!=' || $operator === '<>');

		$filtered = [];
		foreach ($rows as $row) {
			$actual = $this->stringify(value: ($row[$column] ?? null));
			$equal = ($actual === $expected);
			if (($negate === false && $equal === true) || ($negate === true && $equal === false)) {
				$filtered[] = $row;
			}
		}

		                                                                        
		                                                
		return $filtered;
	}                      

	   
                                                                      
   
                                                           
                                                             
   
                                                                             
                                                                                                                              
   
                                                                                    
    
	public function applyColumnAllowlist(array $rows, array $allowlist): array {
		$allowed = array_fill_keys($allowlist, true);
		$allColumns = $this->collectColumns(rows: $rows);
		$dropped = [];
		foreach ($allColumns as $column) {
			if (isset($allowed[$column]) === false) {
				$dropped[] = $column;
			}
		}

		$masked = [];
		foreach ($rows as $row) {
			$kept = [];
			foreach ($row as $key => $value) {
				if (isset($allowed[$key]) === true) {
					$kept[$key] = $value;
				}
			}

			$masked[] = $kept;
		}

		return [$masked, $dropped];
	}                            

	   
                                                                     
   
                                                                          
                                                                             
                                            
   
                                                                                
                                                                                           
   
                                                                            
   
                                                                                    
    
	public function sensitiveColumnsExported(array $presentColumns, ?array $allowlist): array {
		$sensitive = array_fill_keys(self::SENSITIVE_COLUMNS, true);
		$allowed = null;
		if (is_array($allowlist) === true && $allowlist !== []) {
			$allowed = array_fill_keys(array_map('strtolower', $allowlist), true);
		}

		$exported = [];
		foreach ($presentColumns as $column) {
			$lower = strtolower($column);
			if (isset($sensitive[$lower]) === false) {
				continue;
			}

			                                                                
			                                                                   
			if ($allowed !== null && isset($allowed[$lower]) === false) {
				continue;
			}

			$exported[] = $column;
		}

		return $exported;
	}                                

	   
                                                                          
   
                                                                         
                                                                       
                                                                               
   
                                                           
   
                                                                      
   
                                                                                    
    
	public function addTombstoneMarker(array $rows): array {
		$marked = [];
		foreach ($rows as $row) {
			$deleted = false;
			foreach (['_deleted', 'deleted', 'deletedAt', 'deleted_at'] as $field) {
				if (array_key_exists($field, $row) === true) {
					$value = $row[$field];
					if ($value === true || ($value !== null && $value !== false && $value !== '')) {
						$deleted = true;
						break;
					}
				}
			}

			$row['_deleted'] = $deleted;
			$marked[] = $row;
		}

		return $marked;
	}                          

	   
                                                              
   
                                                           
                                                        
   
                                                                         
                                                                                                                                   
    
	public function maxWatermark(array $rows, string $watermarkColumn): ?string {
		$max = null;
		foreach ($rows as $row) {
			if (array_key_exists($watermarkColumn, $row) === false) {
				continue;
			}

			$value = $this->stringify(value: $row[$watermarkColumn]);
			if ($value === '') {
				continue;
			}

			if ($max === null || strcmp($value, $max) > 0) {
				$max = $value;
			}
		}

		return $max;
	}                    

	   
                                                     
   
                                                                              
                                                                     
   
                                                   
                                         
                                                                      
                                                                     
                                                        
   
                                                                
    
	private function fetchRows(
		string $schemaKey,
		string $mode,
		string $watermarkColumn,
		?string $watermarkFrom,
		?int $rowCap,
	): array {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		$rows = [];
		$offset = 0;
		do {
			$filters = ['register' => $register, 'schema' => $schema];
			$config = [
				'filters' => $filters,
				'limit' => self::PAGE_SIZE,
				'offset' => $offset,
			];

			if ($mode === 'incremental' && $watermarkColumn !== '' && $watermarkFrom !== null && $watermarkFrom !== '') {
				$config['filters'][$watermarkColumn] = ['>' => $watermarkFrom];
			}

			try {
				$page = $this->getObjectService()->findAll(config: $config);
			} catch (\Throwable $e) {
				break;
			}

			$page = ($page ?? []);
			foreach ($page as $object) {
				$rows[] = $this->toArray(object: $object);
				if ($rowCap !== null && count($rows) >= $rowCap) {
					return $rows;
				}
			}

			$offset += self::PAGE_SIZE;
		} while (count($page) === self::PAGE_SIZE);

		return $rows;
	}                 

	   
                                                                        
   
                                                           
   
                                                        
    
	private function collectColumns(array $rows): array {
		$columns = [];
		foreach ($rows as $row) {
			foreach (array_keys($row) as $key) {
				$columns[(string)$key] = true;
			}
		}

		return array_keys($columns);
	}                      

	   
                                                                          
   
                                                           
   
                                                         
                                                                                                                                   
    
	public function columnDefinitions(array $rows): array {
		$columns = $this->collectColumns(rows: $rows);
		$definitions = [];
		foreach ($columns as $column) {
			$definitions[$column] = $this->inferType(rows: $rows, column: $column);
		}

		return $definitions;
	}                         

	   
                                                               
   
                                                           
                                     
   
                                                             
    
	private function inferType(array $rows, string $column): string {
		$type = null;
		$nullable = false;
		foreach ($rows as $row) {
			if (array_key_exists($column, $row) === false || $row[$column] === null) {
				$nullable = true;
				continue;
			}

			$value = $row[$column];
			$current = 'string';
			if (is_bool($value) === true) {
				$current = 'boolean';
			} elseif (is_int($value) === true) {
				$current = 'integer';
			} elseif (is_float($value) === true) {
				$current = 'double';
			} elseif (is_array($value) === true) {
				$current = 'object';
			}

			if ($type === null) {
				$type = $current;
			} elseif ($type !== $current) {
				$type = 'string';
			}
		}             

		if ($type === null) {
			$type = 'string';
		}

		if ($nullable === true) {
			return $type . '|null';
		}

		return $type;
	}                 

	   
                                                                                
   
                                                       
   
                                                        
    
	private function csvRow(array $values): string {
		$escaped = [];
		foreach ($values as $value) {
			if (preg_match('/[",\r\n]/', $value) === 1) {
				$value = '"' . str_replace('"', '""', $value) . '"';
			}

			$escaped[] = $value;
		}

		return implode(',', $escaped);
	}              

	   
                                                                                 
   
                                  
   
                                   
    
	private function stringify(mixed $value): string {
		if ($value === null) {
			return '';
		}

		if (is_bool($value) === true) {
			if ($value === true) {
				return 'true';
			}

			return 'false';
		}

		if (is_array($value) === true) {
			$encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if ($encoded === false) {
				return '';
			}

			return $encoded;
		}

		return (string)$value;
	}                 
}           
