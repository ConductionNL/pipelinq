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
 * @uses   \OCA\Pipelinq\Repair\Support\SourceRecordLink
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
			$this->markTestSkipped('recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$actual = json_decode($json, true);
		foreach ($expected as $name => $want) {
			$this->assertSame($want, ($actual[$name] ?? null), $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testGolden()

	/**
	 * The scenarios: an export, the config and how the world answers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$product = ['id' => 'sp-1', 'ref' => 'P-1', 'sku' => 'SKU-1', 'barcode' => '871', 'name' => 'Widget', 'leadTimeDays' => 3];
		$vendor = ['id' => 'sv-1', 'ref' => 'V-1', 'name' => 'Acme', 'kvkNumber' => '123', 'email' => 'a@acme.test', 'iban' => 'NL01', 'paymentTermDays' => 30];
		$found = ['id' => 'obj-p', 'productId' => 'prod-9', 'sku' => 'SKU-1'];
		return [
			'new product'                         => ['export' => ['products' => [$product]]],
			'new product keeps its productId'     => ['export' => ['products' => [$product]], 'createdProductId' => 'same'],
			'new product without object id'       => ['export' => ['products' => [$product]], 'createdNoId' => true],
			'existing product by sku'             => ['export' => ['products' => [$product]], 'found' => ['sku' => $found]],
			'existing product by barcode'         => ['export' => ['products' => [$product + ['sku' => '']]], 'found' => ['barcode' => ['id' => 'obj-b']]],
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
			'existing vendor'                     => ['export' => ['vendors' => [$vendor]], 'contacts' => ['123' => [['UID' => 'uid-acme']]], 'found' => ['contactsUid' => ['id' => 'sup-1', 'contactsUid' => 'uid-acme']]],
			'vendor with an integer id'           => ['export' => ['vendors' => [['id' => 7, 'name' => 'Seven', 'email' => 's@x']]], 'contacts' => ['s@x' => [['UID' => 'u7']]]],
			'vendor, no source record schema'     => ['export' => ['vendors' => [$vendor]], 'contacts' => ['123' => [['UID' => 'uid-acme']]], 'config' => ['sourceRecord_schema' => '']],
			'vendor, source record save throws'   => ['export' => ['vendors' => [$vendor]], 'contacts' => ['123' => [['UID' => 'uid-acme']]], 'throwOn' => 'schema-src'],
			'vendor, contacts unavailable'        => ['export' => ['vendors' => [$vendor]], 'contactsDisabled' => true],
			'both lists'                          => ['export' => ['products' => [$product, ['id' => 'sp-2', 'sku' => 'SKU-2']], 'vendors' => [$vendor]], 'contacts' => ['123' => [['UID' => 'uid-acme']]]],
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
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$objects = $this->objectDouble(scenario: $scenario);
		$contacts = $this->contactsDouble(scenario: $scenario);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				'OCA\OpenRegister\Service\ObjectService' => $objects,
				'OCP\Contacts\IManager' => $contacts,
			}
		);

		$vcard = $this->createMock(ContactVcardService::class);
		$vcard->method('syncToContacts')->willReturnCallback(
			function (string $type, string $id) use ($scenario): ?string {
				$this->trace[] = ['syncToContacts' => [$type, preg_replace('/^pvm-ingest-.+$/', 'pvm-ingest-<uniqid>', $id)]];
				return ($scenario['vcardUid'] ?? null);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					$this->trace[] = ['log' => $level, 'message' => (string)$message, 'context' => $context];
				}
			);
		}

		$step = new IngestProductVendorMaster(
			appManager: $this->createMock(IAppManager::class),
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
			 * @param mixed ...$args The arguments, in the order the step passes them.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(mixed ...$args): array {
				$this->test->trace[] = ['saveObject' => $args];
				if (($this->scenario['throwOn'] ?? null) === ($args[1] ?? null)) {
					throw new RuntimeException('save failed for ' . $args[1]);
				}

				$this->saves++;
				$object = is_array($args[2] ?? null) === true ? $args[2] : [];
				if (($this->scenario['createdNoId'] ?? false) === true) {
					return $object;
				}

				$id = ($args[3] ?? ('new-' . $this->saves));
				$saved = ['id' => $id] + $object;
				if (($this->scenario['createdProductId'] ?? null) === 'same') {
					$saved['productId'] = $id;
				}

				return $saved;
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
