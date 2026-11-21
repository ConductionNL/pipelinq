<?php

/**
 * Unit tests for PortalException.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use OCA\Pipelinq\Service\Portal\PortalException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the safe portal error carrier.
 *
 * @covers \OCA\Pipelinq\Service\Portal\PortalException
 */
class PortalExceptionTest extends TestCase {
	/**
	 * The status, code, message and context reach the response body; the
	 * context adds its fields after the code and message.
	 *
	 * @return void
	 */
	public function testTheBodyCarriesCodeMessageAndContext(): void {
		$exception = new PortalException(429, 'rateLimited', 'Too many requests.', ['retryAfter' => 60]);

		$this->assertSame(429, $exception->getStatus());
		$this->assertSame('rateLimited', $exception->getErrorCode());
		$this->assertSame('Too many requests.', $exception->getMessage());
		$this->assertSame(['retryAfter' => 60], $exception->getContext());
		$this->assertSame(
			['errorCode' => 'rateLimited', 'message' => 'Too many requests.', 'retryAfter' => 60],
			$exception->toBody()
		);
	}//end testTheBodyCarriesCodeMessageAndContext()

	/**
	 * Without context the body is only the code and message.
	 *
	 * @return void
	 */
	public function testWithoutContextTheBodyIsCodeAndMessage(): void {
		$exception = new PortalException(404, 'notFound', 'Not found.');

		$this->assertSame([], $exception->getContext());
		$this->assertSame(['errorCode' => 'notFound', 'message' => 'Not found.'], $exception->toBody());
	}//end testWithoutContextTheBodyIsCodeAndMessage()
}//end class
