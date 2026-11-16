<?php

/**
 * Contract of ServiceAccount
 *
 * The public surface of ServiceAccount that other components call, so they depend
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

use OCP\IGroup;

/**
 * Contract of ServiceAccount.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude shared plumbing of the portal and messaging service accounts; no requirement owns the acting identity
 */
interface ServiceAccountInterface {
	/**
	 * Why the account cannot be used: none is set.
	 *
	 * @var string
	 */
	public const REASON_UNSET = 'unset';

	/**
	 * Why the account cannot be used: the uid names no account.
	 *
	 * @var string
	 */
	public const REASON_UNKNOWN = 'unknown';

	/**
	 * Why the account cannot be used: the account is disabled.
	 *
	 * @var string
	 */
	public const REASON_DISABLED = 'disabled';

	/**
	 * Why the account cannot be used: it is not in the service group.
	 *
	 * @var string
	 */
	public const REASON_NOT_IN_GROUP = 'not-in-group';

	/**
	 * The group the written schemas grant.
	 *
	 * @return string The group id.
	 * @spec exclude shared plumbing of the portal and messaging service accounts; no requirement owns the acting identity
	 */
	public function group(): string;

	/**
	 * The configured uid, or an empty string.
	 *
	 * @return string The uid.
	 * @spec exclude shared plumbing of the portal and messaging service accounts; no requirement owns the acting identity
	 */
	public function configuredUserId(): string;

	/**
	 * Whether the account can be used, and why not when it cannot.
	 *
	 * @return array{userId: string, usable: bool, reason: string|null} The status.
	 * @spec exclude shared plumbing of the portal and messaging service accounts; no requirement owns the acting identity
	 */
	public function status(): array;

	/**
	 * Pick the account: it must exist and be enabled. It joins the service group.
	 *
	 * @param string $userId The uid.
	 *
	 * @return array{userId: string, usable: bool, reason: string|null} The new status.
	 *
	 * @throws \InvalidArgumentException When the uid names no enabled account or the group cannot be made.
	 * @spec exclude shared plumbing of the portal and messaging service accounts; no requirement owns the acting identity
	 */
	public function assign(string $userId): array;

	/**
	 * Create the service group when it does not exist.
	 *
	 * @return IGroup|null The group, or null when it could not be created.
	 * @spec exclude shared plumbing of the portal and messaging service accounts; no requirement owns the acting identity
	 */
	public function ensureGroup(): ?IGroup;
}//end interface
