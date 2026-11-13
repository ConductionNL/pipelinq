<?php

/**
 * Edge cases of the log-only Berichtenbox adapter.
 *
 * Pins the branches the main test leaves out: the header spellings the
 * webhook check reads and strips, a missing header, an empty body, the BSN
 * length flag for a wrong-length value, fresh references per call, and that
 * the adapter always reports itself dormant.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\External\Berichtenbox\LogBerichtenboxAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Header handling, BSN flag, references and dormancy.
 *
 * @covers \OCA\Pipelinq\Service\External\Berichtenbox\LogBerichtenboxAdapter
 * @uses   \OCA\Pipelinq\Service\External\Berichtenbox\BerichtenboxResult
 */
class LogBerichtenboxAdapterEdgeCasesTest extends TestCase {

	/**
	 * What the adapter logged, in order.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * The adapter under test.
	 *
	 * @var LogBerichtenboxAdapter
	 */
	private LogBerichtenboxAdapter $adapter;

	/**
	 * Build the adapter with a capturing logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $text, array $context): void {
				$this->logged[] = [$text, $context];
			}
		);
		$this->adapter = new LogBerichtenboxAdapter($logger);
	}//end setUp()

	/**
	 * The capitalised header counts as present and is stripped from the log.
	 *
	 * @return void
	 */
	public function testCapitalisedHeaderIsReadAndStripped(): void {
		$this->adapter->verifyDeliveryWebhook(rawBody: 'payload', headers: ['X-Logius-Signature' => 'abc', 'Accept' => '*/*']);

		self::assertTrue($this->logged[0][1]['signaturePresent']);
		self::assertSame(['Accept' => '*/*'], $this->logged[0][1]['headers']);
		self::assertSame(7, $this->logged[0][1]['bodyLength']);
	}//end testCapitalisedHeaderIsReadAndStripped()

	/**
	 * When both spellings arrive, both are stripped from the log.
	 *
	 * @return void
	 */
	public function testBothSpellingsAreStripped(): void {
		$this->adapter->verifyDeliveryWebhook(rawBody: '', headers: ['X-Logius-Signature' => 'a', 'x-logius-signature' => 'b']);

		self::assertSame([], $this->logged[0][1]['headers']);
		self::assertTrue($this->logged[0][1]['signaturePresent']);
		self::assertSame(0, $this->logged[0][1]['bodyLength']);
		self::assertStringNotContainsString('"a"', (string) json_encode($this->logged));
	}//end testBothSpellingsAreStripped()

	/**
	 * A missing or empty header is logged as absent; other headers go through.
	 *
	 * @return void
	 */
	public function testMissingOrEmptyHeaderIsAbsent(): void {
		$result = $this->adapter->verifyDeliveryWebhook(rawBody: 'x', headers: ['Content-Type' => 'application/json']);
		$this->adapter->verifyDeliveryWebhook(rawBody: 'x', headers: ['x-logius-signature' => '']);

		self::assertFalse($this->logged[0][1]['signaturePresent']);
		self::assertSame(['Content-Type' => 'application/json'], $this->logged[0][1]['headers']);
		self::assertFalse($this->logged[1][1]['signaturePresent']);
		self::assertSame([], $this->logged[1][1]['headers']);
		self::assertSame('VERIFY_DEFERRED', $result->outcome);
	}//end testMissingOrEmptyHeaderIsAbsent()

	/**
	 * Only a nine-character BSN passes the length flag; the value is never logged.
	 *
	 * @return void
	 */
	public function testBsnLengthFlagIsFalseForOtherLengths(): void {
		$this->adapter->checkMailbox(bsn: '12345678');
		$this->adapter->checkMailbox(bsn: '1234567890');
		$this->adapter->checkMailbox(bsn: '');

		self::assertSame([false, false, false], array_map(static fn (array $line): bool => $line[1]['bsn_length_check'], $this->logged));
		self::assertSame(['[REDACTED]', '[REDACTED]', '[REDACTED]'], array_map(static fn (array $line): string => $line[1]['bsn'], $this->logged));
		self::assertStringNotContainsString('12345678', (string) json_encode($this->logged));
	}//end testBsnLengthFlagIsFalseForOtherLengths()

	/**
	 * Every call gets a fresh reference that the result carries.
	 *
	 * @return void
	 */
	public function testEachCallGetsAFreshReference(): void {
		$first = $this->adapter->dispatchMessage(message: []);
		$second = $this->adapter->dispatchMessage(message: []);

		self::assertNotSame($first->logiusReference, $second->logiusReference);
		self::assertSame($first->logiusReference, $this->logged[0][1]['logiusKenmerk']);
		self::assertSame($second->logiusReference, $this->logged[1][1]['logiusKenmerk']);
		self::assertSame([], $this->logged[0][1]['message']);
	}//end testEachCallGetsAFreshReference()

	/**
	 * An empty body is redacted to a zero byte count, not passed through.
	 *
	 * @return void
	 */
	public function testEmptyBodyIsRedactedToZeroBytes(): void {
		$this->adapter->dispatchMessage(message: ['body' => '', 'attachments' => []]);

		self::assertSame(
			['body' => '[REDACTED-body-bytes=0]', 'attachments' => ['_redacted' => true, 'count' => 0]],
			$this->logged[0][1]['message']
		);
	}//end testEmptyBodyIsRedactedToZeroBytes()

	/**
	 * The log-only adapter always reports itself dormant and logs nothing for it.
	 *
	 * @return void
	 */
	public function testAdapterIsDormant(): void {
		self::assertTrue($this->adapter->isDormant());
		self::assertSame([], $this->logged);
	}//end testAdapterIsDormant()
}//end class
