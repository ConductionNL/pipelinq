<?php

/**
 * Contract of StoreInstallLedger
 *
 * The public surface of StoreInstallLedger that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

use DateTimeInterface;

/**
 * Contract of StoreInstallLedger.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface StoreInstallLedgerInterface {

	/**
	 * Add `installed`, `installedVersion`, `updateAvailable` and, for an
	 * installed item, `openUrl` to each card.
	 *
	 * @param array<int, array<string, mixed>> $cards The registry's cards.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/pipelinq-store-surface/spec.md
	 */
	public function annotate(array $cards): array;

	/**
	 * When the registry last answered, as an ISO date-time, or ''.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/pipelinq-store-surface/spec.md
	 */
	public function checkedAt(): string;

	/**
	 * Note that the registry answered.
	 *
	 * @param DateTimeInterface $when When it answered.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/pipelinq-store-surface/spec.md
	 */
	public function markChecked(DateTimeInterface $when): void;

	/**
	 * Record that an item was installed at a version, replacing an earlier record.
	 *
	 * @param string $slug    The store item slug.
	 * @param string $version The version that was installed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/pipelinq-store-surface/spec.md
	 */
	public function record(string $slug, string $version): void;
}//end interface
