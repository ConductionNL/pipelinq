<?php

/**
 * Characterisation tests for the matching and linking branches of EmailMatchService.
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

use ArrayObject;
use OCA\Pipelinq\Service\EmailMatchService;
use OCA\Pipelinq\Service\MailMessageKey;
use OCA\Pipelinq\Service\MailMessageReader;
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
 * Every early exit and filter of matchAndLinkMessage() and the lookups behind it.
 */
class EmailMatchServiceBranchTest extends TestCase {
	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var ArrayObject<int, string>
	 */
	private ArrayObject $log;

	/**
	 * Set up the shared log.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->log = new ArrayObject();
	}//end setUp()

	/**
	 * A message that is not found links nothing and asks nothing else.
	 *
	 * @return void
	 */
	public function testMissingMessageLinksNothing(): void {
		$service = $this->service(db: $this->db(results: [[]]));
		$this->assertSame(0, $service->matchAndLinkMessage(message: new MailMessageKey(mailAccountId: 3, mailMessageId: 44), userId: 'alice'));
		$this->assertSame(['query'], $this->log->getArrayCopy());

		$this->log = new ArrayObject();
		$service = $this->service(db: $this->db(results: [], throws: true));
		$this->assertSame(0, $service->matchAndLinkMessage(message: new MailMessageKey(mailAccountId: 3, mailMessageId: 44), userId: 'alice'));
		$this->assertSame([], $this->log->getArrayCopy());
	}//end testMissingMessageLinksNothing()

	/**
	 * A message without addresses links nothing and does not read the settings.
	 *
	 * @return void
	 */
	public function testMessageWithoutAddressesLinksNothing(): void {
		$service = $this->service(db: $this->db(results: [[$this->row()], [['email' => ' ', 'type' => 1]]]));
		$this->assertSame(0, $service->matchAndLinkMessage(message: new MailMessageKey(mailAccountId: 3, mailMessageId: 44), userId: 'alice'));
		$this->assertSame(['query', 'query'], $this->log->getArrayCopy());
	}//end testMessageWithoutAddressesLinksNothing()

	/**
	 * Excluded addresses and public domains are skipped, duplicate entities
	 * collapse, and the lookups run per address in order.
	 *
	 * @return void
	 */
	public function testMatchingSkipsExcludedAndPublicAddresses(): void {
		$recipients = [
			['email' => 'me@corp.nl', 'type' => 1],
			['email' => 'friend@gmail.com', 'type' => 1],
			['email' => 'sales@acme.nl', 'type' => 1],
			['email' => 'SALES@acme.nl', 'type' => 2],
			['email' => 'broken', 'type' => 1],
		];
		$service = $this->service(
			db: $this->db(results: [[$this->row()], $recipients]),
			settings: ['excludedAddresses' => ['me@corp.nl']],
		);

		$this->assertSame(2, $service->matchAndLinkMessage(message: new MailMessageKey(mailAccountId: 3, mailMessageId: 44), userId: 'alice'));
		$this->assertSame(
			[
				'query',
				'query',
				'config:email_match_settings.alice',
				'config:register',
				'config:contact_schema',
				'findAll:7:email=friend@gmail.com',
				'config:register',
				'config:client_schema',
				'findAll:8:email=friend@gmail.com',
				'config:register',
				'config:contact_schema',
				'findAll:7:email=sales@acme.nl',
				'config:register',
				'config:client_schema',
				'findAll:8:email=sales@acme.nl',
				'config:register',
				'config:client_schema',
				'findAll:8:type=organization',
				'config:register',
				'config:contact_schema',
				'findAll:7:email=broken',
				'config:register',
				'config:client_schema',
				'findAll:8:email=broken',
				'config:register',
				'config:contact_schema',
				'link:c-sales:7',
				'config:client_schema',
				'link:k-acme:8',
			],
			$this->log->getArrayCopy()
		);
	}//end testMatchingSkipsExcludedAndPublicAddresses()

	/**
	 * Without the email leaf, or without a register, nothing is linked.
	 *
	 * @return void
	 */
	public function testNoLeafOrNoRegisterLinksNothing(): void {
		$recipients = [['email' => 'sales@acme.nl', 'type' => 0]];
		$service = $this->service(db: $this->db(results: [[$this->row()], $recipients]), leaf: null);
		$this->assertSame(0, $service->matchAndLinkMessage(message: new MailMessageKey(mailAccountId: 3, mailMessageId: 44), userId: 'alice'));
		$this->assertNotContains('link:c-sales:7', $this->log->getArrayCopy());

		$this->log = new ArrayObject();
		$config = ['register' => '', 'contact_schema' => '7', 'client_schema' => '8'];
		$service = $this->service(db: $this->db(results: [[$this->row()], $recipients]), config: $config);
		$this->assertSame(0, $service->matchAndLinkMessage(message: new MailMessageKey(mailAccountId: 3, mailMessageId: 44), userId: 'alice'));
		$this->assertSame([], array_values(array_filter($this->log->getArrayCopy(), static fn (string $entry): bool => str_starts_with($entry, 'link'))));
	}//end testNoLeafOrNoRegisterLinksNothing()

	/**
	 * An entity whose schema is not configured is skipped; one already
	 * linked for this account and message is linked again but not counted.
	 *
	 * @return void
	 */
	public function testExistingLinksAreNotCounted(): void {
		$recipients = [['email' => 'sales@acme.nl', 'type' => 0]];
		$leaf = new class($this->log) {
			public function __construct(
				private ArrayObject $log,
			) {
			}

			public function getLinkedEmails(string $uuid, ?string $cursor, int $limit): mixed {
				$this->log[] = 'linked:' . $uuid . ':' . $limit;
				return match ($uuid) {
					'c-sales' => ['items' => ['skip', ['mailAccountId' => 3, 'mailMessageId' => 45, 'id' => 1], ['mailAccountId' => '3', 'mailMessageId' => '44', 'id' => '9']]],
					default => throw new RuntimeException('no page'),
				};
			}

			public function linkEmail(...$args): void {
				$this->log[] = 'link:' . $args['target']->objectUuid;
			}
		};
		$service = $this->service(db: $this->db(results: [[$this->row()], $recipients]), leaf: $leaf);
		$this->assertSame(1, $service->matchAndLinkMessage(message: new MailMessageKey(mailAccountId: 3, mailMessageId: 44), userId: 'alice'));
		$links = array_values(array_filter($this->log->getArrayCopy(), static fn (string $entry): bool => str_starts_with($entry, 'link')));
		$this->assertSame(['linked:c-sales:100', 'link:c-sales', 'linked:k-acme:100', 'link:k-acme'], $links);

		$this->log = new ArrayObject();
		$config = ['register' => '5', 'contact_schema' => '7', 'client_schema' => ''];
		$service = $this->service(db: $this->db(results: [[$this->row()], $recipients]), config: $config);
		$this->assertSame(1, $service->matchAndLinkMessage(message: new MailMessageKey(mailAccountId: 3, mailMessageId: 44), userId: 'alice'));
		$this->assertSame(['link:c-sales:7'], array_values(array_filter($this->log->getArrayCopy(), static fn (string $entry): bool => str_starts_with($entry, 'link'))));
	}//end testExistingLinksAreNotCounted()

	/**
	 * Domain matching: the first organization whose email ends in the
	 * domain and has an id wins; a failed lookup logs and matches nothing.
	 *
	 * @return void
	 */
	public function testDomainMatchingPicksTheFirstOrganizationWithAnId(): void {
		$rows = [
			'not a row',
			['email' => ''],
			['@self' => ['id' => 'k-other'], 'email' => 'x@other.nl'],
			['email' => 'info@ACME.nl'],
			['@self' => ['id' => 'k-2'], 'email' => 'Info@Acme.NL'],
			['@self' => ['id' => 'k-3'], 'email' => 'b@acme.nl'],
		];
		$service = $this->service(db: $this->db(results: []), organizations: $rows);
		$this->assertSame(['entityType' => 'client', 'entityId' => 'k-2'], $service->matchDomainToOrganization(domain: ' ACME.nl '));
		$this->assertNull($service->matchDomainToOrganization(domain: ''));
		$this->assertNull($service->matchDomainToOrganization(domain: 'none.nl'));

		$service = $this->service(db: $this->db(results: []), organizations: 'not rows');
		$this->assertNull($service->matchDomainToOrganization(domain: 'acme.nl'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Pipelinq: matchDomainToOrganization lookup failed', ['domain' => 'acme.nl']);
		$service = $this->service(db: $this->db(results: []), organizations: null, logger: $logger);
		$this->assertNull($service->matchDomainToOrganization(domain: 'acme.nl'));
	}//end testDomainMatchingPicksTheFirstOrganizationWithAnId()

	/**
	 * Address matching keeps only rows whose email equals the address, and
	 * a failed lookup logs its schema key.
	 *
	 * @return void
	 */
	public function testAddressMatchingKeepsExactEmailsOnly(): void {
		$service = $this->service(db: $this->db(results: []));
		$this->assertSame(
			[['entityType' => 'contact', 'entityId' => 'c-sales'], ['entityType' => 'client', 'entityId' => 'k-acme']],
			$service->matchEmailToEntities(address: 'sales@acme.nl')
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning')->with('Pipelinq: findObjectsByEmail failed', $this->anything());
		$service = $this->service(db: $this->db(results: []), organizations: null, logger: $logger, failAddressLookups: true);
		$this->assertSame([], $service->matchEmailToEntities(address: 'sales@acme.nl'));
	}//end testAddressMatchingKeepsExactEmailsOnly()

	/**
	 * The message metadata: uid, subject, the type-0 sender, the other
	 * addresses deduplicated and lowercased, and the send date; a failing
	 * recipient query leaves sender and recipients empty.
	 *
	 * @return void
	 */
	public function testMessageMetadataShape(): void {
				$recipients = [
			['email' => ' A@x.nl ', 'type' => 1],
			['email' => 'b@x.nl', 'type' => 0],
			['email' => 'a@x.nl', 'type' => 2],
			['email' => '', 'type' => 1],
			['email' => 'c@x.nl'],
		];
		$reader = new MailMessageReader($this->db(results: [[$this->row()], $recipients]), $this->createMock(LoggerInterface::class));
		$this->assertSame(
			['uid' => 'imap-9', 'subject' => 'Offer', 'sender' => 'b@x.nl', 'recipients' => ['a@x.nl', 'c@x.nl'], 'date' => date('c', 1767225600)],
			$reader->fetchMeta(new MailMessageKey(mailAccountId: 3, mailMessageId: 44))
		);

		$reader = new MailMessageReader($this->db(results: [[['id' => 44]]], throwsAfter: 1), $this->createMock(LoggerInterface::class));
		$this->assertSame(
			['uid' => '', 'subject' => null, 'sender' => null, 'recipients' => [], 'date' => null],
			$reader->fetchMeta(new MailMessageKey(mailAccountId: 3, mailMessageId: 44))
		);
	}//end testMessageMetadataShape()

	/**
	 * The message row the Mail database returns.
	 *
	 * @return array<string, mixed>
	 */
	private function row(): array {
		return ['id' => 44, 'uid' => 'imap-9', 'subject' => 'Offer', 'sent_at' => 1767225600, 'account_id' => 3];
	}//end row()

	/**
	 * Build the service over the doubles.
	 *
	 * @param IDBConnection              $db                 The Mail database double.
	 * @param array<string, mixed>       $settings           The user's matching settings.
	 * @param object|null|false          $leaf               The email leaf, null for none, false for the default.
	 * @param array<string, string>|null $config             The app config, null for the default.
	 * @param mixed                      $organizations      The organization rows, null to throw.
	 * @param LoggerInterface|null       $logger             The logger, null for a mock.
	 * @param bool                       $failAddressLookups Whether address lookups throw.
	 *
	 * @return EmailMatchService
	 */
	private function service(
		IDBConnection $db,
		array $settings = [],
		object|null|false $leaf = false,
		?array $config = null,
		mixed $organizations = [['@self' => ['id' => 'k-acme'], 'email' => 'info@acme.nl']],
		?LoggerInterface $logger = null,
		bool $failAddressLookups = false,
	): EmailMatchService {
		$log = $this->log;
		$objectService = new class($log, $organizations, $failAddressLookups) {
			public function __construct(
				private ArrayObject $log,
				private mixed $organizations,
				private bool $fail,
			) {
			}

			public function findAll(array $config): mixed {
				$filters = $config['filters'];
				$schema = $filters['schema'];
				if (isset($filters['type']) === true) {
					$this->log[] = 'findAll:' . $schema . ':type=' . $filters['type'];
					if ($this->organizations === null) {
						throw new RuntimeException('down');
					}

					return $this->organizations;
				}

				$this->log[] = 'findAll:' . $schema . ':email=' . $filters['email'];
				if ($this->fail === true) {
					throw new RuntimeException('down');
				}

				$rows = [
					'7' => [['@self' => ['id' => 'c-sales'], 'email' => 'Sales@Acme.nl'], ['@self' => ['id' => 'c-x'], 'email' => 'sales@acme.nl.evil']],
					'8' => [['@self' => ['id' => 'k-acme'], 'email' => 'sales@acme.nl'], ['email' => 'sales@acme.nl']],
				];
				if ($filters['email'] !== 'sales@acme.nl') {
					return [];
				}

				return $rows[$schema] ?? [];
			}
		};
		if ($leaf === false) {
			$leaf = new class($log) {
				public function __construct(
					private ArrayObject $log,
				) {
				}

				public function linkEmail(...$args): void {
					$this->log[] = 'link:' . $args['target']->objectUuid . ':' . $args['target']->schemaId();
				}
			};
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $name) use ($objectService, $leaf) {
				if ($name === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				if ($name === 'OCA\\OpenRegister\\Service\\EmailLinkService' && $leaf !== null) {
					return $leaf;
				}

				throw new RuntimeException('unknown service: ' . $name);
			}
		);

		$config ??= ['register' => '5', 'contact_schema' => '7', 'client_schema' => '8'];
		$config['email_match_settings.alice'] = (string)json_encode($settings);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config, $log): string {
				$log[] = 'config:' . $key;
				return ($config[$key] ?? $default);
			}
		);

		return new EmailMatchService(
			container: $container,
			appConfig: $appConfig,
			db: $db,
			logger: $logger ?? $this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * A Mail database double answering each query with the next result set.
	 *
	 * @param array<int, array<int, array<string, mixed>>> $results     The result sets, in query order.
	 * @param bool                                         $throws      Whether the first query throws.
	 * @param int|null                                     $throwsAfter Throw on every query after this many.
	 *
	 * @return IDBConnection
	 */
	private function db(array $results, bool $throws = false, ?int $throwsAfter = null): IDBConnection {
		$log = $this->log;
		$count = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use (&$results, &$count, $throws, $throwsAfter, $log): IQueryBuilder {
				if ($throws === true || ($throwsAfter !== null && $count >= $throwsAfter)) {
					throw new RuntimeException('db down');
				}

				$count++;
				$log[] = 'query';
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
	}//end db()
}//end class
