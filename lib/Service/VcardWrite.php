<?php

/**
 * Pipelinq VcardWrite.
 *
 * The vCard properties to write and the card they update, if any.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/contacts-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The vCard properties of one write and the UID of the card it updates.
 *
 * @spec openspec/specs/contacts-sync/spec.md
 */
final class VcardWrite {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $properties  The vCard properties.
	 * @param string|null          $existingUid The linked card UID, if any.
	 */
	public function __construct(
		public readonly array $properties,
		public readonly ?string $existingUid,
	) {
	}//end __construct()

	/**
	 * Whether the write updates a linked card.
	 *
	 * @return bool True when a non-empty UID is linked.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function isLinked(): bool {
		return $this->existingUid !== null && $this->existingUid !== '';
	}//end isLinked()
}//end class
