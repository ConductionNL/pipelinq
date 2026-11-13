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

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\OrganisationEnrichmentServiceInterface;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Organisation enrichment endpoints.
 *
 * @spec openspec/specs/client-management/spec.md
 */
class OrganisationEnrichmentController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                      $request     The request.
	 * @param OrganisationEnrichmentServiceInterface $enrichment  The lookup and the write.
	 * @param CrmPrivilegeCheck $privilege The session user and the CRM privilege check.
	 * @param IL10N                         $l10n        Translations.
	 */
	public function __construct(
		IRequest $request,
		private OrganisationEnrichmentServiceInterface $enrichment,
		private CrmPrivilegeCheck $privilege,
		private IL10N $l10n,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Propose KvK values for the selected organisations.
	 *
	 * @return JSONResponse `{ proposals, notFound }`.
	 *
	 * @spec openspec/specs/client-management/spec.md
	 */
	#[NoAdminRequired]
	public function propose(): JSONResponse {
		$refusal = $this->privilege->translatedRefusal($this->l10n);
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
	 * @spec openspec/specs/client-management/spec.md
	 */
	#[NoAdminRequired]
	public function apply(): JSONResponse {
		$refusal = $this->privilege->translatedRefusal($this->l10n);
		if ($refusal !== null) {
			return $refusal;
		}

		$changes = $this->request->getParam('changes');
		if (is_array($changes) === false) {
			return new JSONResponse(['error' => $this->l10n->t('No changes to write')], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($this->enrichment->apply(changes: $changes));
	}//end apply()
}//end class
