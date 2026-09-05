<?php

/**
 * Pipelinq ActivityTimelineController.
 *
 * REST controller for the merged activity timeline and worklog endpoints.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/activity-timeline/tasks.md#task-2
 * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-entries-must-be-available-via-api
 * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-support-manual-entries
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ActivityTimelineService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller exposing /api/timeline and /api/worklog endpoints.
 *
 * Authentication is mandated by @NoAdminRequired (no @PublicPage). Error
 * responses use static messages — internal exception details are logged but
 * never returned to the caller.
 *
 * @spec openspec/specs/activity-timeline/spec.md#requirement-every-entity-must-have-a-timeline-view
 */
class ActivityTimelineController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ActivityTimelineService $service The activity timeline service.
	 * @param IUserSession $userSession The user session.
	 * @param LoggerInterface $logger The logger.
	 * @param ContainerInterface $container The DI container.
	 * @param ObjectOwnerAccessPolicy $policy Per-object owner access policy.
	 */
	public function __construct(
		IRequest $request,
		private ActivityTimelineService $service,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private ContainerInterface $container,
		private ObjectOwnerAccessPolicy $policy,
	) {
		// @PublicPage — DI constructor (not HTTP-routable). The actual auth
		// posture for each endpoint lives on its own method attribute; this
		// ctor is wired by the Nextcloud app framework and never serves a
		// request.
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Verify that the underlying OR object exists and is accessible.
	 *
	 * Returns true if the object is found. Returns false on a clean 404 and
	 * **false on any service error**.
	 *
	 * ⚠️ This used to `return true` from the catch, described as "fails open so
	 * a temporary OR outage does not block the timeline surface". That makes an
	 * unavailable object service indistinguishable from a successful check —
	 * CWE-863, and it is the only thing standing between a caller-supplied
	 * `entityId` and someone else's merged contactmoment/worklog/note history
	 * (#801). Availability is not worth trading for it: a failed check now
	 * denies, and the warning still records the outage.
	 *
	 * @param string $entityId The OR object UUID.
	 * @param string $userId The uid the access check is made for.
	 *
	 * @return bool Whether the object could be verified.
	 */
	private function objectAccessible(string $entityId, string $userId): bool {
		// Availability established before the reach (ADR-083) — the lookup names
		// OpenRegister only as a string, so without this the dependency is
		// declared nowhere a reader or a gate can see it. This path already
		// treats an unavailable OpenRegister as "cannot verify" and denies, so
		// the absent case returns false rather than throwing. Converting to a
		// typed constructor property is the ADR's preferred shape: pipelinq#1160.
		if (class_exists('\OCA\OpenRegister\Service\ObjectService') === false) {
			return false;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$object = $objectService->find($entityId, []);
			if ($object === null) {
				return false;
			}

			// EXISTENCE IS NOT AUTHORIZATION.
			//
			// This used to `return $object !== null`, which answers "does this
			// id name something" — a question every authenticated caller can
			// ask about every id in the instance. The find() above is also
			// deliberately unscoped (no register, no schema), so it resolves
			// across every magic table; that widens the reach of a bare
			// existence check rather than narrowing it.
			//
			// `_rbac: true` is NOT the fix here: DEFAULT_CLOSED_WRITE_ACTIONS
			// is ['create','update','delete'], so a READ returns true whatever
			// the flag says (ConductionNL/.github#372). The decision has to be
			// made by this app.
			// ownerField is this app's convention for a timeline subject; when
			// the schema has no such field mayAccess() falls through to the
			// privileged-group check, which is the only answer available for
			// the 23 of 27 schemas that record no owner at all.
			// The object service is pulled from the container BY STRING, so
			// this seam is untyped: OpenRegister's find() hands back an
			// ObjectEntity, but nothing here can enforce that and a plain array
			// is what several doubles (and older OR versions) return. Calling
			// jsonSerialize() unconditionally fataled on the array shape, and
			// the catch below turned that into a silent "deny" — a 404 that
			// looked like an authorization decision and was really a type
			// error.
			$payload = $object;
			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$payload = $object->jsonSerialize();
			}

			// A NEW name rather than reusing `$object`: that variable still holds
			// the service's return value a few lines up, and re-binding it inside
			// an IDOR guard makes the reader re-derive which shape is in scope.
			// Written as plain assignments rather than a ternary because phpcs
			// forbids the inline IF and phpmd forbids the else.
			$attributes = [];
			if (is_array($payload) === true) {
				$attributes = $payload;
			}

			return $this->policy->mayAccess(
				uid: $userId,
				object: $attributes,
				ownerField: 'ownerId'
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ActivityTimelineController: could not verify object access, denying',
				['entityId' => $entityId, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end objectAccessible()

	/**
	 * Return the merged activity timeline for an entity.
	 *
	 * Reads `entityType`, `entityId`, `from`, `to`, `types[]`, `_page`, `_limit`
	 * from the request.
	 *
	 * @return JSONResponse The merged timeline or an error response.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-entries-must-be-available-via-api
	 */
	public function getTimeline(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$entityType = (string)$this->request->getParam('entityType', '');
		$entityId = (string)$this->request->getParam('entityId', '');

		if ($entityType === '' || $entityId === '') {
			return new JSONResponse(
				['message' => 'entityType and entityId are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		// 404 rather than 403 on purpose: a caller who may not see the entity
		// must not be able to tell "exists but forbidden" from "does not
		// exist", or the endpoint becomes an existence oracle over every id in
		// the instance.
		if ($this->objectAccessible(entityId: $entityId, userId: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Entity not found'], Http::STATUS_NOT_FOUND);
		}

		$params = [
			'from' => $this->request->getParam('from'),
			'to' => $this->request->getParam('to'),
			'types' => $this->request->getParam('types'),
			'_page' => $this->request->getParam('_page'),
			'_limit' => $this->request->getParam('_limit'),
		];

		try {
			$result = $this->service->getTimeline(
				entityType: $entityType,
				entityId: $entityId,
				params: $params
			);
			return new JSONResponse($result);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ActivityTimelineController: failed to load timeline',
				[
					'exception' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);
			return new JSONResponse(
				['message' => 'Failed to load timeline'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end getTimeline()

	/**
	 * Return worklog entries (contactmomenten with channel=worklog) for an entity.
	 *
	 * @return JSONResponse The paginated worklog or an error response.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-support-manual-entries
	 */
	public function getWorklog(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$entityType = (string)$this->request->getParam('entityType', '');
		$entityId = (string)$this->request->getParam('entityId', '');

		if ($entityType === '' || $entityId === '') {
			return new JSONResponse(
				['message' => 'entityType and entityId are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		// 404 rather than 403 on purpose: a caller who may not see the entity
		// must not be able to tell "exists but forbidden" from "does not
		// exist", or the endpoint becomes an existence oracle over every id in
		// the instance.
		if ($this->objectAccessible(entityId: $entityId, userId: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Entity not found'], Http::STATUS_NOT_FOUND);
		}

		$params = [
			'_page' => $this->request->getParam('_page'),
			'_limit' => $this->request->getParam('_limit'),
		];

		try {
			$result = $this->service->getWorklog(
				entityType: $entityType,
				entityId: $entityId,
				params: $params
			);
			return new JSONResponse($result);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ActivityTimelineController: failed to load worklog',
				[
					'exception' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);
			return new JSONResponse(
				['message' => 'Failed to load worklog'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end getWorklog()

	/**
	 * Create a worklog entry for an entity.
	 *
	 * @return JSONResponse The created worklog or an error response.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-support-manual-entries
	 */
	public function createWorklog(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$entityType = (string)$this->request->getParam('entityType', '');
		$entityId = (string)$this->request->getParam('entityId', '');
		$duration = (string)$this->request->getParam('duration', '');

		if ($entityType === '' || $entityId === '' || $duration === '') {
			return new JSONResponse(
				['message' => 'entityType, entityId and duration are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		// 404 rather than 403 on purpose: a caller who may not see the entity
		// must not be able to tell "exists but forbidden" from "does not
		// exist", or the endpoint becomes an existence oracle over every id in
		// the instance.
		if ($this->objectAccessible(entityId: $entityId, userId: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Entity not found'], Http::STATUS_NOT_FOUND);
		}

		$data = [
			'duration' => $duration,
			'description' => $this->request->getParam('description'),
			'date' => $this->request->getParam('date'),
		];

		try {
			$created = $this->service->createWorklog(
				entityType: $entityType,
				entityId: $entityId,
				data: $data
			);
			return new JSONResponse($created, Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ActivityTimelineController: failed to create worklog',
				[
					'exception' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);
			return new JSONResponse(
				['message' => 'Failed to create worklog'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end createWorklog()
}//end class
