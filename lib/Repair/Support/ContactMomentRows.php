<?php

/**
 * Pipelinq ContactMomentRows.
 *
 * Every contact-moment ticket of a register, read without RBAC for a repair step.
 *
 * @category Util
 * @package  OCA\Pipelinq\Repair\Support
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair\Support;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\TicketServiceInterface;

/**
 * Reads up to the host's BATCH_LIMIT contact-moment tickets with RBAC and
 * multitenancy off, as a repair step on the CLI must.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ContactMomentRows {

	/**
	 * Fetch every stored contact moment, with RBAC off.
	 *
	 * @param ObjectServiceInterface $objectService The OR object service.
	 * @param string $register The register id.
	 * @param string $schema The ticket schema id.
	 *
	 * @return array<mixed>
	 *
	 * @spec openspec/specs/contactmomenten/spec.md#requirement-a-contact-moment-references-a-case-semantically-req-cmd-002
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function contactMomentRows(ObjectServiceInterface $objectService, string $register, string $schema): array {
		return $objectService->findAll(
			config: [
				'filters' => [
					'register' => $register,
					'schema' => $schema,
					'ticketType' => TicketServiceInterface::TYPE_CONTACTMOMENT,
				],
				'limit' => self::BATCH_LIMIT,
			],
			_rbac: false,
			_multitenancy: false,
		);
	}//end contactMomentRows()
}//end trait
