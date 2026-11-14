<?php

/**
 * Characterisation test for ProductVendorProviderService's register lookup.
 *
 * Pins the findAll query each lookup sends, what it answers for every row
 * shape, and the warnings for an unconfigured schema and a failing read.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/product-vendor-master/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use JsonSerializable;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\ProductVendorProviderService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Lookup queries, answers and warnings.
 */
class ProductVendorLookupCharacterisationTest extends TestCase {
	/**
	 * Warnings logged.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $warnings = [];

	/**
	 * Queries and answers for both lookups.
	 *
	 * @return void
	 */
	public function testQueriesAnswersAndWarnings(): void {
		$entity = new class implements JsonSerializable {
			/**
			 * The entity's data.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['contactsUid' => 'c-1', 'name' => 'Leverancier'];
			}
		};
		[$service, $objects] = $this->service(rows: ['k' => $entity, 'z' => ['other' => true]]);
		self::assertSame(['contactsUid' => 'c-1', 'name' => 'Leverancier'], $service->resolveSupplier(contactsUid: 'c-1'));
		self::assertSame(
			[['filters' => ['contactsUid' => 'c-1', 'register' => 'reg-1', 'schema' => 'supp-schema'], 'limit' => 1, 'offset' => 0]],
			$objects->configs
		);

		[$service, $objects] = $this->service(rows: [['productId' => 'p-1']]);
		self::assertSame(['productId' => 'p-1'], $service->getProduct(productId: 'p-1', authorisedConsumer: true));
		self::assertSame(['productId' => 'p-1', 'register' => 'reg-1', 'schema' => 'prod-schema'], $objects->configs[0]['filters']);

		[$service] = $this->service(rows: ['not a row']);
		self::assertNull($service->resolveSupplier(contactsUid: 'c-9', consumerAppSlug: 'shillinq'));
		self::assertSame([['ProductVendorProviderService: supplier not found', ['contactsUid' => 'c-9', 'consumer' => 'shillinq']]], $this->warnings);

		[$service, $objects] = $this->service(rows: [['x' => 1]], config: ['supplier_schema' => '']);
		self::assertNull($service->resolveSupplier(contactsUid: 'c-1'));
		self::assertSame([], $objects->configs);
		self::assertSame(['ProductVendorProviderService: register or schema not configured', ['schema' => 'supplier', 'registerId' => 'reg-1', 'schemaId' => '']], $this->warnings[0]);

		[$service, $objects] = $this->service(rows: null);
		self::assertNull($service->getProduct(productId: 'p-2', consumerAppSlug: 'x'));
		self::assertSame(['ProductVendorProviderService: lookup failed', ['schema' => 'product', 'idField' => 'productId', 'error' => 'down']], $this->warnings[0]);
		self::assertSame(['ProductVendorProviderService: product not found', ['productId' => 'p-2', 'consumer' => 'x']], $this->warnings[1]);
	}//end testQueriesAnswersAndWarnings()

	/**
	 * Build the service; null rows make the read throw.
	 *
	 * @param array<int|string, mixed>|null $rows   What findAll answers.
	 * @param array<string, string>         $config Config overrides.
	 *
	 * @return array{0: ProductVendorProviderService, 1: object}
	 */
	private function service(?array $rows, array $config = []): array {
		$this->warnings = [];
		$objects = new class ($rows) extends ObjectService {
			/**
			 * Queries seen.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $configs = [];

			/**
			 * Constructor.
			 *
			 * @param array<int|string, mixed>|null $rows The answer.
			 */
			public function __construct(private ?array $rows) {
			}

			/**
			 * Record and answer.
			 *
			 * @param array<string, mixed> $config        The query.
			 * @param bool                 $_rbac         RBAC flag.
			 * @param bool                 $_multitenancy Multitenancy flag.
			 *
			 * @return array<int|string, mixed>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->configs[] = $config;
				if ($this->rows === null) {
					throw new RuntimeException('down');
				}

				return $this->rows;
			}
		};
		$values = array_merge(['register' => 'reg-1', 'product_schema' => 'prod-schema', 'supplier_schema' => 'supp-schema'], $config);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $values[$key] ?? $default);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return [new ProductVendorProviderService(appConfig: $appConfig, logger: $logger, objectService: $objects), $objects];
	}//end service()
}//end class
