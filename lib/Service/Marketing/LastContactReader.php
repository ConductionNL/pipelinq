<?php

/**
 * Pipelinq LastContactReader.
 *
 * When a customer was last in contact: the newest `occurredAt` among the
 * customer's contact moments. Read for a segment rule, never stored.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/segment-last-contact-signal/specs/marketing-integrated-campaigns/spec.md#requirement-a-customers-last-contact-is-a-derived-segment-field
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCA\Pipelinq\Service\TicketService;

/**
 * LastContactReader: the latest contact moment of one client.
 *
 * @spec openspec/changes/segment-last-contact-signal/specs/marketing-integrated-campaigns/spec.md#requirement-a-customers-last-contact-is-a-derived-segment-field
 */
class LastContactReader {

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store Register-scoped object plumbing.
	 *
	 * @spec openspec/changes/segment-last-contact-signal/specs/marketing-integrated-campaigns/spec.md#requirement-a-customers-last-contact-is-a-derived-segment-field
	 */
	public function __construct(
		private ListObjectStore $store,
	) {
	}//end __construct()

	/**
	 * When the latest contact moment of this client took place.
	 *
	 * A contact moment is a ticket of type `interaction`; requests and
	 * complaints are what the contact was about, not the contact itself.
	 *
	 * @param string $clientId The pipelinq client id.
	 *
	 * @return string|null The `occurredAt` of the newest moment, or null when none is dated.
	 *
	 * @spec openspec/changes/segment-last-contact-signal/specs/marketing-integrated-campaigns/spec.md#requirement-a-customers-last-contact-is-a-derived-segment-field
	 */
	public function forClient(string $clientId): ?string {
		$rows = $this->store->findAll(
			schemaSlug: $this->store->schemaSlug('ticket_schema', 'ticket'),
			filters: ['client' => $clientId, 'ticketType' => TicketService::TYPE_CONTACTMOMENT]
		);

		$latest = null;
		$latestStamp = null;
		foreach ($rows as $row) {
			if ((string)($row['client'] ?? '') !== $clientId
				|| (string)($row['ticketType'] ?? '') !== TicketService::TYPE_CONTACTMOMENT
			) {
				continue;
			}

			$moment = trim((string)($row['occurredAt'] ?? ''));
			$stamp = strtotime($moment);
			if ($moment === '' || $stamp === false) {
				continue;
			}

			if ($latestStamp === null || $stamp > $latestStamp) {
				$latest = $moment;
				$latestStamp = $stamp;
			}
		}

		return $latest;
	}//end forClient()

}//end class
