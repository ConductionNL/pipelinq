<?php

/**
 * LogBerichtenboxAdapter: the dormancy flag and the edges of the two checks.
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
 * The log adapter reports itself dormant, strips either spelling of the
 * signature header, notices an absent signature, and flags a BSN that is not
 * nine digits long, all without logging the BSN.
 *
 * @covers \OCA\Pipelinq\Service\External\Berichtenbox\LogBerichtenboxAdapter
 * @uses   \OCA\Pipelinq\Service\External\Berichtenbox\BerichtenboxResult
 */
class LogBerichtenboxAdapterChecksTest extends TestCase {

	/**
	 * What the adapter logged, one [message, context] pair per call.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * An adapter whose logger records every info line.
	 *
	 * @return LogBerichtenboxAdapter
	 */
	private function adapter(): LogBerichtenboxAdapter {
		$this->logged = [];
		$logger       = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $text, array $context): void {
				$this->logged[] = [$text, $context];
			}
		);

		return new LogBerichtenboxAdapter($logger);
	}//end adapter()

	/**
	 * Without a bound connector the adapter is always dormant.
	 *
	 * @return void
	 */
	public function testTheLogAdapterIsDormant(): void {
		$this->assertTrue($this->adapter()->isDormant());
	}//end testTheLogAdapterIsDormant()

	/**
	 * The capitalised signature header is dropped from the log like the
	 * lower-case one.
	 *
	 * @return void
	 */
	public function testTheCapitalisedSignatureHeaderIsNotLogged(): void {
		$result = $this->adapter()->verifyDeliveryWebhook(
			rawBody: '{"id":1}',
			headers: ['X-Logius-Signature' => 'sha256=abc', 'X-Request-Id' => 'r-1']
		);

		$this->assertSame(
			['bodyLength' => 8, 'signaturePresent' => true, 'headers' => ['X-Request-Id' => 'r-1']],
			array_slice($this->logged[0][1], 1)
		);
		$this->assertStringNotContainsString('sha256=abc', json_encode($this->logged));
		$this->assertSame('VERIFY_DEFERRED', $result->outcome);
	}//end testTheCapitalisedSignatureHeaderIsNotLogged()

	/**
	 * A webhook without a signature is logged as unsigned and still deferred.
	 *
	 * @return void
	 */
	public function testAnUnsignedWebhookIsLoggedAsUnsigned(): void {
		$result = $this->adapter()->verifyDeliveryWebhook(rawBody: '', headers: []);

		$this->assertSame(['bodyLength' => 0, 'signaturePresent' => false, 'headers' => []], array_slice($this->logged[0][1], 1));
		$this->assertSame(['VERIFY_DEFERRED', true], [$result->outcome, $result->dormant]);
		$this->assertSame($this->logged[0][1]['logiusKenmerk'], $result->logiusReference);
	}//end testAnUnsignedWebhookIsLoggedAsUnsigned()

	/**
	 * A BSN that is not nine characters fails the length check, and is not logged.
	 *
	 * @return void
	 */
	public function testAShortBsnFailsTheLengthCheck(): void {
		$result = $this->adapter()->checkMailbox(bsn: '12345678');

		$this->assertSame(['bsn' => '[REDACTED]', 'bsn_length_check' => false], array_slice($this->logged[0][1], 1));
		$this->assertStringNotContainsString('12345678', json_encode($this->logged));
		$this->assertSame(['MAILBOX_DEFERRED', true], [$result->outcome, $result->dormant]);
	}//end testAShortBsnFailsTheLengthCheck()

	/**
	 * Every call gets its own reference.
	 *
	 * @return void
	 */
	public function testEveryCallGetsItsOwnReference(): void {
		$adapter = $this->adapter();

		$first  = $adapter->checkMailbox(bsn: '999993653');
		$second = $adapter->checkMailbox(bsn: '999993653');

		$this->assertNotSame($first->logiusReference, $second->logiusReference);
	}//end testEveryCallGetsItsOwnReference()
}//end class
