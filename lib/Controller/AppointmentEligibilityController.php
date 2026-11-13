<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\EligibilityService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Which resources match an appointment service: the skill-eligible resources
 * that are also active, bookable and of a type the service asks for
 * (REQ-SRG-002). Reads only; the write path that creates services and
 * resources lives elsewhere.
 *
 * Every method requires an authenticated, CRM-privileged user
 * (#[NoAdminRequired] plus the shared access policy), and the per-service read
 * goes through OpenRegister's object service, so a service the caller may not
 * read resolves to an empty match rather than leaking its resources.
 *
 * @spec openspec/changes/services-and-resources-at-a-glance/specs/appointment-booking/spec.md#requirement-each-service-shows-the-resources-that-match-it-req-srg-002
 */
class AppointmentEligibilityController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The HTTP request.
	 * @param EligibilityService $eligibility The eligibility service (REQ-APT-004 + REQ-SRG-002).
	 * @param IUserSession $userSession The current user session.
	 * @param ObjectOwnerAccessPolicy $accessPolicy The CRM access policy.
	 * @param IL10N $l10n Localization service.
	 */
	public function __construct(
		IRequest $request,
		private EligibilityService $eligibility,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $accessPolicy,
		private IL10N $l10n,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The resources that match one service.
	 *
	 * @param string $id The service UUID/slug.
	 *
	 * @return JSONResponse `{ "resources": [...] }`, or an auth error envelope.
	 *
	 * @spec openspec/changes/services-and-resources-at-a-glance/specs/appointment-booking/spec.md#requirement-each-service-shows-the-resources-that-match-it-req-srg-002
	 */
	#[NoAdminRequired]
	public function eligibleResources(string $id): JSONResponse {
		$guard = $this->requirePrivileged();
		if ($guard instanceof JSONResponse) {
			return $guard;
		}

		return new JSONResponse(data: ['resources' => $this->eligibility->getMatchingResources(serviceId: $id)]);
	}//end eligibleResources()

	/**
	 * The matching-resource count per service, for the services list. With a
	 * `services` query parameter (comma-separated ids) only those are counted;
	 * without it every service is.
	 *
	 * @return JSONResponse `{ "counts": { "<serviceId>": <int> } }`, or an auth error.
	 *
	 * @spec openspec/changes/services-and-resources-at-a-glance/specs/appointment-booking/spec.md#requirement-each-service-shows-the-resources-that-match-it-req-srg-002
	 */
	#[NoAdminRequired]
	public function eligibleResourceCounts(): JSONResponse {
		$guard = $this->requirePrivileged();
		if ($guard instanceof JSONResponse) {
			return $guard;
		}

		$raw = (string)$this->request->getParam('services', '');
		$ids = [];
		if ($raw !== '') {
			foreach (explode(',', $raw) as $entry) {
				$trimmed = trim($entry);
				if ($trimmed !== '') {
					$ids[] = $trimmed;
				}
			}
		}

		return new JSONResponse(data: ['counts' => $this->eligibility->matchingResourceCounts(serviceIds: $ids)]);
	}//end eligibleResourceCounts()

	/**
	 * Resolve the acting user, 401 when unauthenticated and 403 without the CRM
	 * privilege, before anything is read.
	 *
	 * @return string|JSONResponse The uid, or an error response.
	 */
	private function requirePrivileged(): string|JSONResponse {
		$user = $this->userSession->getUser();
		$uid = '';
		if ($user !== null) {
			$uid = $user->getUID();
		}

		if ($uid === '') {
			return new JSONResponse(data: ['error' => $this->l10n->t('Authentication required')], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->accessPolicy->isPrivileged(uid: $uid) === false) {
			return new JSONResponse(data: ['error' => $this->l10n->t('Forbidden')], statusCode: Http::STATUS_FORBIDDEN);
		}

		return $uid;
	}//end requirePrivileged()
}//end class
