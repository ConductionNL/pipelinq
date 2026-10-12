<?php

/**
 * Test fake for the website form suite: OpenRegister FormSubmitRefusedException, with the same two readers.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes;

use RuntimeException;

/**
 * A refusal with the status and findings readers OpenRegister's refusal has.
 */
class FakeRefusal extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string                           $message  The message.
	 * @param int                              $status   The status.
	 * @param array<int, array<string, mixed>> $findings The findings.
	 */
	public function __construct(string $message, private int $status, private array $findings = []) {
		parent::__construct($message);
	}//end __construct()

	/**
	 * The status.
	 *
	 * @return int The status.
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()

	/**
	 * The findings.
	 *
	 * @return array<int, array<string, mixed>> The findings.
	 */
	public function getFindings(): array {
		return $this->findings;
	}//end getFindings()
}//end class
