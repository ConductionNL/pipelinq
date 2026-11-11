<?php

/**
 * Unit tests for the OpenRegister email-leaf call in EmailMatchService::matchAndLinkMessage().
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
 * @spec openspec/specs/email-calendar-sync/spec.md#requirement-emails-must-be-automatically-linked-to-crm-contacts
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\EmailMatchService;
use OCA\Pipelinq\Service\MailMessageKey;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What matchAndLinkMessage() hands to OpenRegister's EmailLinkService::linkEmail().
 */
class EmailMatchServiceLinkTest extends TestCase {

	/**
	 * Every matched contact and client is linked once, with the message's account, id and uid.
	 *
	 * @return void
	 */
	public function testLinksEachMatchedEntityThroughTheEmailLeaf(): void {
		$calls = [];
		$service = $this->buildService(calls: $calls, linkFails: false);

		$created = $service->matchAndLinkMessage(message: new MailMessageKey(mailAccountId: 3, mailMessageId: 44), userId: 'alice');

		$this->assertSame(2, $created);
		$this->assertSame(
			[
				['c-1', 5, 7, 3, '44', 'imap-9'],
				['k-1', 5, 8, 3, '44', 'imap-9'],
			],
			$calls
		);
	}//end testLinksEachMatchedEntityThroughTheEmailLeaf()

	/**
	 * A failing leaf call is logged and not counted.
	 *
	 * @return void
	 */
	public function testAFailingLeafCallIsNotCounted(): void {
		$calls = [];
		$service = $this->buildService(calls: $calls, linkFails: true);

		$this->assertSame(0, $service->matchAndLinkMessage(message: new MailMessageKey(mailAccountId: 3, mailMessageId: 44), userId: 'alice'));
		$this->assertCount(2, $calls);
	}//end testAFailingLeafCallIsNotCounted()

	/**
	 * Build the service with one Mail message (sender a contact, one recipient a client).
	 *
	 * @param array<int,array<int,mixed>> $calls     Receives one row per linkEmail() call.
	 * @param bool                        $linkFails Whether linkEmail() throws.
	 *
	 * @return EmailMatchService
	 */
	private function buildService(array &$calls, bool $linkFails): EmailMatchService {
		$objectService = new class {
			public function findAll(array $config): array {
				$schema = $config['filters']['schema'] ?? '';
				if ($schema === '7') {
					return [['@self' => ['id' => 'c-1'], 'email' => 'jan@example.com']];
				}

				if ($schema === '8') {
					return [['@self' => ['id' => 'k-1'], 'email' => 'info@client.nl']];
				}

				return [];
			}
		};
		$linkService = new class($calls, $linkFails) {
			public function __construct(
				private array &$calls,
				private bool $fails,
			) {
			}

			public function linkEmail(...$args): void {
				$this->calls[] = self::flatten(args: $args);
				if ($this->fails === true) {
					throw new RuntimeException('leaf down');
				}
			}

			private static function flatten(array $args): array {
				if (isset($args['target']) === true) {
					$target = $args['target'];
					$message = $args['message'];
					return [
						$target->objectUuid,
						$target->registerId,
						$target->schemaId(),
						$message->mailAccountId,
						$message->messageId,
						$message->uidForMatch() ?? '',
					];
				}

				return [
					$args['objectUuid'],
					$args['registerId'],
					$args['schemaId'],
					$args['mailAccountId'],
					$args['messageId'],
					$args['messageUid'],
				];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $name) use ($objectService, $linkService) {
				if ($name === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				if ($name === 'OCA\\OpenRegister\\Service\\EmailLinkService') {
					return $linkService;
				}

				throw new RuntimeException('unknown service: ' . $name);
			}
		);

		$config = ['register' => '5', 'contact_schema' => '7', 'client_schema' => '8', 'organization_schema' => '9'];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new EmailMatchService(
			container: $container,
			appConfig: $appConfig,
			db: $this->mailDb(),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end buildService()

	/**
	 * A database holding one message (uid imap-9) from jan@example.com to info@client.nl.
	 *
	 * @return IDBConnection
	 */
	private function mailDb(): IDBConnection {
		$results = [
			[['id' => 44, 'uid' => 'imap-9', 'subject' => 'Offer', 'sent_at' => 1767225600, 'account_id' => 3]],
			[['email' => 'Jan@Example.com', 'type' => 0], ['email' => 'info@client.nl', 'type' => 1]],
		];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use (&$results): IQueryBuilder {
				$rows = array_shift($results) ?? [];
				$result = $this->createMock(IResult::class);
				$result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
				$qb = $this->createMock(IQueryBuilder::class);
				foreach (['select', 'from', 'join', 'where', 'andWhere', 'setMaxResults'] as $method) {
					$qb->method($method)->willReturnSelf();
				}

				$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
				$qb->method('executeQuery')->willReturn($result);
				return $qb;
			}
		);
		return $db;
	}//end mailDb()
}//end class
