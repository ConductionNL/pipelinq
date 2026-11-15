<?php

/**
 * Tests for BrokerCredentialReader.
 *
 * The reader hands Pipelinq the metadata of a brokered credential and nothing
 * else. The tests pin the allow-list (a secret-shaped field never comes back),
 * the "no" answers (blank input, a failing or absent register, a missing row),
 * and the owner/provider re-check that keeps another user's credential from
 * being picked by `findLatest()` even when the register returns it.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Social
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Social;

use OCA\Pipelinq\Service\Social\BrokerCredentialReader;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Pipelinq\Service\Social\BrokerCredentialReader
 */
class BrokerCredentialReaderTest extends TestCase {

	/**
	 * The mocked container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The reader under test.
	 *
	 * @var BrokerCredentialReader
	 */
	private BrokerCredentialReader $reader;

	/**
	 * Set up the reader with mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->reader = new BrokerCredentialReader(container: $this->container, logger: $this->logger);
	}

	/**
	 * A stand-in for OpenRegister's ObjectService that records its calls.
	 *
	 * @param mixed $found What `find()` answers.
	 * @param array<int, mixed>|null $all What `findAll()` answers.
	 *
	 * @return object The stand-in.
	 */
	private function objectService(mixed $found = null, ?array $all = []): object {
		return new class($found, $all) {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * @param mixed $found The find answer.
			 * @param array<int, mixed>|null $all The findAll answer.
			 */
			public function __construct(private mixed $found, private ?array $all) {
			}

			/**
			 * @param string $id The id.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @return mixed The scripted answer.
			 */
			public function find(string $id, string $register, string $schema): mixed {
				$this->calls[] = ['find', $id, $register, $schema];
				return $this->found;
			}

			/**
			 * @param array<string, mixed> $config The config.
			 * @return array<int, mixed>|null The scripted answer.
			 */
			public function findAll(array $config): ?array {
				$this->calls[] = ['findAll', $config];
				return $this->all;
			}
		};
	}

	/**
	 * Point the container at one ObjectService stand-in.
	 *
	 * @param object $service The stand-in.
	 *
	 * @return void
	 */
	private function serves(object $service): void {
		$this->container->method('get')
			->with('OCA\\OpenRegister\\Service\\ObjectService')
			->willReturn($service);
	}

	/**
	 * A blank reference never reaches the register.
	 *
	 * @return void
	 */
	public function testABlankReferenceReadsNothing(): void {
		$this->container->expects($this->never())->method('get');

		$this->assertNull($this->reader->read(credentialRef: '   '));
		$this->assertSame('', $this->reader->status(credentialRef: ''));
	}

	/**
	 * The read is addressed at the broker's own register and schema, and only
	 * the allow-listed properties come back.
	 *
	 * @return void
	 */
	public function testReadKeepsOnlyTheReadableProperties(): void {
		$service = $this->objectService(found: [
			'id' => 'cred-1',
			'provider' => 'mastodon',
			'status' => 'active',
			'owner' => 'ruben',
			'accessToken' => 'must-not-leak',
			'refreshToken' => 'must-not-leak',
			'clientSecret' => 'must-not-leak',
		]);
		$this->serves($service);

		$row = $this->reader->read(credentialRef: 'cred-1');

		$this->assertSame(
			['id' => 'cred-1', 'provider' => 'mastodon', 'status' => 'active', 'owner' => 'ruben'],
			$row
		);
		$this->assertSame(
			[['find', 'cred-1', BrokerCredentialReader::REGISTER, BrokerCredentialReader::SCHEMA]],
			$service->calls
		);
	}

	/**
	 * An entity is serialised first, and the `@self` id wins over a top-level one.
	 *
	 * @return void
	 */
	public function testReadSerialisesAnEntityAndTakesTheSelfId(): void {
		$entity = new class implements \JsonSerializable {
			/**
			 * @return array<string, mixed> The payload.
			 */
			public function jsonSerialize(): array {
				return [
					'id' => 'top-level',
					'status' => 'expired',
					'@self' => ['id' => 'self-id'],
				];
			}
		};
		$this->serves($this->objectService(found: $entity));

		$this->assertSame(['id' => 'self-id', 'status' => 'expired'], $this->reader->read(credentialRef: 'x'));
		$this->assertSame('expired', $this->reader->status(credentialRef: 'x'));
	}

	/**
	 * A payload that is not an array reads as an empty row, not as an error.
	 *
	 * @return void
	 */
	public function testANonArrayPayloadReadsAsEmpty(): void {
		$this->serves($this->objectService(found: 'not-a-row'));

		$this->assertSame([], $this->reader->read(credentialRef: 'x'));
		$this->assertSame('', $this->reader->status(credentialRef: 'x'));
	}

	/**
	 * A missing row is null and its status is empty.
	 *
	 * @return void
	 */
	public function testAMissingRowIsNull(): void {
		$this->serves($this->objectService(found: null));

		$this->assertNull($this->reader->read(credentialRef: 'gone'));
		$this->assertSame('', $this->reader->status(credentialRef: 'gone'));
	}

	/**
	 * A register that throws (absent, or the caller may not see the row) is
	 * logged and answered with null.
	 *
	 * @return void
	 */
	public function testAFailingRegisterIsLoggedAndReadsAsNull(): void {
		$this->container->method('get')->willThrowException(new RuntimeException('no openregister'));
		$this->logger->expects($this->once())
			->method('info')
			->with(
				'BrokerCredentialReader.read: the credential could not be read',
				['exception' => 'no openregister']
			);

		$this->assertNull($this->reader->read(credentialRef: 'cred-1'));
	}

	/**
	 * `findLatest()` refuses a blank provider or owner without a read.
	 *
	 * @return void
	 */
	public function testFindLatestNeedsAProviderAndAnOwner(): void {
		$this->container->expects($this->never())->method('get');

		$this->assertNull($this->reader->findLatest(provider: '', ownerUid: 'ruben'));
		$this->assertNull($this->reader->findLatest(provider: 'x', ownerUid: ' '));
	}

	/**
	 * The newest credential of this provider AND this owner wins; another
	 * owner's newer row and another provider's row are re-checked away.
	 *
	 * @return void
	 */
	public function testFindLatestPicksTheNewestOwnRowOfThatProvider(): void {
		$service = $this->objectService(all: [
			['id' => 'old', 'provider' => 'mastodon', 'owner' => 'ruben', 'lastRefreshedAt' => '2026-01-01T00:00:00Z'],
			['id' => 'other-owner', 'provider' => 'mastodon', 'owner' => 'alice', 'lastRefreshedAt' => '2026-09-01T00:00:00Z'],
			['id' => 'other-provider', 'provider' => 'x', 'owner' => 'ruben', 'lastRefreshedAt' => '2026-09-01T00:00:00Z'],
			['id' => 'new', 'provider' => 'mastodon', 'owner' => 'ruben', 'expiresAt' => '2026-06-01T00:00:00Z'],
			['id' => 'undated', 'provider' => 'mastodon', 'owner' => 'ruben'],
			'not-a-row',
		]);
		$this->serves($service);

		$best = $this->reader->findLatest(provider: 'mastodon', ownerUid: 'ruben');

		$this->assertSame('new', $best['id']);
		$this->assertSame(
			[[
				'findAll',
				[
					'filters' => [
						'register' => BrokerCredentialReader::REGISTER,
						'schema' => BrokerCredentialReader::SCHEMA,
						'provider' => 'mastodon',
					],
				],
			]],
			$service->calls
		);
	}

	/**
	 * Equal timestamps go to the later row, so a re-connect replaces the old one.
	 *
	 * @return void
	 */
	public function testFindLatestPrefersTheLaterRowOnATie(): void {
		$this->serves($this->objectService(all: [
			['id' => 'first', 'provider' => 'bluesky', 'owner' => 'ruben', 'expiresAt' => '2026-05-01'],
			['id' => 'second', 'provider' => 'bluesky', 'owner' => 'ruben', 'expiresAt' => '2026-05-01'],
		]));

		$this->assertSame('second', $this->reader->findLatest(provider: 'bluesky', ownerUid: 'ruben')['id']);
	}

	/**
	 * No matching row, or a null list, is null.
	 *
	 * @return void
	 */
	public function testFindLatestWithoutAMatchIsNull(): void {
		$this->serves($this->objectService(all: null));

		$this->assertNull($this->reader->findLatest(provider: 'mastodon', ownerUid: 'ruben'));
	}

	/**
	 * A failing list is logged with the provider and answered with null.
	 *
	 * @return void
	 */
	public function testFindLatestLogsAFailingList(): void {
		$this->container->method('get')->willThrowException(new RuntimeException('boom'));
		$this->logger->expects($this->once())
			->method('info')
			->with(
				'BrokerCredentialReader.findLatest: the credential list could not be read',
				['provider' => 'linkedin', 'exception' => 'boom']
			);

		$this->assertNull($this->reader->findLatest(provider: 'linkedin', ownerUid: 'ruben'));
	}
}
