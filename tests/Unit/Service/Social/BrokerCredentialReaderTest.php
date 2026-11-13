<?php

/**
 * Unit tests for BrokerCredentialReader: only the readable fields of a
 * brokered credential leave the reader, and a failed or empty lookup is null.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Social
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Social;

use JsonSerializable;
use OCA\Pipelinq\Service\Social\BrokerCredentialReader;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for BrokerCredentialReader.
 *
 * @covers \OCA\Pipelinq\Service\Social\BrokerCredentialReader
 */
class BrokerCredentialReaderTest extends TestCase {

	/**
	 * Calls the fake object service received, as [method, arguments].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $calls = [];

	/**
	 * A reader over a container whose ObjectService answers find/findAll with the given values.
	 *
	 * @param mixed                $found  What find() returns, or a Throwable to throw.
	 * @param mixed                $all    What findAll() returns, or a Throwable to throw.
	 * @param LoggerInterface|null $logger The logger, a silent mock by default.
	 *
	 * @return BrokerCredentialReader
	 */
	private function reader(mixed $found = null, mixed $all = [], ?LoggerInterface $logger = null): BrokerCredentialReader {
		$calls   = &$this->calls;
		$service = new class ($found, $all, $calls) {
			/**
			 * @param mixed $found What find() returns or throws.
			 * @param mixed $all   What findAll() returns or throws.
			 * @param array $calls Receives every call.
			 */
			public function __construct(private mixed $found, private mixed $all, private array &$calls) {
			}

			/**
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return mixed
			 */
			public function find(string $id, string $register, string $schema): mixed {
				$this->calls[] = ['find', ['id' => $id, 'register' => $register, 'schema' => $schema]];
				if ($this->found instanceof \Throwable) {
					throw $this->found;
				}

				return $this->found;
			}

			/**
			 * @param array $config The query.
			 *
			 * @return mixed
			 */
			public function findAll(array $config): mixed {
				$this->calls[] = ['findAll', ['config' => $config]];
				if ($this->all instanceof \Throwable) {
					throw $this->all;
				}

				return $this->all;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\\OpenRegister\\Service\\ObjectService')->willReturn($service);

		return new BrokerCredentialReader($container, $logger ?? $this->createMock(LoggerInterface::class));
	}//end reader()

	/**
	 * An entity whose jsonSerialize() returns the given payload.
	 *
	 * @param array $payload The payload.
	 *
	 * @return JsonSerializable
	 */
	private function entity(array $payload): JsonSerializable {
		$entity = $this->createMock(JsonSerializable::class);
		$entity->method('jsonSerialize')->willReturn($payload);

		return $entity;
	}//end entity()

	/**
	 * read() asks the broker register and returns only the readable fields, with the @self id winning.
	 *
	 * @return void
	 */
	public function testReadReturnsOnlyReadableFields(): void {
		$reader = $this->reader(found: $this->entity(payload: [
			'id'           => 'old',
			'name'         => 'Our page',
			'provider'     => 'mastodon',
			'status'       => 'active',
			'accessToken'  => 'secret',
			'refreshToken' => 'secret-too',
			'@self'        => ['id' => 'cred-1'],
		]));

		$row = $reader->read(credentialRef: 'cred-1');

		$this->assertSame(['id' => 'cred-1', 'name' => 'Our page', 'provider' => 'mastodon', 'status' => 'active'], $row);
		$this->assertSame(
			[['find', ['id' => 'cred-1', 'register' => 'credential-broker', 'schema' => 'brokeredcredential']]],
			$this->calls
		);
	}//end testReadReturnsOnlyReadableFields()

	/**
	 * A plain array is read too, and a non-array payload reads as an empty row.
	 *
	 * @return void
	 */
	public function testReadAcceptsArraysAndRejectsScalars(): void {
		$this->assertSame(['kind' => 'oauth2', 'scopes' => ['a']], $this->reader(found: ['kind' => 'oauth2', 'scopes' => ['a'], 'secret' => 'x'])->read(credentialRef: 'c'));
		$this->assertSame([], $this->reader(found: 'not a row')->read(credentialRef: 'c'));
	}//end testReadAcceptsArraysAndRejectsScalars()

	/**
	 * A blank reference never reaches the service; a missing entity or a failure is null and is logged.
	 *
	 * @return void
	 */
	public function testReadFailuresAreNull(): void {
		$this->assertNull($this->reader(found: ['id' => 'x'])->read(credentialRef: '  '));
		$this->assertSame([], $this->calls);

		$this->assertNull($this->reader(found: null)->read(credentialRef: 'gone'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'BrokerCredentialReader.read: the credential could not be read',
			['exception' => 'register down']
		);
		$this->assertNull($this->reader(found: new RuntimeException('register down'), logger: $logger)->read(credentialRef: 'cred-1'));
	}//end testReadFailuresAreNull()

	/**
	 * status() is the row's status, or '' when the row or its status is missing.
	 *
	 * @return void
	 */
	public function testStatus(): void {
		$this->assertSame('relink_required', $this->reader(found: ['status' => 'relink_required'])->status(credentialRef: 'c'));
		$this->assertSame('', $this->reader(found: ['name' => 'n'])->status(credentialRef: 'c'));
		$this->assertSame('', $this->reader(found: null)->status(credentialRef: 'c'));
	}//end testStatus()

	/**
	 * findLatest() filters by provider in the query, keeps only the owner's rows and picks the most recent.
	 *
	 * @return void
	 */
	public function testFindLatestPicksTheOwnersMostRecentConnection(): void {
		$reader = $this->reader(all: [
			['id' => 'a', 'provider' => 'x', 'owner' => 'alice', 'lastRefreshedAt' => '2026-01-01T00:00:00Z'],
			['id' => 'b', 'provider' => 'x', 'owner' => 'bob', 'lastRefreshedAt' => '2026-12-01T00:00:00Z'],
			['id' => 'c', 'provider' => 'bluesky', 'owner' => 'alice', 'lastRefreshedAt' => '2026-12-01T00:00:00Z'],
			$this->entity(payload: ['id' => 'd', 'provider' => 'x', 'owner' => 'alice', 'expiresAt' => '2026-06-01T00:00:00Z', 'accessToken' => 's']),
			['id' => 'e', 'provider' => 'x', 'owner' => 'alice'],
			'garbage',
		]);

		$latest = $reader->findLatest(provider: 'x', ownerUid: 'alice');

		$this->assertSame(['id' => 'd', 'provider' => 'x', 'owner' => 'alice', 'expiresAt' => '2026-06-01T00:00:00Z'], $latest);
		$this->assertSame(
			[['findAll', ['config' => ['filters' => ['register' => 'credential-broker', 'schema' => 'brokeredcredential', 'provider' => 'x']]]]],
			$this->calls
		);
	}//end testFindLatestPicksTheOwnersMostRecentConnection()

	/**
	 * Of two equally recent rows the later one in the list wins.
	 *
	 * @return void
	 */
	public function testFindLatestTieGoesToTheLaterRow(): void {
		$reader = $this->reader(all: [
			['id' => 'first', 'provider' => 'x', 'owner' => 'alice'],
			['id' => 'second', 'provider' => 'x', 'owner' => 'alice'],
		]);

		$this->assertSame('second', $reader->findLatest(provider: 'x', ownerUid: 'alice')['id']);
	}//end testFindLatestTieGoesToTheLaterRow()

	/**
	 * Blank arguments, no matching row, a null list and a failure all give null.
	 *
	 * @return void
	 */
	public function testFindLatestWithoutAMatch(): void {
		$this->assertNull($this->reader(all: [['provider' => 'x', 'owner' => 'alice']])->findLatest(provider: '', ownerUid: 'alice'));
		$this->assertNull($this->reader(all: [['provider' => 'x', 'owner' => 'alice']])->findLatest(provider: 'x', ownerUid: ' '));
		$this->assertSame([], $this->calls);

		$this->assertNull($this->reader(all: [['provider' => 'x', 'owner' => 'bob']])->findLatest(provider: 'x', ownerUid: 'alice'));
		$this->assertNull($this->reader(all: null)->findLatest(provider: 'x', ownerUid: 'alice'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'BrokerCredentialReader.findLatest: the credential list could not be read',
			['provider' => 'x', 'exception' => 'boom']
		);
		$this->assertNull($this->reader(all: new RuntimeException('boom'), logger: $logger)->findLatest(provider: 'x', ownerUid: 'alice'));
	}//end testFindLatestWithoutAMatch()
}//end class
