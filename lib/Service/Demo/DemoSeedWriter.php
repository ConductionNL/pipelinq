<?php

/**
 * Pipelinq DemoSeedWriter.
 *
 * Writes demo objects: contact-first identity, then one save per object.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Demo
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Demo;

use OCA\Pipelinq\Service\ContactVcardService;
use OCA\Pipelinq\Service\TicketService;

/**
 * Provisions the Nextcloud contact a client or contact needs before it is
 * saved, and saves a demo object: ticket sections through TicketService,
 * which forces the `ticketType` discriminator on, every other section to its
 * own schema. Exactly one write happens per save.
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */
class DemoSeedWriter {
	/**
	 * Constructor.
	 *
	 * @param TicketService       $ticketService       Unified ticket write path.
	 * @param ContactVcardService $contactVcardService Contact-first identity provisioning.
	 */
	public function __construct(
		private readonly TicketService $ticketService,
		private readonly ContactVcardService $contactVcardService,
	) {
	}//end __construct()

	/**
	 * The payload with the `contactsUid` of its provisioned Nextcloud contact,
	 * or null when Nextcloud Contacts cannot provision one.
	 *
	 * @param array<string, mixed> $data       The payload about to be saved.
	 * @param string               $objectType 'client' or 'contact'.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function withIdentity(array $data, string $objectType): ?array {
		$provision = $this->contactVcardService->provisionContactFromForm(form: $data, objectType: $objectType);
		if ($provision === null) {
			return null;
		}

		$data['contactsUid'] = $provision['contactsUid'];
		return $data;
	}//end withIdentity()

	/**
	 * Save a demo object, new or over an existing uuid.
	 *
	 * @param DemoSeedTarget       $target Where the object is written.
	 * @param array<string, mixed> $data   The object payload.
	 * @param string|null          $uuid   The existing object's uuid, or null for a new one.
	 *
	 * @return object The saved entity.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function save(DemoSeedTarget $target, array $data, ?string $uuid=null): object {
		if ($target->ticketType !== null) {
			return $this->ticketService->save(ticketType: $target->ticketType, payload: $data, uuid: $uuid);
		}

		return $target->objectService->saveObject($data, [], $target->registerId, $target->schemaId, $uuid);
	}//end save()
}//end class
