<?php

/**
 * MailAccountTransport when the Mail app's services resolve but its
 * LocalMessage entity does not exist.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing\Transport
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

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing\Transport;

use OCA\Pipelinq\Service\Marketing\Transport\MailAccountTransport;
use OCA\Pipelinq\Service\Marketing\Transport\MailAddressing;
use OCA\Pipelinq\Service\Marketing\Transport\MailContent;
use OCA\Pipelinq\Service\Marketing\Transport\RenderedMail;
use OCA\Pipelinq\Service\Marketing\Transport\SendResult;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs in its own process: the Mail app service names are aliased onto
 * local stand-ins here and must not leak into other tests. The
 * LocalMessage entity is deliberately left undefined.
 *
 * @covers \OCA\Pipelinq\Service\Marketing\Transport\MailAccountTransport
 * @uses   \OCA\Pipelinq\Service\Marketing\Transport\MailAddressing
 * @uses   \OCA\Pipelinq\Service\Marketing\Transport\MailContent
 * @uses   \OCA\Pipelinq\Service\Marketing\Transport\RenderedMail
 * @uses   \OCA\Pipelinq\Service\Marketing\Transport\SendResult
 */
#[RunTestsInSeparateProcesses]
class MailAccountTransportWithoutLocalMessageTest extends TestCase {

	/**
	 * Calls made on the stand-in services, as [service, method, ...args].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public static array $calls = [];

	/**
	 * A send whose account resolves, but with no LocalMessage class to build
	 * the message from, is refused as the Mail app unavailable and never
	 * reaches the outbox.
	 *
	 * @return void
	 */
	public function testAMissingLocalMessageClassRefusesBeforeTheOutbox(): void {
		self::$calls = [];
		$accountService = new class {
			/**
			 * Find one account of one user.
			 *
			 * @param string $userId    The owner.
			 * @param int    $accountId The account id.
			 *
			 * @return object The account.
			 */
			public function find(string $userId, int $accountId): object {
				MailAccountTransportWithoutLocalMessageTest::$calls[] = ['accounts', 'find', $userId, $accountId];
				return (object)['id' => $accountId];
			}
		};
		$outboxService = new class {
			/**
			 * Record any outbox call.
			 *
			 * @param string       $name The method.
			 * @param array<mixed> $args Its arguments.
			 *
			 * @return null
			 */
			public function __call(string $name, array $args): mixed {
				MailAccountTransportWithoutLocalMessageTest::$calls[] = ['outbox', $name];
				return null;
			}
		};
		class_alias($accountService::class, 'OCA\Mail\Service\AccountService');
		class_alias($outboxService::class, 'OCA\Mail\Service\OutboxService');
		$this->assertFalse(class_exists('OCA\Mail\Db\LocalMessage'));

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				['\OCA\Mail\Service\AccountService', $accountService],
				['\OCA\Mail\Service\OutboxService', $outboxService],
			]
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$logger->expects($this->never())->method('debug');

		$result = (new MailAccountTransport($container, $logger, mailAccountRef: '7', mailAccountUserId: 'bob'))->send(
			new RenderedMail(
				addressing: new MailAddressing(fromEmail: 'noreply@example.test', fromName: 'Pipelinq', replyTo: '', toEmail: 'user@example.test'),
				content: new MailContent(subject: 'Hi', html: '<p>hi</p>', text: 'hi'),
				headers: [],
				deliveryId: 'd-9',
			)
		);

		$this->assertInstanceOf(SendResult::class, $result);
		$this->assertSame([false, 'mail-app-not-available', null], [$result->accepted, $result->error, $result->providerId]);
		$this->assertSame([['accounts', 'find', 'bob', 7]], self::$calls);
	}//end testAMissingLocalMessageClassRefusesBeforeTheOutbox()
}//end class
