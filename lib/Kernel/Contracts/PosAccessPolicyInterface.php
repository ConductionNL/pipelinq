<?php

/**
 * Contract of PosAccessPolicy
 *
 * The public surface of PosAccessPolicy that other components call, so they depend
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

/**
 * Contract of PosAccessPolicy.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/archive/2026-06-01-pos-lifecycle-guard-adoption/tasks.md#2.1
 */
interface PosAccessPolicyInterface {

	/**
	 * Whether a user may drive a cashier-level transition on a transaction.
	 *
	 * Grants access when the user is the transaction's own cashier, a member of
	 * the configured POS group (default `pos`), or a Nextcloud admin. Fails
	 * closed for an empty user.
	 *
	 * @param array<string, mixed> $object The posTransaction object payload.
	 * @param string $userId The acting user UID.
	 *
	 * @return bool Whether the user may transition this transaction.
	 *
	 * @spec openspec/changes/archive/2026-06-01-pos-lifecycle-guard-adoption/tasks.md#2.1
	 */
	public function canAccessTransaction(array $object, string $userId): bool;

	/**
	 * Whether a user is a POS operator (may use the shared POS surfaces).
	 *
	 * Grants access to a member of the configured POS group (default `pos`) or a
	 * Nextcloud admin. Used to gate the catalogue surfaces (barcode lookup /
	 * price resolution) that are not tied to a single owned object but are still
	 * a cashier capability rather than an any-authenticated-user one. Fails
	 * closed.
	 *
	 * @param string $userId The acting user UID.
	 *
	 * @return bool Whether the user may use the POS surfaces.
	 *
	 * @spec openspec/changes/archive/2026-06-01-pos-lifecycle-guard-adoption/tasks.md#4.2
	 */
	public function isPosUser(string $userId): bool;

	/**
	 * Whether a user is a POS manager (refund / refund-completion authority).
	 *
	 * A manager is a member of the configured manager group
	 * (`pos_manager_group`) or a Nextcloud administrator. Fails closed: when no
	 * group is configured, only NC admins qualify.
	 *
	 * @param string $userId The acting user UID.
	 *
	 * @return bool Whether the user is a POS manager.
	 *
	 * @spec openspec/changes/archive/2026-06-01-pos-lifecycle-guard-adoption/tasks.md#2.1
	 */
	public function isManager(string $userId): bool;
}//end interface
