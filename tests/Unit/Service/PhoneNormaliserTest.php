<?php

/**
 * Unit tests for PhoneNormaliser.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-8.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\PhoneNormaliser;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PhoneNormaliser.
 */
class PhoneNormaliserTest extends TestCase {
	/**
	 * Mock app config.
	 *
	 * @var IAppConfig
	 */
	private IAppConfig $appConfig;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * The normaliser under test.
	 *
	 * @var PhoneNormaliser
	 */
	private PhoneNormaliser $normaliser;

	/**
	 * Set up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturn('NL');

		$this->normaliser = new PhoneNormaliser($this->appConfig,
			$this->logger,
		);
	}//end setUp()

	/**
	 * Test E.164 input passes through unchanged (digits only).
	 *
	 * @return void
	 */
	public function testE164PassesThrough(): void {
		$result = $this->normaliser->normaliseForOrg('+31612345678');
		$this->assertSame('+31612345678', $result['e164']);
		$this->assertSame('+31612345678', $result['raw']);
	}//end testE164PassesThrough()

	/**
	 * Test Dutch national format gets a +31 prefix.
	 *
	 * @return void
	 */
	public function testNationalLeadingZeroBecomesE164(): void {
		$result = $this->normaliser->normaliseForOrg('06 12345678');
		$this->assertSame('+31612345678', $result['e164']);
	}//end testNationalLeadingZeroBecomesE164()

	/**
	 * Test 00 prefix becomes a + sign.
	 *
	 * @return void
	 */
	public function testDoubleZeroBecomesE164(): void {
		$result = $this->normaliser->normaliseForOrg('0031612345678');
		$this->assertSame('+31612345678', $result['e164']);
	}//end testDoubleZeroBecomesE164()

	/**
	 * Test an unparseable input returns null e164.
	 *
	 * @return void
	 */
	public function testGarbageReturnsNull(): void {
		$result = $this->normaliser->normaliseForOrg('???');
		$this->assertNull($result['e164']);
		$this->assertSame('???', $result['raw']);
	}//end testGarbageReturnsNull()

	/**
	 * Test empty string returns null.
	 *
	 * @return void
	 */
	public function testEmptyStringReturnsNull(): void {
		$result = $this->normaliser->normaliseForOrg('');
		$this->assertNull($result['e164']);
	}//end testEmptyStringReturnsNull()

	/**
	 * A provider callback names the sender internationally, often without the
	 * '+': Meta and MessageBird send 31611119999. That is +31611119999, not a
	 * national number to prefix with the country code again.
	 *
	 * @return void
	 */
	public function testInboundSenderWithoutPlusIsInternational(): void {
		$this->assertSame('+31611119999', $this->normaliser->normaliseInbound('31611119999'));
	}//end testInboundSenderWithoutPlusIsInternational()

	/**
	 * An inbound sender already in E.164, in 00-form or national form ends up
	 * in the same E.164 string, so an opt-out on the number matches every
	 * later send to it.
	 *
	 * @return void
	 */
	public function testInboundSenderFormsMeetInOneE164(): void {
		$this->assertSame('+31611119999', $this->normaliser->normaliseInbound('+31 6 1111 9999'));
		$this->assertSame('+31611119999', $this->normaliser->normaliseInbound('0031611119999'));
		$this->assertSame('+31611119999', $this->normaliser->normaliseInbound('0611119999'));
	}//end testInboundSenderFormsMeetInOneE164()

	/**
	 * A sender that is not a phone number is kept as it came, trimmed, rather
	 * than dropped: the caller still has an address to record.
	 *
	 * @return void
	 */
	public function testInboundSenderThatIsNoNumberIsKept(): void {
		$this->assertSame('???', $this->normaliser->normaliseInbound(' ??? '));
		$this->assertSame('', $this->normaliser->normaliseInbound(''));
	}//end testInboundSenderThatIsNoNumberIsKept()
}//end class
