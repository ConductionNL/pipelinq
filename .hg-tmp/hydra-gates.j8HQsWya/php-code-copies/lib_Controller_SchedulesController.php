<?php

   
                                
  
                                                                           
                                                 
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                              
  
                                                                    
                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ScheduledTaskService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

   
                            
  
                                                                          
                                                                          
                                                                    
  
                                                  
  
                                                              
   
class SchedulesController extends Controller {
	   
                
   
                                         
                                                                       
                                                     
                                                  
                                          
    
	public function __construct(
		IRequest $request,
		private ScheduledTaskService $scheduledTaskService,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                         
   
                      
   
                                                 
   
                                                               
    
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$this->scheduledTaskService->applyAcceptLanguage(
			acceptLanguage: $this->request->getHeader('Accept-Language')
		);

		try {
			$userId = $user->getUID();
			$isAdmin = $this->groupManager->isAdmin($userId);

			                                                                 
			                                                          
			$requestedAssignee = (string)$this->request->getParam('assigneeUserId', '');
			if ($isAdmin === false) {
				                                                               
				                                                               
				$requestedAssignee = $userId;
			}

			$params = [
				'status' => $this->request->getParam('status', ''),
				'assigneeUserId' => $requestedAssignee,
				'assigneeGroupId' => $this->request->getParam('assigneeGroupId', ''),
				'from' => $this->request->getParam('from', ''),
				'to' => $this->request->getParam('to', ''),
				'_page' => (int)$this->request->getParam('_page', 1),
				'_limit' => (int)$this->request->getParam('_limit', 20),
			];

			$result = $this->scheduledTaskService->getScheduledTasks($params);

			return new JSONResponse($result);
		} catch (\Throwable $e) {
			$this->logger->error(
				'SchedulesController::index failed',
				['exception' => $e]
			);
			return new JSONResponse(
				['message' => 'Operation failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}         
	}             

	   
                            
   
                       
   
                                                                     
   
                                                               
    
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$type = (string)$this->request->getParam('type', '');
		$subject = (string)$this->request->getParam('subject', '');
		$deadline = (string)$this->request->getParam('deadline', '');

		if ($type === ''
			|| in_array($type, ScheduledTaskService::VALID_TYPES, true) === false
		) {
			return new JSONResponse(
				['message' => 'Invalid input'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($subject === '' || $deadline === '') {
			return new JSONResponse(
				['message' => 'Invalid input'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$userId = $user->getUID();
		$data = $this->buildScheduledTaskData(
			type: $type,
			subject: $subject,
			deadline: $deadline,
			userId: $userId,
			isAdmin: $this->groupManager->isAdmin($userId)
		);

		try {
			$created = $this->scheduledTaskService->createScheduledTask($data);
			return new JSONResponse($created, Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(
				['message' => 'Invalid input'],
				Http::STATUS_BAD_REQUEST
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'SchedulesController::create failed',
				['exception' => $e]
			);
			return new JSONResponse(
				['message' => 'Operation failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}         
	}              

	   
                                                            
   
                                                                        
                                                  
   
                                                
                                                 
                                                   
                                                 
                                                              
   
                                                                      
    
	private function buildScheduledTaskData(
		string $type,
		string $subject,
		string $deadline,
		string $userId,
		bool $isAdmin,
	): array {
		$requestedAssignee = (string)$this->request->getParam('assigneeUserId', '');
		                                                                          
		if ($isAdmin === false && $requestedAssignee !== '' && $requestedAssignee !== $userId) {
			$requestedAssignee = $userId;
		}

		$data = [
			'type' => $type,
			'subject' => $subject,
			'deadline' => $deadline,
			'description' => (string)$this->request->getParam('description', ''),
			'priority' => (string)$this->request->getParam('priority', 'normal'),
			'assigneeUserId' => $requestedAssignee,
			'assigneeGroupId' => (string)$this->request->getParam('assigneeGroupId', ''),
			'clientId' => (string)$this->request->getParam('clientId', ''),
			'requestId' => (string)$this->request->getParam('requestId', ''),
			'callbackPhoneNumber' => (string)$this->request->getParam('callbackPhoneNumber', ''),
			'preferredTimeSlot' => (string)$this->request->getParam('preferredTimeSlot', ''),
		];

		                                                                   
		$optionalFields = [
			'description',
			'assigneeUserId',
			'assigneeGroupId',
			'clientId',
			'requestId',
			'callbackPhoneNumber',
			'preferredTimeSlot',
		];
		foreach ($optionalFields as $optional) {
			if ($data[$optional] === '') {
				unset($data[$optional]);
			}
		}

		return $data;
	}                              

	   
                                   
   
                              
   
                                                                       
   
                                                               
    
	#[NoAdminRequired]
	public function pending(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$window = (int)$this->request->getParam('window', 60);
			if ($window > 1440) {
				$window = 1440;
			}

			if ($window < 1) {
				$window = 1;
			}

			$userId = $user->getUID();
			$isAdmin = $this->groupManager->isAdmin($userId);
			$items = $this->scheduledTaskService->getPendingTasks($window);

			                                               
			if ($isAdmin === false) {
				$items = array_values(
					array_filter(
						$items,
						static function (array $task) use ($userId): bool {
							return ($task['assigneeUserId'] ?? '') === $userId;
						}
					)
				);
			}

			return new JSONResponse(
				[
					'items' => $items,
					'total' => count($items),
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'SchedulesController::pending failed',
				['exception' => $e]
			);
			return new JSONResponse(
				['message' => 'Operation failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}         
	}               

	   
                                
   
                           
   
                                    
   
                                         
   
                                                               
    
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$this->scheduledTaskService->applyAcceptLanguage(
			acceptLanguage: $this->request->getHeader('Accept-Language')
		);

		try {
			$task = $this->scheduledTaskService->getScheduledTask($id);

			                                            
			$userId = $user->getUID();
			$isAdmin = $this->groupManager->isAdmin($userId);
			if ($isAdmin === false && ($task['assigneeUserId'] ?? '') !== $userId) {
				return new JSONResponse(
					['message' => 'Not found'],
					Http::STATUS_NOT_FOUND
				);
			}

			return new JSONResponse($task);
		} catch (\RuntimeException $e) {
			return new JSONResponse(
				['message' => 'Not found'],
				Http::STATUS_NOT_FOUND
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'SchedulesController::show failed',
				['exception' => $e]
			);
			return new JSONResponse(
				['message' => 'Operation failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}         
	}            

	   
                            
   
                           
   
                                    
   
                                                               
   
                                                               
    
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		try {
			$task = $this->scheduledTaskService->getScheduledTask($id);
		} catch (\RuntimeException $e) {
			return new JSONResponse(
				['message' => 'Not found'],
				Http::STATUS_NOT_FOUND
			);
		}

		$user = $this->userSession->getUser();
		$userId = '';
		if ($user !== null) {
			$userId = $user->getUID();
		}

		try {
			$this->scheduledTaskService->authorizeTaskMutation($task, $userId);
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(
				['message' => 'Not authorized'],
				Http::STATUS_FORBIDDEN
			);
		}

		$payload = $this->request->getParams();
		unset($payload['id'], $payload['_route']);

		                                                                         
		$isAdmin = $this->groupManager->isAdmin($userId);
		if ($isAdmin === false) {
			unset(
				$payload['assigneeUserId'],
				$payload['assigneeGroupId'],
				$payload['status'],
				$payload['createdAt'],
				$payload['attempts']
			);
		}

		try {
			$updated = $this->scheduledTaskService->updateScheduledTask($id, $payload);
			return new JSONResponse($updated);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(
				['message' => 'Invalid input'],
				Http::STATUS_BAD_REQUEST
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'SchedulesController::update failed',
				['exception' => $e]
			);
			return new JSONResponse(
				['message' => 'Operation failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}         
	}              

	   
                                     
   
                              
   
                                    
   
                                                             
   
                                                               
    
	#[NoAdminRequired]
	public function destroy(string $id): JSONResponse {
		try {
			$task = $this->scheduledTaskService->getScheduledTask($id);
		} catch (\RuntimeException $e) {
			return new JSONResponse(
				['message' => 'Not found'],
				Http::STATUS_NOT_FOUND
			);
		}

		$user = $this->userSession->getUser();
		$userId = '';
		if ($user !== null) {
			$userId = $user->getUID();
		}

		try {
			$this->scheduledTaskService->authorizeTaskMutation($task, $userId);
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(
				['message' => 'Not authorized'],
				Http::STATUS_FORBIDDEN
			);
		}

		try {
			$this->scheduledTaskService->deleteScheduledTask($id);
			return new JSONResponse([], Http::STATUS_NO_CONTENT);
		} catch (\Throwable $e) {
			$this->logger->error(
				'SchedulesController::destroy failed',
				['exception' => $e]
			);
			return new JSONResponse(
				['message' => 'Operation failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}         
	}               
}           
