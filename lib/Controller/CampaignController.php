<?php

/**
 * Pipelinq CampaignController.
 *
 * The two campaign endpoints the browser cannot get from the object API:
 * the aggregate report, and the action that asks Portaliq for a landing
 * page. Plain campaign reads and writes go through OpenRegister's own
 * object API, so there is no pass-through CRUD here.
 *
 * Authentication is not authorization: a campaign report carries revenue
 * and customer names, so it takes the same privilege check as the blast
 * endpoints.
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
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\CampaignReportService;
use OCA\Pipelinq\Service\LandingPageProvisioningService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * CampaignController: the campaign report and the landing page action.
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
 */
class CampaignController extends Controller {

	/**
	 * The HTTP status each of Portaliq's failure codes answers with.
	 *
	 * A rejected request is the caller's to fix, so it is a 422; an
	 * unknown portal is a 404; a write that failed inside Portaliq and an
	 * absent Portaliq are both platform faults, so 502 and 501. The code
	 * itself always travels in the body, because that is what tells the
	 * marketer which of the two forms to go and fix.
	 *
	 * @var array<string, int>
	 */
	private const ERROR_STATUS = [
		'not_found' => Http::STATUS_NOT_FOUND,
		'unknown_portal' => Http::STATUS_NOT_FOUND,
		'duplicate_route' => Http::STATUS_CONFLICT,
		'invalid_article' => Http::STATUS_UNPROCESSABLE_ENTITY,
		'invalid_form' => Http::STATUS_UNPROCESSABLE_ENTITY,
		'write_failed' => Http::STATUS_BAD_GATEWAY,
		'portaliq_missing' => Http::STATUS_NOT_IMPLEMENTED,
	];

	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Request.
	 * @param CampaignReportService $report The aggregate report.
	 * @param LandingPageProvisioningService $landingPages The Portaliq hand-off.
	 * @param IUserSession $userSession Session.
	 * @param ObjectOwnerAccessPolicy $policy CRM privilege check.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CampaignReportService $report,
		private readonly LandingPageProvisioningService $landingPages,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The whole report for one campaign, in one response.
	 *
	 * @param string $id The campaign id or slug.
	 * @param string|null $from Window start `YYYY-MM-DD`.
	 * @param string|null $to Window end `YYYY-MM-DD`.
	 *
	 * @return JSONResponse `{campaign, window, channels[], engagement, leads[], totals, models, cost}`.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
	 */
	#[NoAdminRequired]
	public function report(string $id, ?string $from = null, ?string $to = null): JSONResponse {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}

		$record = $this->report->forCampaign(campaignId: $id, from: $from, to: $to);
		if ($record === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($record);
	}//end report()

	/**
	 * Ask Portaliq for a landing page with a lead-capture form.
	 *
	 * Portaliq's own failure code is returned unchanged. Collapsing the
	 * five into one message would cost the marketer the only information
	 * that says where to go and fix it.
	 *
	 * @param string $id The campaign id or slug.
	 * @param string $portal The portal slug; empty uses the configured one.
	 * @param string $route The in-portal route; empty derives one from the campaign value.
	 *
	 * @return JSONResponse `{error, portal, route, pageId, publicUrl, formId}`.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-creates-its-landing-page-in-portaliq
	 */
	#[NoAdminRequired]
	public function createLandingPage(string $id, string $portal = '', string $route = ''): JSONResponse {
		$denied = $this->guard();
		if ($denied !== null) {
			return $denied;
		}

		$result = $this->landingPages->createFor(campaignId: $id, portal: $portal, route: $route);
		if ($result['error'] === '') {
			return new JSONResponse($result);
		}

		return new JSONResponse($result, (self::ERROR_STATUS[$result['error']] ?? Http::STATUS_BAD_GATEWAY));
	}//end createLandingPage()

	/**
	 * Refuse a caller who is not signed in or not privileged.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may proceed.
	 */
	private function guard(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end guard()
}//end class
