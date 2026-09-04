<?php

/**
 * Contract tests for BlastController.
 *
 * Covers `GET /api/blasts/{id}/deliveries` and `GET /api/blasts/{id}/attribution`.
 * BlastService and AttributionService are the REAL classes; only the
 * OpenRegister ObjectService is replaced by an in-memory double, so both
 * aggregates are asserted against SEEDED rows rather than against "the status
 * was 200".
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Controller\BlastController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ArticleService;
use OCA\Pipelinq\Service\AttributionService;
use OCA\Pipelinq\Service\BlastService;
use OCA\Pipelinq\Service\SegmentService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * BlastController contract coverage.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A controller contract test
 *  necessarily wires the whole collaborator graph the endpoint touches.
 */
class BlastControllerTest extends TestCase {
	/**
	 * In-memory OpenRegister ObjectService double.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * The user session double.
	 *
	 * @var IUserSession
	 */
	private IUserSession $userSession;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->objects = $this->buildObjectStore();
	}//end setUp()

	/**
	 * Build the in-memory ObjectService double.
	 *
	 * Register/schema context is taken ONLY from `$config['filters']`, exactly
	 * as ObjectService::prepareFindAllConfig() does; the remaining filter keys
	 * are object-field equality filters; soft-deleted rows are excluded from
	 * both reads and counts; `limit`/`offset` page the result.
	 *
	 * @return object The store.
	 */
	private function buildObjectStore(): object {
		return new class extends \OCA\OpenRegister\Service\ObjectService {
			/**
			 * Rows keyed by uuid.
			 *
			 * @var array<string, array<string, mixed>>
			 */
			public array $store = [];

			/**
			 * Seed one row.
			 *
			 * @param string $uuid Row uuid.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 * @param array<string, mixed> $data Row body.
			 *
			 * @return void
			 */
			public function seed(string $uuid, string $register, string $schema, array $data): void {
				$data['id'] = $uuid;
				$data['@self'] = ['id' => $uuid, 'register' => $register, 'schema' => $schema];
				$this->store[$uuid] = $data;
			}//end seed()

			/**
			 * Read one row.
			 *
			 * Mirrors ObjectService::find() exactly — the parent declares nine
			 * parameters and an `?ObjectEntityInterface` return, and PHP checks
			 * signature compatibility at CLASS-LOAD time, so a narrower override
			 * is a fatal before test 1 rather than a test failure (ADR-084).
			 *
			 * @param int|string $id Object id.
			 * @param array<string, mixed>|null $_extend Unused.
			 * @param bool $files Unused.
			 * @param string|int|null $register Unused (single-register store).
			 * @param string|int|null $schema Unused (single-schema store).
			 * @param bool $_rbac Unused.
			 * @param bool $_multitenancy Unused.
			 * @param bool $_render Unused.
			 * @param bool $_audit Unused.
			 *
			 * @return ObjectEntityInterface|null The row, wrapped as an entity.
			 */
			public function find(
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				string|int|null $register = null,
				string|int|null $schema = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $_render = true,
				bool $_audit = true,
			): ?ObjectEntityInterface {
				$row = ($this->store[(string)$id] ?? null);
				if ($row === null || ($row['_deleted'] ?? null) !== null) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject($row);

				return $entity;
			}//end find()

			/**
			 * Match the stored rows against a query config.
			 *
			 * @param array<string, mixed> $config Query config.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			private function match(array $config): array {
				$filters = ($config['filters'] ?? []);
				$register = (string)($filters['register'] ?? '');
				$schema = (string)($filters['schema'] ?? '');
				if ($register === '' || $schema === '') {
					return [];
				}

				$reserved = ['register', 'schema', 'registers', 'schemas', 'extend'];
				$fields = [];
				foreach ($filters as $key => $value) {
					if (in_array($key, $reserved, true) === true || str_starts_with((string)$key, '_') === true) {
						continue;
					}

					$fields[$key] = $value;
				}

				$out = [];
				foreach ($this->store as $row) {
					if (($row['_deleted'] ?? null) !== null) {
						continue;
					}

					if ((string)($row['@self']['register'] ?? '') !== $register) {
						continue;
					}

					if ((string)($row['@self']['schema'] ?? '') !== $schema) {
						continue;
					}

					$matches = true;
					foreach ($fields as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							$matches = false;
							break;
						}
					}

					if ($matches === true) {
						$out[] = $row;
					}
				}

				return $out;
			}//end match()

			/**
			 * Query rows.
			 *
			 * @param array<string, mixed> $config Query config.
			 * @param bool $_rbac RBAC posture.
			 * @param bool $_multitenancy Tenancy posture.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$out = $this->match(config: $config);
				$offset = (int)($config['offset'] ?? 0);
				$limit = ($config['limit'] ?? null);
				if ($limit === null) {
					return array_slice($out, $offset);
				}

				return array_slice($out, $offset, (int)$limit);
			}//end findAll()

			/**
			 * Count rows (unpaged, per the ObjectService contract).
			 *
			 * @param array<string, mixed> $config Query config.
			 *
			 * @return int
			 */
			public function count(array $config = []): int {
				return count($this->match(config: $config));
			}//end count()
		};
	}//end buildObjectStore()

	/**
	 * Build the controller under test.
	 *
	 * @return BlastController
	 */
	private function buildController(): BlastController {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->objects;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				$map = [
					'register' => 'pipelinq',
					'blast_schema' => 'blast',
					'blastDelivery_schema' => 'blastDelivery',
					'attributionLink_schema' => 'attributionLink',
				];

				return ($map[$key] ?? $default);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);

		return new BlastController(
			request: $this->createMock(IRequest::class),
			blastService: new BlastService(
				appConfig: $appConfig,
				segmentService: $this->createMock(SegmentService::class),
				articleService: $this->createMock(ArticleService::class),
				logger: $logger,
				container: $container,
			),
			attributionService: new AttributionService(
				appConfig: $appConfig,
				logger: $logger,
				container: $container,
			),
			userSession: $this->userSession,
			policy: $this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => true, 'mayAccess' => true]),
		);
	}//end buildController()

	/**
	 * Sign a user in.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('operator-1');
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Seed a Blast plus three of its deliveries and one belonging to a
	 * different Blast (which must never leak into the scoped list).
	 *
	 * @return void
	 */
	private function seedDeliveries(): void {
		$this->objects->seed('blast-1', 'pipelinq', 'blast', ['name' => 'June newsletter', 'status' => 'sent']);
		$this->objects->seed('blast-2', 'pipelinq', 'blast', ['name' => 'Other', 'status' => 'draft']);

		foreach (['d-1' => 'delivered', 'd-2' => 'opened', 'd-3' => 'bounced'] as $uuid => $status) {
			$this->objects->seed($uuid,
				'pipelinq',
				'blastDelivery',
				['blastId' => 'blast-1', 'status' => $status, 'contactId' => 'c-' . $uuid]
			);
		}

		$this->objects->seed(
			'd-other',
			'pipelinq',
			'blastDelivery',
			['blastId' => 'blast-2', 'status' => 'delivered', 'contactId' => 'c-other']
		);
	}//end seedDeliveries()

	/**
	 * Unauthenticated delivery listing is refused with 401.
	 *
	 * @return void
	 */
	public function testDeliveriesRequiresAuthentication(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->buildController()->deliveries(id: 'blast-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}//end testDeliveriesRequiresAuthentication()

	/**
	 * GET /api/blasts/{id}/deliveries returns the SEEDED rows for that Blast
	 * only, inside the documented `{data, pagination}` envelope.
	 *
	 * @return void
	 */
	public function testDeliveriesReturnsTheSeededRowsScopedToTheBlast(): void {
		$this->signIn();
		$this->seedDeliveries();

		$response = $this->buildController()->deliveries(id: 'blast-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();

		$this->assertSame(['data', 'pagination'], array_keys($data));
		$this->assertCount(3, $data['data']);
		$this->assertSame(
			['delivered', 'opened', 'bounced'],
			array_column($data['data'], 'status')
		);
		foreach ($data['data'] as $row) {
			$this->assertSame('blast-1', $row['blastId']);
		}

		$this->assertSame(
			['page' => 1, 'limit' => 20, 'total' => 3, 'pages' => 1],
			$data['pagination']
		);
	}//end testDeliveriesReturnsTheSeededRowsScopedToTheBlast()

	/**
	 * Paging is applied server-side: page 2 with a page size of 2 returns the
	 * remaining row while `total` still reports the full count.
	 *
	 * @return void
	 */
	public function testDeliveriesPagesTheSeededRows(): void {
		$this->signIn();
		$this->seedDeliveries();

		$response = $this->buildController()->deliveries(id: 'blast-1', page: 2, limit: 2);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();

		$this->assertCount(1, $data['data']);
		$this->assertSame('bounced', $data['data'][0]['status']);
		$this->assertSame(
			['page' => 2, 'limit' => 2, 'total' => 3, 'pages' => 2],
			$data['pagination']
		);
	}//end testDeliveriesPagesTheSeededRows()

	/**
	 * A soft-deleted delivery must not be listed nor counted.
	 *
	 * @return void
	 */
	public function testDeliveriesExcludesSoftDeletedRowsFromDataAndTotal(): void {
		$this->signIn();
		$this->seedDeliveries();
		$this->objects->store['d-2']['_deleted'] = ['deleted' => '2026-06-01T00:00:00+00:00'];

		$response = $this->buildController()->deliveries(id: 'blast-1');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(2, $data['data']);
		$this->assertSame(2, $data['pagination']['total']);
	}//end testDeliveriesExcludesSoftDeletedRowsFromDataAndTotal()

	/**
	 * An empty Blast id yields the documented empty envelope rather than the
	 * whole delivery table.
	 *
	 * @return void
	 */
	public function testDeliveriesWithAnEmptyIdReturnsAnEmptyEnvelope(): void {
		$this->signIn();
		$this->seedDeliveries();

		$response = $this->buildController()->deliveries(id: '');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $data['data']);
		$this->assertSame(0, $data['pagination']['total']);
	}//end testDeliveriesWithAnEmptyIdReturnsAnEmptyEnvelope()

	/**
	 * Unauthenticated attribution read is refused with 401.
	 *
	 * @return void
	 */
	public function testAttributionRequiresAuthentication(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->buildController()->attribution(id: 'blast-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}//end testAttributionRequiresAuthentication()

	/**
	 * An unknown Blast yields a generic 404.
	 *
	 * @return void
	 */
	public function testAttributionOnAnUnknownBlastReturnsNotFound(): void {
		$this->signIn();

		$response = $this->buildController()->attribution(id: 'ghost');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Not found'], $response->getData());
	}//end testAttributionOnAnUnknownBlastReturnsNotFound()

	/**
	 * GET /api/blasts/{id}/attribution sums the SEEDED attribution links,
	 * de-duplicates the deal ids and scopes to the requested Blast.
	 *
	 * @return void
	 */
	public function testAttributionSumsTheSeededLinks(): void {
		$this->signIn();
		$this->seedDeliveries();

		$this->objects->seed(
			'al-1',
			'pipelinq',
			'attributionLink',
			['blastId' => 'blast-1', 'dealId' => 'deal-1', 'attributedValue' => 1500.5]
		);
		// Same deal seen twice — dealCount must not double-count it.
		$this->objects->seed(
			'al-2',
			'pipelinq',
			'attributionLink',
			['blastId' => 'blast-1', 'dealId' => 'deal-1', 'attributedValue' => 499.5]
		);
		$this->objects->seed(
			'al-3',
			'pipelinq',
			'attributionLink',
			['blastId' => 'blast-1', 'dealId' => 'deal-2', 'attributedValue' => 1000]
		);
		// Another Blast's link must not be counted.
		$this->objects->seed(
			'al-other',
			'pipelinq',
			'attributionLink',
			['blastId' => 'blast-2', 'dealId' => 'deal-9', 'attributedValue' => 99999]
		);

		$response = $this->buildController()->attribution(id: 'blast-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'blastId' => 'blast-1',
				'dealCount' => 2,
				'attributedValue' => 3000.0,
				'currency' => 'EUR',
			],
			$response->getData()
		);
	}//end testAttributionSumsTheSeededLinks()

	/**
	 * A Blast with no attribution links returns an explicit zero summary — the
	 * documented shape, not an empty body.
	 *
	 * @return void
	 */
	public function testAttributionReturnsAZeroSummaryWithoutLinks(): void {
		$this->signIn();
		$this->seedDeliveries();

		$response = $this->buildController()->attribution(id: 'blast-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'blastId' => 'blast-1',
				'dealCount' => 0,
				'attributedValue' => 0.0,
				'currency' => 'EUR',
			],
			$response->getData()
		);
	}//end testAttributionReturnsAZeroSummaryWithoutLinks()

	/**
	 * A soft-deleted attribution link must not be counted.
	 *
	 * @return void
	 */
	public function testAttributionExcludesSoftDeletedLinks(): void {
		$this->signIn();
		$this->seedDeliveries();
		$this->objects->seed(
			'al-1',
			'pipelinq',
			'attributionLink',
			['blastId' => 'blast-1', 'dealId' => 'deal-1', 'attributedValue' => 100.0]
		);
		$this->objects->seed(
			'al-deleted',
			'pipelinq',
			'attributionLink',
			[
				'blastId' => 'blast-1',
				'dealId' => 'deal-2',
				'attributedValue' => 900.0,
				'_deleted' => ['deleted' => '2026-06-01T00:00:00+00:00'],
			]
		);

		$response = $this->buildController()->attribution(id: 'blast-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['dealCount']);
		$this->assertSame(100.0, $response->getData()['attributedValue']);
	}//end testAttributionExcludesSoftDeletedLinks()
}//end class
