<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\BookingFiguresService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * How busy an appointment service or resource is: the figures of the service
 * page (REQ-SRG-003) and the resource page (REQ-SRG-004), one call per page.
 * Reads only.
 *
 * Every method requires an authenticated, CRM-privileged user
 * (#[NoAdminRequired] plus the shared access policy); the reads go through
 * OpenRegister's object service.
 *
 * @spec openspec/changes/services-and-resources-at-a-glance/specs/appointment-booking/spec.md#requirement-a-service-page-shows-how-busy-the-service-is-req-srg-003
 */
class AppointmentFiguresController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                $request      The HTTP request.
	 * @param BookingFiguresService   $figures      The week and month figures.
	 * @param IUserSession            $userSession  The current user session.
	 * @param ObjectOwnerAccessPolicy $accessPolicy The CRM access policy.
	 * @param IL10N                   $l10n         Localization service.
	 */
	public function __construct(
		IRequest $request,
		private BookingFiguresService $figures,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $accessPolicy,
		private IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * A service's bookings this week and no-shows this month.
	 *
	 * @param string $id The service UUID/slug.
	 *
	 * @return JSONResponse `{ "bookingsThisWeek": int, "noShowsThisMonth": int }`, or an auth error envelope.
	 *
	 * @spec openspec/changes/services-and-resources-at-a-glance/specs/appointment-booking/spec.md#requirement-a-service-page-shows-how-busy-the-service-is-req-srg-003
	 */
	#[NoAdminRequired]
	public function serviceFigures(string $id): JSONResponse {
		$guard = $this->requirePrivileged();
		if ($guard !== null) {
			return $guard;
		}

		return new JSONResponse(data: $this->figures->serviceFigures(serviceId: $id));
	}//end serviceFigures()

	/**
	 * A resource's bookings this week and the share of its bookable time they fill.
	 *
	 * @param string $id The resource UUID/slug.
	 *
	 * @return JSONResponse `{ "bookingsThisWeek", "bookedMinutes", "bookableMinutes", "bookedShare" }`, or an auth error envelope.
	 *
	 * @spec openspec/changes/services-and-resources-at-a-glance/specs/appointment-booking/spec.md#requirement-a-resource-page-shows-how-much-of-its-bookable-time-is-booked-req-srg-004
	 */
	#[NoAdminRequired]
	public function resourceFigures(string $id): JSONResponse {
		$guard = $this->requirePrivileged();
		if ($guard !== null) {
			return $guard;
		}

		return new JSONResponse(data: $this->figures->resourceFigures(resourceId: $id));
	}//end resourceFigures()

	/**
	 * Refuse a caller who is not signed in (401) or not CRM-privileged (403).
	 *
	 * @return JSONResponse|null The refusal, or null for a privileged caller.
	 */
	private function requirePrivileged(): ?JSONResponse {
		$uid = ($this->userSession->getUser()?->getUID() ?? '');
		if ($uid === '') {
			return new JSONResponse(data: ['error' => $this->l10n->t('Authentication required')], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->accessPolicy->isPrivileged(uid: $uid) === false) {
			return new JSONResponse(data: ['error' => $this->l10n->t('Forbidden')], statusCode: Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end requirePrivileged()
}//end class
