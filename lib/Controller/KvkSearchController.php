<?php

/**
 * Pipelinq KvkSearchController.
 *
 * A single Handelsregister search for a dialog: the new request dialog's KVK
 * pill searches by name or KVK number and picks one company. It wraps
 * {@see KvkApiClient} behind the same login as the enrichment endpoints (the KVK source's own rate limit applies downstream), so
 * a handler who may read the CRM may search the KVK.
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
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Service\KvkApiClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * The KVK search behind the new request dialog's "Opzoeken in de KVK" pill.
 *
 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
 */
class KvkSearchController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest          $request   The request.
	 * @param KvkApiClient      $kvk       The Handelsregister lookup.
	 * @param CrmPrivilegeCheck $privilege The session user and the CRM privilege check.
	 * @param IL10N             $l10n      Translations.
	 */
	public function __construct(
		IRequest $request,
		private KvkApiClient $kvk,
		private CrmPrivilegeCheck $privilege,
		private IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Search the Handelsregister by name or KVK number.
	 *
	 * A query of exactly eight digits is read as a KVK number and looked up
	 * directly; anything else is a name search. The caller must be able to
	 * read the CRM, the same check the enrichment endpoints make.
	 *
	 * @return JSONResponse `{ results }`, or a refusal.
	 *
	 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
	 */
	#[NoAdminRequired]
	public function search(): JSONResponse {
		$refusal = $this->privilege->translatedRefusal($this->l10n);
		if ($refusal !== null) {
			return $refusal;
		}

		$query = trim((string)$this->request->getParam('q', ''));
		if ($query === '') {
			return new JSONResponse(['error' => $this->l10n->t('Type a company name or KVK number')], Http::STATUS_BAD_REQUEST);
		}

		$results = preg_match('/^\d{8}$/', $query) === 1
			? $this->kvk->companyByNumber(kvkNumber: $query)
			: $this->kvk->searchByName(name: $query);

		return new JSONResponse(['results' => array_values($results)]);
	}//end search()
}//end class
