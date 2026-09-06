<?php

/**
 * Pipelinq MailTransportController.
 *
 * HTTP surface for the marketing-mail-transports deliverability panel's one
 * real computation: the SPF/DKIM/DMARC DNS check. Transport CRUD itself
 * (list, create, toggle active/default) goes through OpenRegister's generic
 * object API via `useObjectStore` on the frontend, exactly like
 * `channelProvider` in `MessagingSettings.vue` — this controller exists only
 * because a DNS lookup is not CRUD.
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
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-deliverability-panel-shows-spf-dkim-and-dmarc-status-per-sender-domain
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Marketing\DeliverabilityCheckService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Deliverability-check controller.
 *
 * Endpoints:
 *   POST /api/mail-transports/{id}/check-deliverability   AuthorizedAdminSetting
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-deliverability-panel-shows-spf-dkim-and-dmarc-status-per-sender-domain
 */
class MailTransportController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param DeliverabilityCheckService $deliverabilityCheckService SPF/DKIM/DMARC lookup.
	 *
	 * @SuppressWarnings(PHPMD.LongVariable) $deliverabilityCheckService mirrors the
	 *  class it holds; renaming would obscure what it is.
	 */
	public function __construct(
		IRequest $request,
		private DeliverabilityCheckService $deliverabilityCheckService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Check (or refresh) a transport's SPF/DKIM/DMARC verdict.
	 *
	 * @param string $id MailTransport UUID or slug.
	 * @param bool $refresh Re-query DNS even when the cache is fresh.
	 *
	 * @return JSONResponse The verdict, or 404 when the transport does not exist.
	 *
	 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-deliverability-panel-shows-spf-dkim-and-dmarc-status-per-sender-domain
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $refresh is the documented
	 *  cache-bypass request from the "Check now" button, not a behaviour switch.
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function checkDeliverability(string $id = '', bool $refresh = false): JSONResponse {
		$verdict = $this->deliverabilityCheckService->checkTransportById(id: $id, forceRefresh: $refresh);
		if ($verdict === null) {
			return new JSONResponse(['error' => 'mail-transport-not-found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($verdict);
	}//end checkDeliverability()
}//end class
