<?php

/**
 * Behavioural tests for the PosTransitionErrors trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
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

namespace OCA\Pipelinq\Tests\Unit\Service\Support;

use OCA\Pipelinq\Service\Support\PosTransitionErrors;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 403, 422, 404 and pass-through mapping by message markers.
 *
 * @covers \OCA\Pipelinq\Service\Support\PosTransitionErrors
 */
class PosTransitionErrorsTest extends TestCase {

	/**
	 * Map one failure through the host.
	 *
	 * @param \Throwable $error The engine failure.
	 *
	 * @return \Throwable The mapped failure.
	 */
	private function mapped(\Throwable $error): \Throwable {
		return (new PosTransitionErrorsHost())->map($error);
	}//end mapped()

	/**
	 * @return void
	 */
	public function testMapping(): void {
		$denied = $this->mapped(new RuntimeException('no permission here'));
		$this->assertInstanceOf(OCSForbiddenException::class, $denied);
		$this->assertSame('no permission here', $denied->getMessage());

		$guard = $this->mapped(new RuntimeException('Alleen een Beheerder mag dit'));
		$this->assertInstanceOf(OCSForbiddenException::class, $guard);

		$this->assertInstanceOf(OCSBadRequestException::class, $this->mapped(new RuntimeException('Transition not allowed from draft')));
		$this->assertInstanceOf(OCSNotFoundException::class, $this->mapped(new RuntimeException('Object not found')));

		$other = new RuntimeException('boom');
		$this->assertSame($other, $this->mapped($other));
	}//end testMapping()

	/**
	 * @return void
	 */
	public function testForbiddenFallback(): void {
		$denied = $this->mapped(new NotAuthorizedException(''));
		$this->assertInstanceOf(OCSForbiddenException::class, $denied);
		$this->assertSame('Geen toegang.', $denied->getMessage());
	}//end testForbiddenFallback()
}//end class

/**
 * An engine RBAC denial, matched by short class name.
 */
final class NotAuthorizedException extends RuntimeException {
}//end class

/**
 * A host with the markers the trait reads.
 */
final class PosTransitionErrorsHost {
	use PosTransitionErrors;

	private const TRANSITION_FORBIDDEN = 'Geen toegang.';

	private const TRANSITION_ACCESS_MARKERS = ['beheerder mag'];

	private const TRANSITION_PRECONDITION_MARKERS = ['overschrijdt', 'not allowed from'];

	/**
	 * @param \Throwable $error The failure.
	 * @return \Throwable
	 */
	public function map(\Throwable $error): \Throwable {
		return $this->mapTransitionError(e: $error);
	}
}//end class
