<?php

/**
 * Unit tests for MailAccountTransport.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing\Transport
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-a-mail-account-transport-sends-through-the-senders-own-account
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing\Transport;

use OCA\Pipelinq\Service\Marketing\Transport\MailAccountTransport;
use OCA\Pipelinq\Service\Marketing\Transport\RenderedMail;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for MailAccountTransport — the Mail-app-absent degrade-soft path
 * and the mailAccountRef type-boundary guard. The Mail app
 * (`\OCA\Mail\Service\AccountService` etc.) is never installed in this
 * unit-test environment, so `class_exists()` on its FQCNs is always false —
 * exactly the scenario this adapter must degrade soft against.
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-a-mail-account-transport-sends-through-the-senders-own-account
 */
class MailAccountTransportTest extends TestCase {
	private ContainerInterface $container;
	private LoggerInterface $logger;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willThrowException(new RuntimeException('Mail app not installed'));
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build a minimal RenderedMail.
	 *
	 * @return RenderedMail
	 */
	private function mail(): RenderedMail {
		return new RenderedMail('noreply@example.test', 'Pipelinq', '', 'user@example.test', 'Hi', '<p>hi</p>', 'hi', [], 'd-1');
	}//end mail()

	/**
	 * The Mail app is absent (`class_exists()` on its FQCN is false, so the
	 * container is never even asked): the send fails soft, no exception.
	 *
	 * @return void
	 */
	public function testSendDegradesSoftWhenMailAppAbsent(): void {
		$transport = new MailAccountTransport($this->container, $this->logger, mailAccountRef: '1', mailAccountUserId: 'alice');

		$result = $transport->send($this->mail());

		$this->assertFalse($result->accepted);
		$this->assertSame('mail-app-not-available', $result->error);
	}//end testSendDegradesSoftWhenMailAppAbsent()

	/**
	 * A non-numeric `mailAccountRef` fails soft before any container
	 * resolution is attempted, rather than letting a cast to `int` throw.
	 *
	 * @return void
	 */
	public function testSendFailsSoftOnMalformedAccountRef(): void {
		$transport = new MailAccountTransport($this->container, $this->logger, mailAccountRef: 'not-a-number', mailAccountUserId: 'alice');

		$result = $transport->send($this->mail());

		$this->assertFalse($result->accepted);
		$this->assertSame('malformed-mail-account-reference', $result->error);
	}//end testSendFailsSoftOnMalformedAccountRef()

	/**
	 * An empty `mailAccountUserId` (the IDOR guard) fails soft — the Mail
	 * app's `AccountService::find()` is scoped per-user and must never be
	 * called with an empty/ambiguous user id.
	 *
	 * @return void
	 */
	public function testSendFailsSoftWhenAccountUserIdMissing(): void {
		$transport = new MailAccountTransport($this->container, $this->logger, mailAccountRef: '1', mailAccountUserId: '');

		$result = $transport->send($this->mail());

		$this->assertFalse($result->accepted);
		$this->assertSame('malformed-mail-account-reference', $result->error);
	}//end testSendFailsSoftWhenAccountUserIdMissing()
}//end class
