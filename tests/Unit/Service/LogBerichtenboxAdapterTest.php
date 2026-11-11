<?php

/**
 * LogBerichtenboxAdapter::dispatchMessage: what reaches the log.
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
 * The BSN and the body never reach the log; the rest of the message does.
 */
class LogBerichtenboxAdapterTest extends TestCase {

	/**
	 * Dispatch a message and return what was logged and the result.
	 *
	 * @param array<string, mixed> $message The message.
	 *
	 * @return array{0: array<int, mixed>, 1: \OCA\Pipelinq\Service\External\Berichtenbox\BerichtenboxResult}
	 */
	private function dispatch(array $message): array {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			static function (string $text, array $context) use (&$logged): void {
				$logged[] = [$text, $context];
			}
		);

		$result = (new LogBerichtenboxAdapter($logger))->dispatchMessage(message: $message);

		return [$logged, $result];
	}//end dispatch()

	/**
	 * Every redaction rule: the BSN is replaced, the body by its byte length,
	 * a list of attachments by its count; other fields go through as they
	 * are; absent fields stay absent and non-list attachments stay; the
	 * result is deferred under the logged reference.
	 *
	 * @return void
	 */
	public function testEveryRedactionRule(): void {
		[$logged, $result] = $this->dispatch(['recipientBsn' => '999993653', 'subject' => 'Besluit', 'body' => 'héllo', 'attachments' => [['name' => 'a.pdf'], ['name' => 'b.pdf']]]);

		$this->assertCount(1, $logged);
		$this->assertSame('Pipelinq Berichtenbox dispatch deferred (no outbound connector bound)', $logged[0][0]);
		$this->assertSame(['recipientBsn' => '[REDACTED]', 'subject' => 'Besluit', 'body' => '[REDACTED-body-bytes=6]', 'attachments' => ['_redacted' => true, 'count' => 2]], $logged[0][1]['message']);
		$this->assertMatchesRegularExpression('/^bbk-log-[0-9a-f]{16}$/', $logged[0][1]['logiusKenmerk']);
		$this->assertSame(['logiusKenmerk', 'message'], array_keys($logged[0][1]));
		$this->assertSame(['DISPATCH_DEFERRED', $logged[0][1]['logiusKenmerk'], true], [$result->outcome, $result->logiusReference, $result->dormant]);
		$this->assertSame(['reason' => 'no-outbound-connector-bound', 'note' => 'Bind LogiusConnector (or an openconnector source slug `logius-berichtenbox`) by overriding BerichtenboxAdapterInterface in Application::register() to enable real transport.'], $result->extras);
		$this->assertStringNotContainsString('999993653', json_encode($logged));

		[$logged] = $this->dispatch(['subject' => 'x', 'attachments' => 'none', 'recipientBsn' => null]);
		$this->assertSame(['subject' => 'x', 'attachments' => 'none', 'recipientBsn' => null], $logged[0][1]['message']);
	}//end testEveryRedactionRule()
	/**
	 * The webhook check and the mailbox check log without the signature or
	 * the BSN, and answer deferred with their own note.
	 *
	 * @return void
	 */
	public function testTheOtherChecksAreDeferredToo(): void {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			static function (string $text, array $context) use (&$logged): void {
				$logged[] = [$text, $context];
			}
		);
		$adapter = new LogBerichtenboxAdapter($logger);

		$verify = $adapter->verifyDeliveryWebhook(rawBody: 'abc', headers: ['x-logius-signature' => 'sig', 'Content-Type' => 'json']);
		$this->assertSame('Pipelinq Berichtenbox verifyDeliveryWebhook deferred (no outbound connector bound)', $logged[0][0]);
		$this->assertSame(['bodyLength' => 3, 'signaturePresent' => true, 'headers' => ['Content-Type' => 'json']], array_slice($logged[0][1], 1));
		$this->assertMatchesRegularExpression('/^bbk-verify-log-[0-9a-f]{12}$/', $logged[0][1]['logiusKenmerk']);
		$this->assertSame(
			['VERIFY_DEFERRED', $logged[0][1]['logiusKenmerk'], true, ['reason' => 'no-outbound-connector-bound', 'note' => 'Bind LogiusConnector and override BerichtenboxAdapterInterface in Application::register() to enable real webhook HMAC verification.']],
			[$verify->outcome, $verify->logiusReference, $verify->dormant, $verify->extras]
		);

		$mailbox = $adapter->checkMailbox(bsn: '999993653');
		$this->assertSame('Pipelinq Berichtenbox checkMailbox deferred (no outbound connector bound)', $logged[1][0]);
		$this->assertSame(['bsn' => '[REDACTED]', 'bsn_length_check' => true], array_slice($logged[1][1], 1));
		$this->assertMatchesRegularExpression('/^bbk-mbox-log-[0-9a-f]{12}$/', $logged[1][1]['logiusKenmerk']);
		$this->assertSame(
			['MAILBOX_DEFERRED', $logged[1][1]['logiusKenmerk'], true, ['reason' => 'no-outbound-connector-bound', 'note' => 'Bind LogiusConnector and override BerichtenboxAdapterInterface in Application::register() to enable real mailbox reachability checks. NEVER log BSN values.']],
			[$mailbox->outcome, $mailbox->logiusReference, $mailbox->dormant, $mailbox->extras]
		);
		$this->assertStringNotContainsString('999993653', json_encode($logged));
	}//end testTheOtherChecksAreDeferredToo()
}//end class
