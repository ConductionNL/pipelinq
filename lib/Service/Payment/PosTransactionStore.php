<?php
/**
 * Pipelinq PosTransactionStore.
 *
 * Where POS transactions are read: OpenRegister's ObjectService, the register and the posTransaction schema.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Payment
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
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-011
 */
declare(strict_types=1);

namespace OCA\Pipelinq\Service\Payment;

/**
 * The object service, register id and posTransaction schema id a transaction lookup runs against.
 *
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-011
 */
final class PosTransactionStore {
	/**
	 * Constructor.
	 *
	 * @param mixed  $objectService OpenRegister's ObjectService.
	 * @param string $register      The register id.
	 * @param string $schema        The posTransaction schema id.
	 */
	public function __construct(
		public readonly mixed $objectService,
		public readonly string $register,
		public readonly string $schema,
	) {
	}//end __construct()
}//end class
