<?php

/**
 * The client page's cases, read from dossiq by the client's master entity.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/client-page-as-the-relation-boards/specs/client-detail/spec.md#requirement-the-client-page-lists-the-clients-cases
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\Pipelinq\Service\ClientCaseReader;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Pipelinq\Service\ClientCaseReader
 */
class ClientCaseReaderTest extends TestCase {

	/**
	 * The master entities dossiq's leaf was asked for, with the status filter.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	public array $asked = [];

	/**
	 * A reader over this client (or this refusal) and this dossiq leaf.
	 *
	 * @param array<string, mixed>|\Throwable $client The client, or what reading it throws.
	 * @param array<int, array<string, mixed>>|null $cases The leaf's cases; null without dossiq.
	 *
	 * @return ClientCaseReader The reader.
	 */
	private function reader(array|\Throwable $client, ?array $cases): ClientCaseReader {
		$objects = new class ($client) {
			/**
			 * @param array<string, mixed>|\Throwable $client The client, or the refusal.
			 */
			public function __construct(private array|\Throwable $client) {
			}

			/**
			 * @param string $id The id.
			 * @param mixed ...$rest The register, schema and scope.
			 *
			 * @return array<string, mixed>
			 */
			public function find(string $id, mixed ...$rest): array {
				if ($this->client instanceof \Throwable) {
					throw $this->client;
				}

				return $this->client;
			}
		};
		$leaf = null;
		if ($cases !== null) {
			$leaf = new class ($this, $cases) {
				/**
				 * @param ClientCaseReaderTest $test The test, recording what was asked.
				 * @param array<int, array<string, mixed>> $cases The cases.
				 */
				public function __construct(private ClientCaseReaderTest $test, private array $cases) {
				}

				/**
				 * @param string $ref The master entity.
				 * @param string $status The status filter.
				 *
				 * @return array<string, mixed>
				 */
				public function byMasterEntity(string $ref, string $status = 'all'): array {
					$this->test->asked[] = [$ref, $status];
					return ['status' => 200, 'cases' => $this->cases];
				}
			};
		}

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key): string => ['register' => '5', 'client_schema' => '12'][$key] ?? '');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		return new class ($config, $container, $this->createMock(LoggerInterface::class), $leaf) extends ClientCaseReader {
			/**
			 * @param IAppConfig $config The config.
			 * @param ContainerInterface $container The container.
			 * @param LoggerInterface $logger The logger.
			 * @param object|null $fakeLeaf Dossiq's leaf, or null without dossiq.
			 */
			public function __construct(IAppConfig $config, ContainerInterface $container, LoggerInterface $logger, private ?object $fakeLeaf) {
				parent::__construct($config, $container, $logger);
			}

			/**
			 * @return object|null
			 */
			protected function leaf(): ?object {
				return $this->fakeLeaf;
			}
		};
	}//end reader()

	/**
	 * Three cases from dossiq, the first two shown, the total kept.
	 *
	 * @return void
	 */
	public function testReadsTheCasesOfTheClientsMasterEntity(): void {
		$cases = [['id' => 'a', 'identifier' => '2026-0070'], ['id' => 'b'], ['id' => 'c']];
		$answer = $this->reader(['id' => 'client-1', 'masterEntityRef' => 'master-7'], $cases)->casesOf('client-1', 'open', 2);

		self::assertSame(['status' => 200, 'results' => [$cases[0], $cases[1]], 'total' => 3, 'available' => true], $answer);
		self::assertSame([['master-7', 'open']], $this->asked);
	}//end testReadsTheCasesOfTheClientsMasterEntity()

	/**
	 * Without a master entity, or without dossiq, the client has no cases and
	 * says whether dossiq is there.
	 *
	 * @return void
	 */
	public function testAnswersNoCasesWithoutAMasterEntityOrWithoutDossiq(): void {
		self::assertSame(
			['status' => 200, 'results' => [], 'total' => 0, 'available' => true],
			$this->reader(['id' => 'client-1'], [['id' => 'a']])->casesOf('client-1', 'all', 20)
		);
		self::assertSame(
			['status' => 200, 'results' => [], 'total' => 0, 'available' => false],
			$this->reader(['id' => 'client-1', 'masterEntityRef' => 'master-7'], null)->casesOf('client-1', 'all', 20)
		);
		self::assertSame([], $this->asked);
	}//end testAnswersNoCasesWithoutAMasterEntityOrWithoutDossiq()

	/**
	 * A client the caller may not read gives no cases: dossiq is never asked.
	 *
	 * @return void
	 */
	public function testAClientTheCallerMayNotReadIsRefused(): void {
		$answer = $this->reader(new NotAuthorizedException('no'), [['id' => 'a']])->casesOf('client-1', 'all', 20);

		self::assertSame(403, $answer['status']);
		self::assertSame([], $this->asked);
	}//end testAClientTheCallerMayNotReadIsRefused()
}//end class
