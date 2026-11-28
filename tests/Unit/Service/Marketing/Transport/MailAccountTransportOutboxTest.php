<?php

/**
 * MailAccountTransport with the Mail app present: every way a send ends.
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

use OCA\Mail\Service\AccountService;
use OCA\Mail\Service\OutboxService;
use OCA\Pipelinq\Service\Marketing\Transport\MailAccountTransport;
use OCA\Pipelinq\Service\Marketing\Transport\MailAddressing;
use OCA\Pipelinq\Service\Marketing\Transport\MailContent;
use OCA\Pipelinq\Service\Marketing\Transport\RenderedMail;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Runs in its own process: the fake Mail app classes must not leak into
 * the tests that need the Mail app absent.
 */
#[RunTestsInSeparateProcesses]
class MailAccountTransportOutboxTest extends TestCase {

	/**
	 * Load the fake Mail app into this test's own process.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		require_once __DIR__.'/Fixtures/FakeMailApp.php';
	}//end setUp()

	/**
	 * A rendered mail.
	 *
	 * @return RenderedMail
	 */
	private function mail(): RenderedMail {
		return new RenderedMail(
			addressing: new MailAddressing(fromEmail: 'noreply@example.test', fromName: 'Pipelinq', replyTo: '', toEmail: 'user@example.test'),
			content: new MailContent(subject: 'Hi', html: '<p>hi</p>', text: 'hi'),
			headers: [],
			deliveryId: 'd-7',
		);
	}//end mail()

	/**
	 * A transport over a container that hands out the given services.
	 *
	 * @param array<string, mixed> $services Class => service, or a Throwable.
	 * @param LoggerInterface      $logger   The logger.
	 *
	 * @return MailAccountTransport
	 */
	private function transport(array $services, LoggerInterface $logger): MailAccountTransport {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $class) use ($services) {
				$service = ($services[$class] ?? null);
				if ($service instanceof \Throwable) {
					throw $service;
				}

				return $service;
			}
		);

		return new MailAccountTransport($container, $logger, mailAccountRef: '12', mailAccountUserId: 'alice');
	}//end transport()

	/**
	 * A send through the owner's account saves the message to the outbox and sends it.
	 *
	 * @return void
	 */
	public function testASendGoesThroughTheOwnersOutbox(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$transport = $this->transport(['\OCA\Mail\Service\AccountService' => new AccountService(), '\OCA\Mail\Service\OutboxService' => new OutboxService()], $logger);

		$result = $transport->send($this->mail());

		$this->assertSame([true, null], [$result->accepted, $result->error]);
		$this->assertSame([['find', 'alice', 12]], AccountService::$calls);
		$this->assertCount(2, OutboxService::$calls);
		[$save, $send] = OutboxService::$calls;
		$this->assertSame('saveMessage', $save[0]);
		$this->assertSame([['setSubject', 'Hi'], ['setBodyPlain', 'hi'], ['setHtml', true], ['setBodyHtml', '<p>hi</p>']], $save[2]);
		$this->assertSame([[['email' => 'user@example.test']], [], []], [$save[3], $save[4], $save[5]]);
		$this->assertSame('sendMessage', $send[0]);
		$this->assertSame($save[1], $send[2]);
	}//end testASendGoesThroughTheOwnersOutbox()

	/**
	 * A failed account lookup is not found, and nothing reaches the outbox.
	 *
	 * @return void
	 */
	public function testAFailedLookupIsNotFound(): void {
		AccountService::$failure = new RuntimeException('no such account');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('MailAccountTransport.send: account lookup failed', ['deliveryId' => 'd-7', 'exception' => 'no such account']);
		$transport = $this->transport(['\OCA\Mail\Service\AccountService' => new AccountService(), '\OCA\Mail\Service\OutboxService' => new OutboxService()], $logger);

		$result = $transport->send($this->mail());

		$this->assertSame([false, 'mail-account-not-found'], [$result->accepted, $result->error]);
		$this->assertSame([], OutboxService::$calls);
	}//end testAFailedLookupIsNotFound()

	/**
	 * A failed outbox send is a send failure, logged.
	 *
	 * @return void
	 */
	public function testAFailedOutboxSendFails(): void {
		OutboxService::$failure = new RuntimeException('smtp');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('MailAccountTransport.send: outbox send failed', ['deliveryId' => 'd-7', 'exception' => 'smtp']);
		$transport = $this->transport(['\OCA\Mail\Service\AccountService' => new AccountService(), '\OCA\Mail\Service\OutboxService' => new OutboxService()], $logger);

		$result = $transport->send($this->mail());

		$this->assertSame([false, 'mail-account-send-failed'], [$result->accepted, $result->error]);
	}//end testAFailedOutboxSendFails()

	/**
	 * A service the container cannot build, or hands back as a non-object,
	 * is the Mail app unavailable, logged at debug and info; the account is
	 * never looked up.
	 *
	 * @return void
	 */
	public function testAnUnresolvableServiceIsTheMailAppUnavailable(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with('MailAccountTransport.resolve: container resolution failed', ['class' => '\OCA\Mail\Service\AccountService', 'exception' => 'boom']);
		$logger->expects($this->once())->method('info')->with('MailAccountTransport.send: Mail app unavailable', ['deliveryId' => 'd-7']);
		$transport = $this->transport(['\OCA\Mail\Service\AccountService' => new RuntimeException('boom'), '\OCA\Mail\Service\OutboxService' => new OutboxService()], $logger);
		$result = $transport->send($this->mail());
		$this->assertSame([false, 'mail-app-not-available'], [$result->accepted, $result->error]);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('debug');
		$transport = $this->transport(['\OCA\Mail\Service\AccountService' => new AccountService(), '\OCA\Mail\Service\OutboxService' => 'not an object'], $logger);
		$this->assertSame('mail-app-not-available', $transport->send($this->mail())->error);
		$this->assertSame([], AccountService::$calls);
	}//end testAnUnresolvableServiceIsTheMailAppUnavailable()

	/**
	 * A malformed reference is logged with the reference and never resolves anything.
	 *
	 * @return void
	 */
	public function testAMalformedReferenceIsLoggedAndResolvesNothing(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('MailAccountTransport.send: malformed mailAccountRef/mailAccountUserId', ['deliveryId' => 'd-7', 'mailAccountRef' => '-1']);

		$result = (new MailAccountTransport($container, $logger, mailAccountRef: '-1', mailAccountUserId: 'alice'))->send($this->mail());

		$this->assertSame('malformed-mail-account-reference', $result->error);
	}//end testAMalformedReferenceIsLoggedAndResolvesNothing()
}//end class
