<?php

/**
 * Pipelinq BerichtenboxZaakStatusListener.
 *
 * Listens for OpenRegister ObjectUpdatedEvent on zaakafhandelapp's zaak
 * schema. When a zaak transitions to a terminal or notification-worthy
 * status (afgehandeld, afgewezen, meer-info-nodig, in-behandeling), it
 * calls BerichtenboxService::queueOutboundMessage() with the burger's
 * BSN resolved from the linked Contactmoment / Burger record. The
 * listener is best-effort: any error is logged and swallowed so zaak
 * processing is never blocked (REQ-OUTBOUND-001 / Acceptance 1).
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-outbound-001
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/tasks.md#5.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\BerichtenboxService;
use OCA\Pipelinq\Service\TicketService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Zaak status-change → Berichtenbox dispatch listener.
 *
 * @implements IEventListener<Event>
 */
class BerichtenboxZaakStatusListener implements IEventListener {
	/**
	 * Statuses that trigger a Berichtenbox dispatch.
	 *
	 * @var array<int, string>
	 */
	public const TRIGGER_STATUSES = [
		'received',
		'in-behandeling',
		'meer-info-nodig',
		'afgehandeld',
		'afgewezen',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config.
	 * @param BerichtenboxService $berichtenbox Bridge service.
	 * @param TicketService $ticketService Resolver for the unified ticket schema.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly BerichtenboxService $berichtenbox,
		private readonly TicketService $ticketService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an OR ObjectUpdatedEvent for a zaak.
	 *
	 * Duck-typed on the event class name so we don't hard-require
	 * zaakafhandelapp at compile time (the listener is bound from the
	 * Application bootstrap and silently no-ops when the event class
	 * isn't loaded).
	 *
	 * @param Event $event Event.
	 *
	 * @return void
	 */
	public function handle(Event $event): void {
		if ($this->isCaseUpdate(event: $event) === false) {
			return;
		}

		try {
			[$oldData, $newData] = $this->extractObjectPair(event: $event);
			if ($newData === null) {
				return;
			}

			$newStatus = (string)($newData['status'] ?? '');
			$oldStatus = (string)($oldData['status'] ?? '');
			if ($newStatus === '' || $newStatus === $oldStatus) {
				return;
			}

			if (in_array($newStatus, self::TRIGGER_STATUSES, true) === false) {
				return;
			}

			$bsn = (string)($newData['bsn'] ?? '');
			if ($bsn === '') {
				// Try the linked Contactmoment.
				$bsn = $this->resolveBsnViaContactmoment(
					contactmomentId: (string)($newData['contactmomentId'] ?? '')
				);
			}

			if ($bsn === '') {
				$this->logger->info(
					'BerichtenboxZaakStatusListener: no BSN resolved, skipping.',
					['caseId' => (string)($newData['id'] ?? '')]
				);
				return;
			}

			$this->berichtenbox->queueOutboundMessage(
				caseId: (string)($newData['id'] ?? $newData['uuid'] ?? ''),
				contactmomentId: (string)($newData['contactmomentId'] ?? null),
				status: $newStatus,
				bsn: $bsn,
				templateOverride: null,
				extraVariables: [
					'caseType' => (string)($newData['caseType'] ?? ''),
					'language' => (string)($newData['language'] ?? 'nl'),
				],
				attachments: ($newData['attachments'] ?? [])
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'BerichtenboxZaakStatusListener: dispatch suppressed.',
				['exception' => $e->getMessage()]
			);
		}//end try
	}//end handle()

	/**
	 * Heuristic: is this an OR ObjectUpdatedEvent on a zaak schema?
	 *
	 * @param Event $event Event.
	 *
	 * @return bool
	 */
	private function isCaseUpdate(Event $event): bool {
		$class = $event::class;
		if (str_contains($class, 'ObjectUpdatedEvent') === false) {
			return false;
		}

		// Best-effort schema detection.
		if (method_exists($event, 'getNewObject') === false) {
			return false;
		}

		$new = $event->getNewObject();
		if ($new === null || method_exists($new, 'getSchema') === false) {
			return false;
		}

		$schemaId = (string)$new->getSchema();
		$caseSchema = $this->appConfig->getValueString(
			Application::APP_ID,
			'zaak_schema',
			''
		);
		return ($schemaId === $caseSchema && $caseSchema !== '');
	}//end isZaakUpdate()

	/**
	 * Extract (old, new) object data from an OR event.
	 *
	 * @param Event $event Event.
	 *
	 * @return array{0: ?array, 1: ?array}
	 */
	private function extractObjectPair(Event $event): array {
		$oldData = null;
		$newData = null;
		if (method_exists($event, 'getOldObject') === true) {
			$old = $event->getOldObject();
			if ($old !== null && method_exists($old, 'getObject') === true) {
				$candidate = $old->getObject();
				if (is_array($candidate) === true) {
					$oldData = $candidate;
				}
			}
		}

		if (method_exists($event, 'getNewObject') === true) {
			$new = $event->getNewObject();
			if ($new !== null && method_exists($new, 'getObject') === true) {
				$candidate = $new->getObject();
				if (is_array($candidate) === true) {
					$newData = $candidate;
				}
			}
		}

		return [$oldData, $newData];
	}//end extractObjectPair()

	/**
	 * Resolve a BSN by reading the linked Contactmoment + Burger.
	 *
	 * The contactmoment is a `ticket` with `ticketType: contactmoment`
	 * (unify-ticket-supertype), so the lookup resolves the unified ticket
	 * schema through TicketService instead of the retired
	 * `contactmoment_schema`.
	 *
	 * @param string $contactmomentId Contactmoment (ticket) uuid.
	 *
	 * @return string
	 */
	private function resolveBsnViaContactmoment(string $contactmomentId): string {
		if ($contactmomentId === '') {
			return '';
		}

		if ($this->ticketService->isConfigured() === false) {
			return '';
		}

		try {
			$service = $this->ticketService->getObjectService();
			$register = $this->ticketService->getRegisterId();
			$schema = $this->ticketService->getSchemaId();

			$row = $service->find(id: $contactmomentId, register: $register, schema: $schema);
			if ($row === null) {
				return '';
			}

			$data = $row;
			if (is_array($row) === false) {
				$data = ($row->getObject() ?? []);
			}

			return (string)($data['bsn'] ?? '');
		} catch (\Throwable $e) {
			$this->logger->info(
				'BerichtenboxZaakStatusListener: BSN-via-contactmoment lookup failed.',
				['exception' => $e->getMessage()]
			);
			return '';
		}//end try
	}//end resolveBsnViaContactmoment()
}//end class
