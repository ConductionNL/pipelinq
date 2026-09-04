<?php

/**
 * Pipelinq SearchConsoleController.
 *
 * Read side of the Search Console import: the top queries over a window
 * plus the connection status the settings page and the Search queries
 * page show. The service account key itself is never part of any
 * response; only its email address is, because that is what the admin
 * has to add on the property.
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
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-queries-page-lists-top-queries
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\SearchConsole\SearchConsoleImportService;
use OCA\Pipelinq\Service\SearchConsole\SearchQueryReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * SearchConsoleController: `GET /api/marketing/search-queries`.
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-queries-page-lists-top-queries
 */
class SearchConsoleController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Request.
	 * @param SearchQueryReportService $report The aggregation.
	 * @param SearchConsoleImportService $importer Connection status.
	 * @param IUserSession $userSession Session.
	 * @param ObjectOwnerAccessPolicy $policy CRM privilege check.
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-queries-page-lists-top-queries
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SearchQueryReportService $report,
		private readonly SearchConsoleImportService $importer,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Top queries by clicks over a window, with the connection status.
	 *
	 * Authentication is not authorization: search demand is marketing
	 * data, a CRM capability, so the same privilege check as the blast
	 * endpoints applies. Admins bypass via the policy.
	 *
	 * @param string|null $from Window start `YYYY-MM-DD`.
	 * @param string|null $to Window end `YYYY-MM-DD`.
	 * @param int $limit Rows to return.
	 * @param string|null $property Restrict to one property.
	 *
	 * @return JSONResponse `{from, to, rows[], totalQueries, configured, properties[], serviceAccountEmail, lastImportAt}`.
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-queries-page-lists-top-queries
	 */
	#[NoAdminRequired]
	public function index(?string $from = null, ?string $to = null, int $limit = 50, ?string $property = null): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$result = $this->report->topQueries(from: $from, to: $to, limit: $limit, property: $property);
		$result['configured'] = ($this->importer->hasKey() === true && $this->importer->properties() !== []);
		$result['properties'] = $this->importer->properties();
		$result['serviceAccountEmail'] = $this->importer->serviceAccountEmail();
		$result['lastImportAt'] = $this->importer->lastImportAt();

		return new JSONResponse($result);
	}//end index()
}//end class
