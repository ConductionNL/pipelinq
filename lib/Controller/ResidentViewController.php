<?php
/**
 * Pipelinq ResidentViewController.
 *
 * What the resident will read of a ticket, for the preview on TicketDetail
 * (portal-resident-view-preview). The panels are built by the portal's own
 * code: the bespoke resident portal through PortalRequestService, the
 * organisation portal (portaliq) through pipelinq's PortalContributionProvider.
 * So the preview changes the moment the portal changes.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/resident-view-preview/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Portal\PortalContributionProvider;
use OCA\Pipelinq\Service\Portal\PortalRequestService;
use OCA\Pipelinq\Service\Portal\PortalTenantService;
use OCA\Pipelinq\Service\TicketService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * Answers the resident's view of one ticket.
 *
 * @spec openspec/specs/resident-view-preview/spec.md
 */
class ResidentViewController extends Controller {

	/**
	 * Ticket fields the bespoke portal reads (see PortalRequestService::presentDetail()).
	 * `assignee` is added when the portal shows the handler's name.
	 */
	private const BESPOKE_FIELDS = [
		'caseReference',
		'reference',
		'title',
		'category',
		'status',
		'occurredAt',
		'description',
		'customerMessage',
		'portalReplies',
	];

	/**
	 * Keys that identify a ticket rather than say something about it.
	 */
	private const IDENTIFIERS = ['@self', 'id', 'uuid', 'ticketType'];

	/**
	 * Constructor.
	 *
	 * @param IRequest                   $request  The request.
	 * @param TicketService              $tickets  Register and schema of the ticket supertype, and OpenRegister.
	 * @param PortalRequestService       $requests The resident portal's request presenter.
	 * @param PortalContributionProvider $portal   pipelinq's contribution to portaliq.
	 * @param PortalTenantService        $tenants  The portal tenant settings.
	 * @param IAppManager                $apps     Whether portaliq is installed.
	 */
	public function __construct(
		IRequest $request,
		private readonly TicketService $tickets,
		private readonly PortalRequestService $requests,
		private readonly PortalContributionProvider $portal,
		private readonly PortalTenantService $tenants,
		private readonly IAppManager $apps,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The resident's view of a ticket.
	 *
	 * The ticket is read through OpenRegister as the calling user: a ticket
	 * they may not read answers 404 with no data, so the preview never shows
	 * more than the handler can already see.
	 *
	 * @param string $id The ticket id.
	 *
	 * @return JSONResponse `{bespoke, portaliq, internalFields}`, or 404.
	 *
	 * @spec openspec/specs/resident-view-preview/spec.md#requirement-the-preview-respects-the-handlers-own-rights-req-rvp-003
	 */
	#[NoAdminRequired]
	public function show(string $id = ''): JSONResponse {
		if ($this->tickets->isConfigured() === false) {
			return new JSONResponse(['error' => 'Tickets are not configured'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$ticket = null;
		if ($id !== '') {
			// OpenRegister's facade reads under the caller's register RBAC
			// (no `_rbac: false`), which is the per-object guard.
			$objectService = $this->objectService();
			try {
				$ticket = $objectService->find(
					id: $id,
					register: $this->tickets->getRegisterId(),
					schema: $this->tickets->getSchemaId()
				);
			} catch (Throwable) {
				$ticket = null;
			}
		}

		if ($ticket === null) {
			return new JSONResponse(['error' => 'Ticket not found'], Http::STATUS_NOT_FOUND);
		}

		$data   = $ticket->getObject();
		$expose = ($this->tenants->getConfig(PortalTenantService::DEFAULT_TENANT)['exposeAssigneeName'] ?? false) === true;

		$bespoke = $this->requests->previewDetail(ticket: $data, exposeAssigneeName: $expose);
		$shown   = [];
		if ($bespoke !== null) {
			$shown = self::BESPOKE_FIELDS;
			if ($expose === true) {
				$shown[] = 'assignee';
			}
		}

		$portaliq = $this->portaliqPanel(ticket: $data);
		if ($portaliq !== null) {
			$shown = array_merge($shown, array_keys($portaliq['fields']));
		}

		return new JSONResponse(
			[
				'bespoke'        => $bespoke,
				'portaliq'       => $portaliq,
				'internalFields' => $this->internalFields(ticket: $data, shown: $shown),
			]
		);
	}//end show()

	/**
	 * OpenRegister's object facade, which reads under the caller's RBAC.
	 *
	 * @return ObjectServiceInterface The facade.
	 */
	private function objectService(): ObjectServiceInterface {
		return $this->tickets->getObjectService();
	}//end objectService()

	/**
	 * What an organisation's contact reads of the ticket in portaliq.
	 *
	 * Built from the `client` contribution: the collection whose filter
	 * matches the ticket's type, keeping only its whitelisted fields.
	 *
	 * @param array<string, mixed> $ticket The ticket.
	 *
	 * @return array{label: string, fields: array<string, mixed>}|null The panel, or null.
	 *
	 * @spec openspec/specs/resident-view-preview/spec.md#requirement-the-preview-shows-the-organisation-portal-too-req-rvp-002
	 */
	private function portaliqPanel(array $ticket): ?array {
		if (trim((string)($ticket['client'] ?? '')) === '' || $this->apps->isInstalled('portaliq') === false) {
			return null;
		}

		$contribution = $this->portal->getContribution(subject: ['audience' => 'client']);
		foreach (($contribution['collections'] ?? []) as $collection) {
			if (($collection['schema'] ?? '') !== 'ticket'
				|| ($collection['filter']['ticketType'] ?? null) !== ($ticket['ticketType'] ?? null)
			) {
				continue;
			}

			$fields = [];
			foreach (($collection['fields'] ?? []) as $field) {
				$fields[$field] = ($ticket[$field] ?? null);
			}

			return ['label' => (string)($collection['label'] ?? ''), 'fields' => $fields];
		}

		return null;
	}//end portaliqPanel()

	/**
	 * The ticket fields with a value that no portal shows.
	 *
	 * @param array<string, mixed> $ticket The ticket.
	 * @param array<int, string>   $shown  The fields a panel shows.
	 *
	 * @return array<int, string> The internal field names, sorted.
	 */
	private function internalFields(array $ticket, array $shown): array {
		$internal = [];
		foreach ($ticket as $key => $value) {
			if (in_array($key, self::IDENTIFIERS, true) === true || in_array($key, $shown, true) === true) {
				continue;
			}

			if ($value === null || $value === '' || $value === []) {
				continue;
			}

			$internal[] = (string)$key;
		}

		sort($internal);

		return $internal;
	}//end internalFields()
}//end class
