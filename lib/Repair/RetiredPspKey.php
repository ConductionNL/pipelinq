<?php

/**
 * Pipelinq RetiredPspKey.
 *
 * One retired app-held PSP credential row.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\Kernel\Contracts\PosPaymentServiceInterface;

/**
 * A payment provider and one of its retired secret fields, and the app-config
 * key the pair used to be stored under.
 *
 * @spec openspec/changes/pos-psp-keys-via-broker/tasks.md#task-4-delete-the-legacy-keys
 */
final class RetiredPspKey {
	/**
	 * Constructor.
	 *
	 * @param string $provider The payment provider.
	 * @param string $field    The retired secret field.
	 */
	public function __construct(
		public readonly string $provider,
		public readonly string $field,
	) {
	}//end __construct()

	/**
	 * The app-config key the secret was stored under.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/pos-psp-keys-via-broker/tasks.md#task-4-delete-the-legacy-keys
	 */
	public function configKey(): string {
		return PosPaymentServiceInterface::CONFIG_PREFIX . $this->provider . '.' . $this->field;
	}//end configKey()
}//end class
