<?php

/**
 * Characterisation tests for BerichtenboxService::handleInboundReply().
 *
 * Pins how a citizen's reply is stored (the BSN re-encrypted from the
 * parent, its hash, the reply fields), the contactmoment it opens, the
 * second write that links the two, the audit entries, and the two failure
 * paths, so the method can be restructured without changing them.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\BerichtenboxService;
use OCA\Pipelinq\Service\DeliveryAuditLogger;
use OCA\Pipelinq\Service\DutchHolidayCalendar;
use OCA\Pipelinq\Service\EmailFallbackSender;
use OCA\Pipelinq\Service\EncryptionService;
use OCA\Pipelinq\Service\LogiusConnector;
use OCA\Pipelinq\Service\MailboxResolver;
use OCA\Pipelinq\Service\TemplateRenderer;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for a citizen's reply through Berichtenbox.
 */
class BerichtenboxInboundReplyTest extends TestCase {

	/**
	 * Reply writes, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $replies = [];

	/**
	 * Contactmoment writes, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $moments = [];

	/**
	 * Audit entries, as [method, arguments].
	 *
	 * @var array<int, array{0: string, 1: array<int, mixed>}>
	 */
	private array $audit = [];

	/**
	 * Which reply write throws (1-based), or 0 for none.
	 *
	 * @var integer
	 */
	private int $failingReplyWrite = 0;

	/**
	 * The encryption under test, shared with the parent fixture.
	 *
	 * @var EncryptionService|null
	 */
	private ?EncryptionService $encryption = null;

	/**
	 * A reply is stored with the parent's BSN re-encrypted, opens a
	 * contactmoment, is linked to it and is audited.
	 *
	 * @return void
	 */
	public function testAReplyIsStoredLinkedAndAudited(): void {
		$reply = $this->service(ticketsConfigured: true)->handleInboundReply('msg-1', 'lg-7', 'Ik heb een vraag', [['name' => 'a.pdf']]);

		$this->assertCount(2, $this->replies);
		$first = $this->replies[0];
		$this->assertSame(['uuid', 'parentMessageId', 'logiusReplyId', 'receivedAt', 'bsn', 'bsnHash', 'bodyText', 'attachments'], array_keys($first));
		$this->assertSame(['reply-uuid', 'msg-1', 'lg-7', 'Ik heb een vraag', [['name' => 'a.pdf']]], [$first['uuid'], $first['parentMessageId'], $first['logiusReplyId'], $first['bodyText'], $first['attachments']]);
		$this->assertSame('123456782', $this->encryption()->decrypt($first['bsn'], 'tenant-a'));
		$this->assertSame($this->encryption()->hashBsn('123456782', 'tenant-a'), $first['bsnHash']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $first['receivedAt']);

		$this->assertSame(
			[['title' => 'Re: Uw paspoort', 'description' => 'Ik heb een vraag', 'channel' => 'berichtenbox', 'direction' => 'inbound', 'outcome' => 'opvolging-nodig', 'caseId' => 'Z-1', 'parentMessageId' => 'msg-1', 'berichtenboxReplyId' => 'reply-uuid']],
			$this->moments
		);

		$second = $this->replies[1];
		$this->assertSame('cm-1', $second['createdInteractionId']);
		$this->assertArrayHasKey('processedAt', $second);
		$this->assertSame(['@self', 'id', 'createdInteractionId', 'processedAt'], array_values(array_diff(array_keys($second), array_keys($first))));
		$this->assertSame([['logReplyReceived', ['reply-uuid', 'hash:Ik heb een vraag', null, 'system']]], $this->audit);
		$this->assertSame('cm-1', $reply['createdInteractionId']);
	}//end testAReplyIsStoredLinkedAndAudited()

	/**
	 * A reply to an unknown message is refused before anything is written.
	 *
	 * @return void
	 */
	public function testAReplyToAnUnknownMessageIsRefused(): void {
		try {
			$this->service(ticketsConfigured: true)->handleInboundReply('missing', 'lg-1', 'x');
			$this->fail('an unknown parent must be refused');
		} catch (RuntimeException $e) {
			$this->assertSame('Berichtenbox inbound reply: parent message not found: missing', $e->getMessage());
		}

		$this->assertSame([[], [], []], [$this->replies, $this->moments, $this->audit]);
	}//end testAReplyToAnUnknownMessageIsRefused()

	/**
	 * A reply that cannot be stored is audited as a processing error and rethrown.
	 *
	 * @return void
	 */
	public function testAReplyThatCannotBeStoredIsAuditedAndRethrown(): void {
		$this->failingReplyWrite = 1;

		try {
			$this->service(ticketsConfigured: true)->handleInboundReply('msg-1', 'lg-7', 'Body');
			$this->fail('the store failure must surface');
		} catch (RuntimeException $e) {
			$this->assertSame('reply store down', $e->getMessage());
		}

		$this->assertSame([], $this->moments);
		$this->assertSame([['logProcessingError', ['reply-uuid', 'reply store down', 'hash:Body', null, 'system']]], $this->audit);
	}//end testAReplyThatCannotBeStoredIsAuditedAndRethrown()

	/**
	 * A reply whose contactmoment cannot be opened keeps the error on the
	 * reply, is audited and rethrown.
	 *
	 * @return void
	 */
	public function testAReplyWithoutAContactmomentKeepsTheError(): void {
		try {
			$this->service(ticketsConfigured: false)->handleInboundReply('msg-1', 'lg-7', 'Body');
			$this->fail('the contactmoment failure must surface');
		} catch (RuntimeException $e) {
			$this->assertSame('Contactmoment register or schema not configured.', $e->getMessage());
		}

		$this->assertCount(2, $this->replies);
		$this->assertSame('Contactmoment register or schema not configured.', $this->replies[1]['processingError']);
		$this->assertArrayNotHasKey('createdInteractionId', $this->replies[1]);
		$this->assertSame([['logProcessingError', ['reply-uuid', 'Contactmoment register or schema not configured.', 'hash:Body', null, 'system']]], $this->audit);
	}//end testAReplyWithoutAContactmomentKeepsTheError()

	/**
	 * The encryption service, one per test.
	 *
	 * @return EncryptionService
	 */
	private function encryption(): EncryptionService {
		if ($this->encryption === null) {
			$config = $this->createMock(IConfig::class);
			$config->method('getSystemValue')->willReturn('berichtenbox-reply-test-secret');
			$this->encryption = new EncryptionService($config, $this->createMock(LoggerInterface::class));
		}

		return $this->encryption;
	}//end encryption()

	/**
	 * An entity as OpenRegister returns it.
	 *
	 * @param array<string, mixed> $payload The object.
	 *
	 * @return ObjectEntityInterface
	 */
	private static function entity(array $payload): ObjectEntityInterface {
		$entity = new ObjectEntity();
		$entity->setUuid((string)($payload['uuid'] ?? 'x'));
		$entity->setObject($payload);
		return $entity;
	}//end entity()

	/**
	 * The service over recording doubles, with one parent message stored.
	 *
	 * @param bool $ticketsConfigured Whether the contactmoment store is configured.
	 *
	 * @return BerichtenboxService
	 */
	private function service(bool $ticketsConfigured): BerichtenboxService {
		$parent = ['uuid' => 'msg-1', 'subject' => 'Uw paspoort', 'caseId' => 'Z-1', 'bsn' => $this->encryption()->encrypt('123456782', 'tenant-a')];
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			static fn (string $id): ?ObjectEntityInterface => ($id === 'msg-1' ? self::entity(payload: $parent) : null)
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntityInterface {
				$this->replies[] = $object;
				if (count($this->replies) === $this->failingReplyWrite) {
					throw new RuntimeException('reply store down');
				}

				return self::entity(payload: $object);
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$container->method('has')->willReturn(false);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['register' => 'reg-1', 'berichtenboxMessage_schema' => 'sch-msg', 'berichtenboxReply_schema' => 'sch-reply', 'tenant_id' => 'tenant-a'][$key] ?? $default)
		);

		$logius = $this->createMock(LogiusConnector::class);
		$logius->method('newUuidV4')->willReturn('reply-uuid');

		$audit = $this->createMock(DeliveryAuditLogger::class);
		$audit->method('hashPayload')->willReturnCallback(static fn (string $body): string => 'hash:' . $body);
		foreach (['logReplyReceived', 'logProcessingError'] as $method) {
			$audit->method($method)->willReturnCallback(
				function (...$arguments) use ($method): void {
					$this->audit[] = [$method, $arguments];
				}
			);
		}

		$tickets = $this->createMock(TicketService::class);
		$tickets->method('isConfigured')->willReturn($ticketsConfigured);
		$tickets->method('save')->willReturnCallback(
			function (string $ticketType, array $payload): ObjectEntityInterface {
				$this->moments[] = $payload;
				return self::entity(payload: ['uuid' => 'cm-1']);
			}
		);

		return new BerichtenboxService(
			$container,
			$appConfig,
			$this->encryption(),
			new TemplateRenderer($this->createMock(LoggerInterface::class)),
			$this->createMock(MailboxResolver::class),
			$logius,
			$this->createMock(EmailFallbackSender::class),
			$audit,
			new DutchHolidayCalendar(),
			$tickets,
			$this->createMock(LoggerInterface::class),
			objectService: $objects,
		);
	}//end service()
}//end class
