<?php

/**
 * Pipelinq CrmCallerGate.
 *
 * Admits a caller of the marketing controllers: logged in, and a CRM user.
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
 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUserSession;

/**
 * The caller check of the blast, segment and template controllers.
 *
 * Authentication is not authorization: blasts, segments and templates reach
 * customer contact data and send on the organisation's behalf — a CRM
 * capability. Admins bypass via the policy.
 *
 * @spec openspec/specs/marketing-api/spec.md
 */
final class CrmCallerGate {
	/**
	 * Constructor.
	 *
	 * @param IUserSession            $userSession The user session.
	 * @param ObjectOwnerAccessPolicy $policy      The CRM privilege policy.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
	}//end __construct()

	/**
	 * The authenticated user id, or null when unauthenticated.
	 *
	 * @return string|null UID or null.
	 *
	 * @spec openspec/specs/marketing-api/spec.md
	 */
	public function userId(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end userId()

	/**
	 * The id of a logged-in CRM user, or the refusal: 401 without a user,
	 * 403 for a user without CRM privilege.
	 *
	 * @return string|JSONResponse The UID, or the refusal response.
	 *
	 * @spec openspec/specs/marketing-api/spec.md
	 */
	public function privilegedUserId(): string|JSONResponse {
		$uid = $this->userId();
		if ($uid === null) {
			return $this->unauthorized();
		}

		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return $uid;
	}//end privilegedUserId()

	/**
	 * Generic 401 response.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/marketing-api/spec.md
	 */
	public function unauthorized(): JSONResponse {
		return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
	}//end unauthorized()
}//end class
