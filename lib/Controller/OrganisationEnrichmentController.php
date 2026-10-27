<?php

/**
 * Pipelinq OrganisationEnrichmentController.
 *
 * Proposes KvK values for the empty fields of selected organisations and
 * writes the accepted ones.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\OrganisationEnrichmentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Organisation enrichment endpoints.
 *
 * @spec openspec/changes/organisation-enrichment/specs/client-management/spec.md
 */
class OrganisationEnrichmentController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                      $request     The request.
	 * @param OrganisationEnrichmentService $enrichment  The lookup and the write.
	 * @param IUserSession                  $userSession The user session.
	 * @param ObjectOwnerAccessPolicy       $policy      Who may change the CRM.
	 * @param IL10N                         $l10n        Translations.
	 */
	public function __construct(
		IRequest $request,
		private OrganisationEnrichmentService $enrichment,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $policy,
		private IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Propose KvK values for the selected organisations.
	 *
	 * @return JSONResponse `{ proposals, notFound }`.
	 *
	 * @spec openspec/changes/organisation-enrichment/specs/client-management/spec.md
	 */
	#[NoAdminRequired]
	public function propose(): JSONResponse {
		$refusal = $this->refusal();
		if ($refusal !== null) {
			return $refusal;
		}

		$ids = $this->request->getParam('ids');
		if (is_array($ids) === false || $ids === []) {
			return new JSONResponse(['error' => $this->l10n->t('Choose at least one organisation')], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($this->enrichment->propose(clientIds: array_map('strval', $ids)));
	}//end propose()

	/**
	 * Write the accepted proposals.
	 *
	 * @return JSONResponse `{ updated }`.
	 *
	 * @spec openspec/changes/organisation-enrichment/specs/client-management/spec.md
	 */
	#[NoAdminRequired]
	public function apply(): JSONResponse {
		$refusal = $this->refusal();
		if ($refusal !== null) {
			return $refusal;
		}

		$changes = $this->request->getParam('changes');
		if (is_array($changes) === false) {
			return new JSONResponse(['error' => $this->l10n->t('No changes to write')], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($this->enrichment->apply(changes: $changes));
	}//end apply()

	/**
	 * Refuse a caller who is not signed in or may not change the CRM.
	 *
	 * @return JSONResponse|null The refusal, or null to go on.
	 */
	private function refusal(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Forbidden')], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end refusal()
}//end class
