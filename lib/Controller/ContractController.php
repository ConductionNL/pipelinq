<?php

/**
 * Pipelinq ContractController.
 *
 * Exposes ONLY the app-logic operations that plain OpenRegister CRUD cannot do
 * (ADR-022): guarded lifecycle transitions, contract numbering, successor
 * drafting, and recurring-revenue metrics. Plain contract reads/writes go
 * through OpenRegister directly via the frontend `useObjectStore`.
 *
 * Every mutating endpoint loads the target contract, verifies the caller is the
 * owner or an admin/sales-group member (per-object authorization — no IDOR,
 * ADR-005 Rule 3), then applies the guarded transition.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-contract-lifecycle-management
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ContractService;
use OCA\Pipelinq\Service\RecurringRevenueService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Contract lifecycle + recurring-revenue controller.
 *
 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-contract-lifecycle-management
 */
class ContractController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ContractService $contractService Contract lifecycle service.
	 * @param RecurringRevenueService $revenueService Recurring-revenue service.
	 * @param IUserSession $userSession The user session.
	 * @param ObjectOwnerAccessPolicy $accessPolicy Per-object owner authorization.
	 * @param ContainerInterface $container The DI container.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		IRequest $request,
		private ContractService $contractService,
		private RecurringRevenueService $revenueService,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $accessPolicy,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Create a contract with an auto-generated unique contractNumber.
	 *
	 * The created contract is owned by the supplied ownerId, defaulting to the
	 * current user. Any authenticated user may create a contract they own.
	 *
	 * @return JSONResponse The created contract or an error.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-contract-lifecycle-management
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->request->getParams();
		unset($body['_route']);

		if (trim((string)($body['title'] ?? '')) === '' || trim((string)($body['clientRef'] ?? '')) === '') {
			return new JSONResponse(['message' => 'title and clientRef are required'], Http::STATUS_BAD_REQUEST);
		}

		$existing = $this->contractService->loadAll();
		$body['contractNumber'] = $this->contractService->generateContractNumber($existing);
		if (trim((string)($body['ownerId'] ?? '')) === '') {
			$body['ownerId'] = $user->getUID();
		}

		if (trim((string)($body['status'] ?? '')) === '') {
			$body['status'] = 'draft';
		}

		$saved = $this->contractService->save($body, null);
		if ($saved === null) {
			return new JSONResponse(['message' => 'Failed to save contract'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($saved, Http::STATUS_CREATED);
	}//end create()

	/**
	 * Apply a guarded lifecycle transition to a contract.
	 *
	 * Loads the contract by id, authorizes the caller (owner or privileged
	 * group — no IDOR), validates the transition through ContractService, and
	 * persists it. A won renewal (`renewed`) drafts a successor contract.
	 *
	 * @param string $id The contract UUID.
	 *
	 * @return JSONResponse The updated contract or an error.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-contract-lifecycle-management
	 */
	#[NoAdminRequired]
	public function transition(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$newStatus = (string)$this->request->getParam('status', '');
		$reason = (string)$this->request->getParam('cancellationReason', '');

		$contract = $this->loadContract(id: $id);
		if ($contract === null) {
			return new JSONResponse(['message' => 'Contract not found'], Http::STATUS_NOT_FOUND);
		}

		if ($this->isAuthorized(uid: $user->getUID(), contract: $contract) === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		if ($reason !== '') {
			$contract['cancellationReason'] = $reason;
		}

		try {
			$this->contractService->assertTransitionAllowed($contract, $newStatus, false);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$contract['status'] = $newStatus;
		$saved = $this->contractService->save($contract, $id);
		if ($saved === null) {
			return new JSONResponse(['message' => 'Failed to save contract'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		// A manually-applied `renewed` transition also drafts the successor.
		if ($newStatus === 'renewed') {
			$successor = $this->contractService->buildSuccessorDraft($saved);
			$this->contractService->save($successor, null);
		}

		return new JSONResponse($saved);
	}//end transition()

	/**
	 * Recurring-revenue summary (MRR/ARR + counts) for dashboard widgets.
	 *
	 * @return JSONResponse The summary.
	 *
	 * @no-admin-idor-exempt returns tenant-wide aggregate metrics only (MRR/ARR/counts);
	 *                       takes no caller-supplied object id, exposes no per-object data.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-recurring-revenue-roll-up
	 */
	#[NoAdminRequired]
	public function summary(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse($this->revenueService->getSummary());
	}//end summary()

	/**
	 * Renewal metrics (rate + churned MRR) for a period.
	 *
	 * @return JSONResponse The renewal metrics.
	 *
	 * @no-admin-idor-exempt returns tenant-wide period aggregates only (renewal rate /
	 *                       churned MRR); takes a date range, not an object id.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-recurring-revenue-roll-up
	 */
	#[NoAdminRequired]
	public function renewalMetrics(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$from = (string)$this->request->getParam('from', date('Y-m-d', strtotime('-3 months')));
		$to = (string)$this->request->getParam('to', date('Y-m-d'));

		$contracts = $this->contractService->loadAll();
		return new JSONResponse($this->revenueService->computeRenewalMetrics($contracts, $from, $to));
	}//end renewalMetrics()

	/**
	 * Load a contract object by UUID via OpenRegister.
	 *
	 * @param string $id The UUID.
	 *
	 * @return array<string,mixed>|null The contract, or null when absent/unconfigured.
	 */
	private function loadContract(string $id): ?array {
		[$registerId, $schemaId] = $this->contractService->getRegisterAndSchema();
		if ($registerId === '' || $schemaId === '') {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			// Named arguments are load-bearing: ObjectService::find()'s second and
			// third parameters are `?array $_extend` and `bool $files`, so the
			// positional form passed the register id into $_extend and raised a
			// TypeError that the catch below swallowed into a 404 — for the owner
			// as well as for a stranger. See #801.
			$object = $objectService->find(
				id: $id,
				register: $registerId,
				schema: $schemaId,
			);
			if ($object === null) {
				return null;
			}

			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				return $object->jsonSerialize();
			}

			return (array)$object;
		} catch (Throwable $e) {
			$this->logger->warning('ContractController: loadContract failed', ['error' => $e->getMessage()]);
			return null;
		}//end try
	}//end loadContract()

	/**
	 * Per-object authorization: the caller must own the contract or belong to a
	 * privileged group (admin/sales). Prevents IDOR on the transition endpoint.
	 *
	 * @param string $uid The caller user ID.
	 * @param array<string,mixed> $contract The target contract.
	 *
	 * @return bool True when the caller may mutate this contract.
	 */
	private function isAuthorized(string $uid, array $contract): bool {
		return $this->accessPolicy->mayAccess(uid: $uid, object: $contract, ownerField: 'ownerId');
	}//end isAuthorized()
}//end class
