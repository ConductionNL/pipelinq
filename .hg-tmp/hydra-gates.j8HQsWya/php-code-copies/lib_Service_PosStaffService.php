<?php

   
                            
  
                                                                              
                                                                               
                                                                           
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                             
  
                                                                          
                                                                            
                                                                              
                                                                      
  
                                                                              
                                                                            
                                                                             
                                                                                  
                                   
                                                                              
                                                                    
  
                                                              
   
class PosStaffService {
	   
                                                                       
   
            
    
	public const LOCKOUT_THRESHOLD = 5;

	   
                                             
   
            
    
	public const LOCKOUT_SECONDS = 900;

	   
                                                                        
                        
   
            
    
	public const BCRYPT_COST = 12;

	   
                
   
                                                
                                                                                       
                                              
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private PosRoleService $posRoleService,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                               
   
                                                                               
   
                                                                 
    
	public function listStaff(): array {
		[$register, $schema] = $this->config(schemaKey: 'posStaff_schema');

		$results = $this->getObjectService()->findAll(
			config: [
				'filters' => [
					'register' => $register,
					'schema' => $schema,
				],
				'limit' => 2000,
			]
		);

		$out = [];
		foreach ($results as $result) {
			$out[] = $this->stripSensitive(staff: $this->toArray(object: $result));
		}

		return $out;
	}                 

	   
                                                                 
   
                                     
   
                                                                  
   
                                                           
   
                                                                 
    
	public function getStaff(string $id): array {
		$staff = $this->fetchStaff(id: $id);
		return $this->stripSensitive(staff: $staff);
	}                

	   
                                       
   
                                                                          
                                                                           
                                                                            
                              
   
                                                     
                                                
   
                                                                    
   
                                                                                                          
   
                                                                 
    
	public function saveStaff(array $data, string $id = ''): array {
		$displayName = trim((string)($data['displayName'] ?? ''));
		if ($displayName === '') {
			throw new OCSBadRequestException('Naam is verplicht.');
		}

		$posRole = trim((string)($data['posRole'] ?? ''));
		if ($posRole === '') {
			throw new OCSBadRequestException('Rol is verplicht.');
		}

		                      
		$this->posRoleService->getRole(id: $posRole);

		[$existing, $isUpdate] = $this->resolveExistingStaff(id: $id);

		$hash = $this->resolvePinHash(data: $data, isUpdate: $isUpdate, existing: $existing);

		$payload = $this->buildStaffPayload(
			data: $data,
			posRole: $posRole,
			displayName: $displayName,
			hash: $hash,
			existing: $existing
		);

		[$register, $schema] = $this->config(schemaKey: 'posStaff_schema');

		$uuid = $id;
		if ($uuid === '') {
			$uuid = $this->uuid();
		}

		$saved = $this->getObjectService()->saveObject(
			object: $payload,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $uuid
		);

		return $this->stripSensitive(staff: $this->toArray(object: $saved));
	}                 

	   
                                                                      
   
                                                                   
                           
   
                                                
   
                                                                          
    
	private function resolveExistingStaff(string $id): array {
		$isUpdate = ($id !== '');
		$existing = null;
		if ($isUpdate === true) {
			try {
				$existing = $this->fetchStaff(id: $id);
			} catch (OCSNotFoundException $e) {
				$existing = null;
				$isUpdate = false;
			}
		}

		return [$existing, $isUpdate];
	}                            

	   
                                                          
   
                                                                   
                                                                        
   
                                                     
                                                    
                                                                             
   
                                                 
   
                                                                                      
    
	private function resolvePinHash(array $data, bool $isUpdate, ?array $existing): string {
		$pin = (string)($data['pin'] ?? '');
		$hash = '';
		if ($isUpdate === true && $existing !== null) {
			$hash = (string)($existing['pinHash'] ?? '');
		}

		if ($pin !== '') {
			if ($this->isValidPinFormat(pin: $pin) === false) {
				throw new OCSBadRequestException('PIN moet 4 tot 6 cijfers bevatten.');
			}

			                                                                
			                                                             
			                                                 
			$hash = password_hash($pin, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
		}

		if ($isUpdate === false && $hash === '') {
			throw new OCSBadRequestException('PIN is verplicht bij het aanmaken van een medewerker.');
		}

		return $hash;
	}                      

	   
                                                       
   
                                                     
                                            
                                                        
                                             
                                                                             
   
                                                     
    
	private function buildStaffPayload(array $data, string $posRole, string $displayName, string $hash, ?array $existing): array {
		$payload = [
			'displayName' => $displayName,
			'userId' => (string)($data['userId'] ?? ($existing['userId'] ?? '')),
			'posRole' => $posRole,
			'pinHash' => $hash,
			'isActive' => (bool)($data['isActive'] ?? ($existing['isActive'] ?? true)),
			'failedPinAttempts' => (int)($existing['failedPinAttempts'] ?? 0),
		];

		if (isset($existing['lockedUntil']) === true && $existing['lockedUntil'] !== '') {
			$payload['lockedUntil'] = (string)$existing['lockedUntil'];
		}

		if (isset($existing['lastLoginAt']) === true && $existing['lastLoginAt'] !== '') {
			$payload['lastLoginAt'] = (string)$existing['lastLoginAt'];
		}

		return $payload;
	}                         

	   
                          
   
                                     
   
                
   
                                                   
   
                                                                 
    
	public function deleteStaff(string $id): void {
		                                                               
		$this->fetchStaff(id: $id);

		[$register, $schema] = $this->config(schemaKey: 'posStaff_schema');

		try {
			$this->getObjectService()->deleteObject(uuid: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: failed to delete posStaff', ['exception' => $e->getMessage()]);
			throw new OCSNotFoundException('Medewerker kon niet worden verwijderd.');
		}
	}                   

	   
                                                     
   
                                                                         
                                                                            
                                                                         
                                                             
   
                                                          
                                                    
   
                                                                                                                                   
   
                                                           
                                                                                                          
   
                                                                 
    
	public function validatePin(string $staffId, string $pin): array {
		if ($this->isValidPinFormat(pin: $pin) === false) {
			throw new OCSForbiddenException('PIN onjuist.');
		}

		$staff = $this->fetchStaff(id: $staffId);

		if ((bool)($staff['isActive'] ?? true) === false) {
			throw new OCSForbiddenException('Account is gedeactiveerd.');
		}

		$lockedUntil = (string)($staff['lockedUntil'] ?? '');
		if ($lockedUntil !== '' && $this->isStillLocked(lockedUntil: $lockedUntil) === true) {
			throw new OCSForbiddenException('Account is geblokkeerd. Probeer het later opnieuw.');
		}

		$hash = (string)($staff['pinHash'] ?? '');
		if ($hash === '' || password_verify($pin, $hash) === false) {
			$this->incrementFailedAttempts(staff: $staff);
			throw new OCSForbiddenException('PIN onjuist.');
		}

		                                                        
		$this->markLoginSuccess(staff: $staff);

		$permissions = $this->resolvePermissions(roleId: (string)($staff['posRole'] ?? ''));

		return [
			'staffId' => (string)($staff['id'] ?? $staffId),
			'displayName' => (string)($staff['displayName'] ?? ''),
			'permissions' => $permissions,
			'expiresAt' => (new DateTimeImmutable('+8 hours'))->format(DateTimeInterface::ATOM),
		];
	}                   

	   
                                                                                  
   
                                                                           
                                                                  
                     
   
                                          
   
                                                       
   
                                                           
   
                                                                 
    
	public function getPermissions(string $staffId): array {
		$staff = $this->fetchStaff(id: $staffId);
		return $this->resolvePermissions(roleId: (string)($staff['posRole'] ?? ''));
	}                      

	   
                                                                         
   
                                                                
                                                                           
                                                                           
                                                                           
                                                                          
                                                                   
                                             
   
                                          
                                                                             
   
                                                             
   
                                                                                
   
                                                                 
    
	public function authorizeStaff(string $staffId, string $userId): array {
		$staff = $this->fetchStaff(id: $staffId);
		unset($userId);
		                                                                       
		return $staff;
	}                      

	   
                                                                            
   
                                     
   
                                                      
   
                                              
    
	private function fetchStaff(string $id): array {
		[$register, $schema] = $this->config(schemaKey: 'posStaff_schema');

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new OCSNotFoundException('Medewerker niet gevonden.');
		}

		$staff = $this->toArray(object: $object);
		$staff['id'] = (string)($staff['id'] ?? $id);

		return $staff;
	}                  

	   
                                                                    
   
                                                            
   
                                                                  
    
	private function stripSensitive(array $staff): array {
		unset($staff['pinHash']);
		return $staff;
	}                      

	   
                                                                      
   
                                           
   
                                                               
    
	private function isValidPinFormat(string $pin): bool {
		return preg_match('/^[0-9]{4,6}$/', $pin) === 1;
	}                        

	   
                                                           
   
                                                                       
                                                       
   
                                           
   
                                                       
    
	private function resolvePermissions(string $roleId): array {
		if ($roleId === '') {
			return $this->emptyPermissions();
		}

		try {
			$role = $this->posRoleService->getRole(id: $roleId);
		} catch (\Throwable $e) {
			return $this->emptyPermissions();
		}

		return [
			'canVoid' => (bool)($role['canVoid'] ?? false),
			'maxDiscountPercent' => (int)($role['maxDiscountPercent'] ?? 0),
			'canRefund' => (bool)($role['canRefund'] ?? false),
			'canNoSale' => (bool)($role['canNoSale'] ?? false),
			'roleId' => $roleId,
			'roleName' => (string)($role['name'] ?? ''),
		];
	}                          

	   
                                       
   
                                                  
    
	private function emptyPermissions(): array {
		return [
			'canVoid' => false,
			'maxDiscountPercent' => 0,
			'canRefund' => false,
			'canNoSale' => false,
			'roleId' => '',
			'roleName' => '',
		];
	}                        

	   
                                                                               
   
                                                  
   
                                                           
    
	private function isStillLocked(string $lockedUntil): bool {
		try {
			$lockTime = new DateTimeImmutable($lockedUntil);
		} catch (\Throwable $e) {
			return false;
		}

		return $lockTime > new DateTimeImmutable();
	}                     

	   
                                                                             
   
                                                                
   
                
    
	private function incrementFailedAttempts(array $staff): void {
		$current = (int)($staff['failedPinAttempts'] ?? 0);
		$next = ($current + 1);

		$update = [
			'failedPinAttempts' => $next,
		];

		if ($next >= self::LOCKOUT_THRESHOLD) {
			$update['lockedUntil'] = (new DateTimeImmutable('+' . self::LOCKOUT_SECONDS . ' seconds'))
				->format(DateTimeInterface::ATOM);
			$update['failedPinAttempts'] = 0;
		}

		$this->updateStaffFields(staff: $staff, fields: $update);
	}                               

	   
                                                                            
   
                                                                
   
                
    
	private function markLoginSuccess(array $staff): void {
		$this->updateStaffFields(
			staff: $staff,
			fields: [
				'failedPinAttempts' => 0,
				'lockedUntil' => '',
				'lastLoginAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			]
		);
	}                        

	   
                                               
   
                                                                                                                 
                                                                
   
                
    
	private function updateStaffFields(array $staff, array $fields): void {
		$id = (string)($staff['id'] ?? '');
		if ($id === '') {
			return;
		}

		$payload = [
			'displayName' => (string)($staff['displayName'] ?? ''),
			'userId' => (string)($staff['userId'] ?? ''),
			'posRole' => (string)($staff['posRole'] ?? ''),
			'pinHash' => (string)($staff['pinHash'] ?? ''),
			'isActive' => (bool)($staff['isActive'] ?? true),
			'failedPinAttempts' => (int)($staff['failedPinAttempts'] ?? 0),
			'lockedUntil' => (string)($staff['lockedUntil'] ?? ''),
			'lastLoginAt' => (string)($staff['lastLoginAt'] ?? ''),
		];

		foreach ($fields as $key => $value) {
			$payload[$key] = $value;
		}

		try {
			[$register, $schema] = $this->config(schemaKey: 'posStaff_schema');
			$this->getObjectService()->saveObject(
				object: $payload,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $id
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: failed to update posStaff', ['exception' => $e->getMessage()]);
		}
	}                         

	   
                                                             
   
                                                       
   
                                                                   
   
                                                                             
    
	private function config(string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('POS register of schema is niet geconfigureerd.');
		}

		return [$register, $schema];
	}              

	   
                                       
   
                                      
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        

	   
                                              
   
                                       
   
                                                        
    
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return (array)$object;
	}               

	   
                       
   
                            
    
	private function uuid(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}            
}           
