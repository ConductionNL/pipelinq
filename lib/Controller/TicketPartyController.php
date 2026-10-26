<?php

/**
 * Pipelinq TicketPartyController.
 *
 * A host app's link to one party's tickets. A host app (dossiq's contact and
 * organisation pages) knows a party by the master entity its record shares
 * with the pipelinq client, not by pipelinq's client uuid, so the link names
 * the master entity and this route opens the ticket list filtered on that
 * party's clients.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://pipelinq.nl
 * @spec openspec/changes/tickets-list-by-party/specs/ticket-leaf/spec.md#requirement-a-host-app-can-link-to-one-partys-ticket-list
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Integration\TicketLeafProvider;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Opens the ticket list on the clients of one master entity.
 *
 * @spec openspec/changes/tickets-list-by-party/specs/ticket-leaf/spec.md#requirement-a-host-app-can-link-to-one-partys-ticket-list
 */
class TicketPartyController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param TicketLeafProvider $provider Resolves a master entity to its clients.
	 * @param IURLGenerator $urlGenerator Builds the ticket list URL.
	 */
	public function __construct(
		IRequest $request,
		private readonly TicketLeafProvider $provider,
		private readonly IURLGenerator $urlGenerator,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Redirect to the ticket list filtered on the party's clients; a party with
	 * no client the caller may read opens the whole list. The clients are read
	 * through OpenRegister as the caller, so the filter names nothing the
	 * caller may not see. A plain navigation (a link from another app), so it
	 * carries no CSRF token.
	 *
	 * @param string $ref The party's master entity reference.
	 *
	 * @return RedirectResponse The ticket list.
	 *
	 * @spec openspec/changes/tickets-list-by-party/specs/ticket-leaf/spec.md#requirement-a-host-app-can-link-to-one-partys-ticket-list
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function tickets(string $ref): RedirectResponse {
		$list = $this->urlGenerator->linkToRoute('pipelinq.dashboard.page', ['path' => 'tickets']);
		$clients = $this->provider->clientIdsOf(ref: $ref);
		if ($clients === []) {
			return new RedirectResponse($list);
		}

		$client = $clients;
		if (count($clients) === 1) {
			$client = $clients[0];
		}

		return new RedirectResponse($list.'?'.http_build_query(['client' => $client]));
	}//end tickets()
}//end class
