<?php

/**
 * Golden test for the product and vendor master-data ingest.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/product-vendor-master/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Repair\IngestProductVendorMaster;
use OCA\Pipelinq\Service\ContactVcardService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Every ingest path: what is looked up, what is saved (in the argument order
 * the step passes), what is logged and which FK maps come back. Compared with
 * IngestProductVendorMasterGolden.json; set PVM_GOLDEN_RECORD=1 to rewrite it,
 * then run once more without it.
 *
 * @covers \OCA\Pipelinq\Repair\IngestProductVendorMaster
 * @covers \OCA\Pipelinq\Repair\Support\SourceRecordLink
 * @uses   \OCA\Pipelinq\AppInfo\OpenRegisterAutoloader
 *
 * @spec openspec/specs/product-vendor-master/spec.md
 */
class IngestProductVendorMasterGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/IngestProductVendorMasterGolden.json';

	/**
	 * What the current scenario did, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $trace = [];

	/**
	 * Run every scenario and compare it with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => $scenario) {
			$actual[$name] = $this->runScenario(scenario: $scenario);
		}

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)) . "\n";
		if (getenv('PVM_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, $json);
			$this->markTestSkipped(message: 'recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$actual = json_decode($json, true);
		foreach ($expected as $name => $want) {
			$this->assertSame(expected: $want, actual: ($actual[$name] ?? null), message: $name);
		}

		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
	}//end testGolden()

	/**
	 * The scenarios: an export, the config and how the world answers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$product = ['id' => 'sp-1', 'ref' => 'P-1', 'sku' => 'SKU-1', 'barcode' => '871', 'name' => 'Widget', 'leadTimeDays' => 3];
		$vendor = [
			'id' => 'sv-1', 'ref' => 'V-1', 'name' => 'Acme', 'kvkNumber' => '123', 'email' => 'a@acme.test', 'iban' => 'NL01', 'paymentTermDays' => 30,
		];
		$acme = ['123' => [['UID' => 'uid-acme']]];
		$found = ['id' => 'obj-p', 'productId' => 'prod-9', 'sku' => 'SKU-1'];
		return [
			'new product'                         => ['export' => ['products' => [$product]]],
			'new product keeps its productId'     => ['export' => ['products' => [$product]], 'createdProductId' => 'same'],
			'new product without object id'       => ['export' => ['products' => [$product]], 'createdNoId' => true],
			'existing product by sku'             => ['export' => ['products' => [$product]], 'found' => ['sku' => $found]],
			'existing product by barcode'         => ['export' => ['products' => [$product + ['sku' => '']]], 'found' => ['barcode' => ['id' => 'obj-b']]],
			'barcode after a sku miss'            => [
				'export' => ['products' => [$product]],
				'found'  => ['barcode' => ['id' => 'obj-b2', 'productId' => 'prod-b2']],
			],
			'existing product as an entity'       => [
				'export' => ['products' => [$product]],
				'found'  => ['sku' => new ArrayRow(row: $found + ['leadTimeDays' => null])],
			],
			'existing product as a plain object'  => [
				'export' => ['products' => [$product]],
				'found'  => ['sku' => (object)['id' => 'obj-o', 'name' => 'Plain']],
			],
			'existing product without ids'        => ['export' => ['products' => [$product]], 'found' => ['sku' => ['sku' => 'SKU-1', 'leadTimeDays' => 9]]],
			'product with an integer id'          => ['export' => ['products' => [['id' => 42, 'sku' => 'S']]]],
			'product with a null id'              => ['export' => ['products' => [['id' => null, 'ref' => 'R', 'sku' => 'S']]]],
			'product without id or ref'           => ['export' => ['products' => [['sku' => 'S']]]],
			'product, no source record schema'    => ['export' => ['products' => [$product]], 'config' => ['sourceRecord_schema' => '']],
			'product, source record save throws'  => ['export' => ['products' => [$product]], 'throwOn' => 'schema-src'],
			'product, product save throws'        => ['export' => ['products' => [$product]], 'throwOn' => 'schema-product'],
			'product, not configured'             => ['export' => ['products' => [$product]], 'config' => ['product_schema' => '']],
			'new vendor, contact found'           => ['export' => ['vendors' => [$vendor]], 'contacts' => ['123' => [['UID' => 'uid-acme']]]],
			'new vendor, contact by email'        => ['export' => ['vendors' => [$vendor]], 'contacts' => ['a@acme.test' => [['UID' => 'uid-mail']]]],
			'new vendor, contact created'         => ['export' => ['vendors' => [$vendor]], 'vcardUid' => 'uid-new'],
			'new vendor, generated uid'           => ['export' => ['vendors' => [$vendor]]],
			'existing vendor'                     => [
				'export'   => ['vendors' => [$vendor]],
				'contacts' => $acme,
				'found'    => ['contactsUid' => ['id' => 'sup-1', 'contactsUid' => 'uid-acme']],
			],
			'vendor with an integer id'           => [
				'export'   => ['vendors' => [['id' => 7, 'name' => 'Seven', 'email' => 's@x']]],
				'contacts' => ['s@x' => [['UID' => 'u7']]],
			],
			'vendor, no source record schema'     => ['export' => ['vendors' => [$vendor]], 'contacts' => $acme, 'config' => ['sourceRecord_schema' => '']],
			'vendor, source record save throws'   => ['export' => ['vendors' => [$vendor]], 'contacts' => $acme, 'throwOn' => 'schema-src'],
			'vendor, contacts unavailable'        => ['export' => ['vendors' => [$vendor]], 'contactsDisabled' => true],
			'vendor, not configured'              => ['export' => ['vendors' => [$vendor]], 'contacts' => $acme, 'config' => ['supplier_schema' => '']],
			'vendor, contact match without uid'   => [
				'export'   => ['vendors' => [$vendor]],
				'contacts' => ['123' => [['FN' => 'No uid']], 'a@acme.test' => [['UID' => '']]],
				'vcardUid' => 'uid-after-miss',
			],
			'vendor without ref or id'            => ['export' => ['vendors' => [['name' => 'Anon']]]],
			'vendor, supplier save throws'        => ['export' => ['vendors' => [$vendor]], 'contacts' => $acme, 'throwOn' => 'schema-supplier'],
			'both lists'                          => [
				'export'   => ['products' => [$product, ['id' => 'sp-2', 'sku' => 'SKU-2']], 'vendors' => [$vendor]],
				'contacts' => $acme,
			],
			'empty export'                        => ['export' => []],
		];
	}//end scenarios()

	/**
	 * Run one scenario against a fresh repair step.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function runScenario(array $scenario): array {
		$this->trace = [];
		$config = ($scenario['config'] ?? []) + [
			'register' => 'reg-1',
			'product_schema' => 'schema-product',
			'supplier_schema' => 'schema-supplier',
			'sourceRecord_schema' => 'schema-src',
		];
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$objects = $this->objectDouble(scenario: $scenario);
		$contacts = $this->contactsDouble(scenario: $scenario);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				'OCA\OpenRegister\Service\ObjectService' => $objects,
				'OCP\Contacts\IManager' => $contacts,
			}
		);

		$vcard = $this->createMock(originalClassName: ContactVcardService::class);
		$vcard->method('syncToContacts')->willReturnCallback(
			function (string $type, string $id) use ($scenario): ?string {
				$this->trace[] = ['syncToContacts' => [$type, preg_replace('/^pvm-ingest-.+$/', 'pvm-ingest-<uniqid>', $id)]];
				return ($scenario['vcardUid'] ?? null);
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		foreach (['error', 'warning', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					$this->trace[] = ['log' => $level, 'message' => (string)$message, 'context' => $context];
				}
			);
		}

		$step = new IngestProductVendorMaster(
			appManager: $this->createMock(originalClassName: IAppManager::class),
			appConfig: $appConfig,
			contactVcardService: $vcard,
			container: $container,
			logger: $logger,
		);

		try {
			$result = ['returned' => $step->ingest(export: $scenario['export'])];
		} catch (Throwable $e) {
			$result = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		return $this->normalise(value: $result + ['trace' => $this->trace]);
	}//end runScenario()

	/**
	 * An ObjectService double that records findAll and saveObject as the step calls them.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return object
	 */
	private function objectDouble(array $scenario): object {
		return new class($this, $scenario) {
			/**
			 * Saves so far.
			 *
			 * @var int
			 */
			private int $saves = 0;

			/**
			 * @param IngestProductVendorMasterGoldenTest $test     The test, for its trace.
			 * @param array<string, mixed>                $scenario The scenario.
			 */
			public function __construct(private IngestProductVendorMasterGoldenTest $test, private array $scenario) {
			}

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config): array {
				$this->test->trace[] = ['findAll' => $config];
				foreach (($this->scenario['found'] ?? []) as $field => $row) {
					if (array_key_exists($field, $config['filters']) === true) {
						return [$row];
					}
				}

				return [];
			}

			/**
			 * Records the arguments as passed, by name when the step names them.
			 *
			 * @param mixed ...$args The arguments the step passes.
			 *
			 * @return ObjectEntity The saved object, as OpenRegister returns it.
			 */
			public function saveObject(mixed ...$args): ObjectEntity {
				$this->test->trace[] = ['saveObject' => $args];
				$schema = ($args['schema'] ?? $args[1] ?? null);
				if (($this->scenario['throwOn'] ?? null) === $schema) {
					throw new RuntimeException('save failed for ' . $schema);
				}

				$this->saves++;
				$object = ($args['object'] ?? $args[2] ?? null);
				if (is_array($object) === false) {
					$object = [];
				}

				$entity = new ObjectEntity();
				$entity->setRegister((string)($args['register'] ?? $args[0] ?? ''));
				$entity->setSchema((string)$schema);
				if (($this->scenario['createdNoId'] ?? false) === true) {
					$entity->setObject($object);
					return $entity;
				}

				$id = ($args['uuid'] ?? $args[3] ?? ('new-' . $this->saves));
				$saved = ['id' => $id] + $object;
				if (($this->scenario['createdProductId'] ?? null) === 'same') {
					$saved['productId'] = $id;
				}

				$entity->setUuid((string)$id);
				$entity->setObject($saved);
				return $entity;
			}
		};
	}//end objectDouble()

	/**
	 * A Contacts manager double answering searches from the scenario.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return object
	 */
	private function contactsDouble(array $scenario): object {
		return new class($this, $scenario) {
			/**
			 * @param IngestProductVendorMasterGoldenTest $test     The test, for its trace.
			 * @param array<string, mixed>                $scenario The scenario.
			 */
			public function __construct(private IngestProductVendorMasterGoldenTest $test, private array $scenario) {
			}

			/**
			 * @return bool
			 */
			public function isEnabled(): bool {
				return (($this->scenario['contactsDisabled'] ?? false) === false);
			}

			/**
			 * @param string        $term    The search term.
			 * @param array<string> $fields  The fields.
			 * @param array<mixed>  $options The options.
			 *
			 * @return array<int, array<string, string>>
			 */
			public function search(string $term, array $fields, array $options): array {
				$this->test->trace[] = ['contacts.search' => [$term, $fields, $options]];
				return ($this->scenario['contacts'][$term] ?? []);
			}
		};
	}//end contactsDouble()

	/**
	 * Replace the clock: an ISO-8601 timestamp becomes `<now>`.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function normalise(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->normalise(value: $item), $value);
		}

		if (is_string($value) === true && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $value) === 1) {
			return '<now>';
		}

		return $value;
	}//end normalise()
}//end class

/**
 * A found row that serialises to an array, as an OpenRegister entity does.
 */
class ArrayRow implements \JsonSerializable {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $row The serialised row.
	 */
	public function __construct(private array $row) {
	}

	/**
	 * The row.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return $this->row;
	}
}
