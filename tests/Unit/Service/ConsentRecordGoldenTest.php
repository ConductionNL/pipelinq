<?php

/**
 * Golden test for ConsentService's opt-in and opt-out recording.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-writes-every-wish-to-integriq-req-cii-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\ContactAddressLookup;
use OCA\Pipelinq\Tests\Unit\Support\FakeIntegriq;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every result recordOptIn() and recordOptOut() give, every change they hand
 * integriq, and every row they write, compared with ConsentRecordGolden.json.
 * Set CONSENT_RECORD_GOLDEN_RECORD=1 to rewrite the file, then run once more
 * without it.
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-writes-every-wish-to-integriq-req-cii-003
 */
class ConsentRecordGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/ConsentRecordGolden.json';

	/**
	 * Warnings logged during the current scenario.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $warnings = [];

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

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
		if (getenv('CONSENT_RECORD_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, $json);
			$this->markTestSkipped('recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $name => $want) {
			$this->assertSame($want, ($actual[$name] ?? null), $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testGolden()

	/**
	 * The scenarios: the call, its arguments, the store and integriq's state.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$in = ['contactId' => 'contact-1', 'channel' => 'sms', 'source' => 'webform', 'evidence' => 'form 12'];
		$out = ['contactId' => 'contact-1', 'channel' => 'whatsapp', 'source' => 'keyword-stop', 'evidence' => 'STOP'];
		$pending = [
			['uuid' => 'seed-new', 'contactId' => 'contact-1', 'channel' => 'sms', 'state' => 'opted-out', 'source' => 'keyword-stop', 'evidence' => 'stop', 'recordedAt' => '2030-01-02T00:00:00.000000Z', 'legalBasis' => 'consent'],
			['uuid' => 'seed-old', 'contactId' => 'contact-1', 'channel' => 'sms', 'state' => 'opted-in', 'source' => 'webform', 'evidence' => 'form', 'recordedAt' => '2030-01-01T00:00:00.000000Z', 'legalBasis' => 'contract'],
			['uuid' => 'seed-before', 'contactId' => 'contact-1', 'channel' => 'sms', 'state' => 'opted-in', 'source' => 'webform', 'evidence' => 'old', 'recordedAt' => '2001-01-01T00:00:00.000000Z', 'legalBasis' => 'consent'],
			['uuid' => 'seed-other', 'contactId' => 'contact-2', 'channel' => 'sms', 'state' => 'opted-out', 'source' => 'keyword-stop', 'evidence' => 'x', 'recordedAt' => '2030-01-03T00:00:00.000000Z', 'legalBasis' => 'consent'],
		];
		$sparse = [
			['uuid' => 'seed-no-basis', 'contactId' => 'contact-1', 'channel' => 'sms', 'state' => 'opted-in', 'source' => 'webform', 'evidence' => 'form', 'recordedAt' => '2030-01-01T00:00:00.000000Z'],
			['uuid' => 'seed-empty-basis', 'contactId' => 'contact-1', 'channel' => 'sms', 'state' => 'opted-in', 'source' => 'webform', 'evidence' => 'form', 'recordedAt' => '2030-01-02T00:00:00.000000Z', 'legalBasis' => ''],
			['uuid' => 'seed-bare', 'contactId' => 'contact-1', 'channel' => 'sms', 'state' => 'opted-out', 'recordedAt' => '2030-01-03T00:00:00.000000Z'],
		];
		return [
			'before cutover: opt-in with a basis'          => ['call' => 'recordOptIn', 'args' => [...$in, 'legalBasis' => 'legitimate-interest']],
			'before cutover: opt-out, default basis'       => ['call' => 'recordOptOut', 'args' => $out],
			'before cutover: opt-out with a basis'         => ['call' => 'recordOptOut', 'args' => [...$out, 'legalBasis' => 'contract']],
			'before cutover: opt-in, empty basis'          => ['call' => 'recordOptIn', 'args' => [...$in, 'legalBasis' => '']],
			'before cutover: no contact'                   => ['call' => 'recordOptOut', 'args' => [...$out, 'contactId' => '', 'address' => '+31600000001']],
			'before cutover: no channel'                   => ['call' => 'recordOptIn', 'args' => [...$in, 'channel' => '']],
			'before cutover: empty source and evidence'    => ['call' => 'recordOptOut', 'args' => [...$out, 'source' => '', 'evidence' => '']],
			'before cutover: save throws'                  => ['call' => 'recordOptIn', 'args' => $in, 'saveThrows' => true],
			'before cutover: no openregister'              => ['call' => 'recordOptOut', 'args' => $out, 'noObjectService' => true],
			'cutover: opt-in, address looked up'           => ['call' => 'recordOptIn', 'args' => [...$in, 'legalBasis' => 'contract'], 'cutover' => true],
			'cutover: opt-in, empty basis'                 => ['call' => 'recordOptIn', 'args' => [...$in, 'legalBasis' => ''], 'cutover' => true],
			'cutover: opt-out with address and basis'      => ['call' => 'recordOptOut', 'args' => [...$out, 'legalBasis' => 'contract', 'address' => '+31 6 9999 0000'], 'cutover' => true],
			'cutover: opt-out from a number, no contact'   => ['call' => 'recordOptOut', 'args' => [...$out, 'contactId' => '', 'address' => '+31600000002'], 'cutover' => true],
			'cutover: opt-in from a number, no contact'    => ['call' => 'recordOptIn', 'args' => [...$in, 'contactId' => '', 'address' => '+31600000003'], 'cutover' => true],
			'cutover: no contact and no address'           => ['call' => 'recordOptOut', 'args' => [...$out, 'contactId' => ''], 'cutover' => true],
			'cutover: no channel'                          => ['call' => 'recordOptOut', 'args' => [...$out, 'channel' => '', 'address' => '+31600000004'], 'cutover' => true],
			'cutover: contact without a phone'             => ['call' => 'recordOptIn', 'args' => [...$in, 'contactId' => 'contact-3'], 'cutover' => true],
			'cutover: contact id is an email'              => ['call' => 'recordOptOut', 'args' => [...$out, 'contactId' => 'Jan@Example.nl', 'channel' => 'email'], 'cutover' => true],
			'cutover: integriq does not answer'            => ['call' => 'recordOptIn', 'args' => $in, 'cutover' => true, 'changeMode' => FakeIntegriq::MODE_UNHANDLED],
			'cutover: integriq throws'                     => ['call' => 'recordOptOut', 'args' => $out, 'cutover' => true, 'changeMode' => FakeIntegriq::MODE_THROW],
			'cutover: unanswered and no openregister'      => ['call' => 'recordOptOut', 'args' => $out, 'cutover' => true, 'changeMode' => FakeIntegriq::MODE_UNHANDLED, 'noObjectService' => true],
			'cutover: pending rows replay first'           => ['call' => 'recordOptIn', 'args' => $in, 'cutover' => true, 'stored' => $pending],
			'cutover: no contact skips the replay'         => ['call' => 'recordOptOut', 'args' => [...$out, 'contactId' => '', 'address' => '+31600000005'], 'cutover' => true, 'stored' => $pending],
			'cutover: replay unanswered, then fallback'    => ['call' => 'recordOptOut', 'args' => $out, 'cutover' => true, 'stored' => $pending, 'changeMode' => FakeIntegriq::MODE_UNHANDLED],
			'cutover: sparse pending rows replay'         => ['call' => 'recordOptOut', 'args' => $out, 'cutover' => true, 'stored' => $sparse],
			'change request: opt-in row'                  => ['call' => 'changeRequestFor', 'args' => ['row' => $pending[1], 'address' => '+31611111111']],
			'change request: opt-out row'                 => ['call' => 'changeRequestFor', 'args' => ['row' => $pending[0], 'address' => '']],
			'change request: opt-in without a basis'      => ['call' => 'changeRequestFor', 'args' => ['row' => $sparse[0], 'address' => 'a@example.nl']],
			'change request: empty row'                   => ['call' => 'changeRequestFor', 'args' => ['row' => ['id' => 'only-id'], 'address' => 'x']],
			'cutover: wish already in integriq'            => ['call' => 'recordOptOut', 'args' => [...$out, 'channel' => 'sms'], 'cutover' => true, 'rows' => [['address' => '+31612345678', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'sms', 'contactRef' => 'contact-1']]],
		];
	}//end scenarios()

	/**
	 * Run one scenario against a fresh service, store and integriq.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function runScenario(array $scenario): array {
		$this->warnings = [];
		$store = new ConsentRecordGoldenStore();
		$store->saveThrows = ($scenario['saveThrows'] ?? false);
		foreach (($scenario['stored'] ?? []) as $row) {
			$store->rows[$row['uuid']] = $row;
		}

		$integriq = new FakeIntegriq();
		$integriq->changeMode = ($scenario['changeMode'] ?? FakeIntegriq::MODE_ANSWER);
		foreach (($scenario['rows'] ?? []) as $row) {
			$integriq->seed($row);
		}

		$cutover = ($scenario['cutover'] ?? false);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => match ($key) {
				'consent.store' => ($cutover === true ? 'integriq' : 'pipelinq'),
				'consent.cutover_at' => '1700000000',
				default => $default,
			}
		);

		$noObjectService = ($scenario['noObjectService'] ?? false);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($store, $noObjectService): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService' && $noObjectService === false) {
					return $store;
				}

				throw new RuntimeException('not registered: ' . $id);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
			$this->warnings[] = ['message' => $message, 'context' => $context];
		});

		$service = new ConsentService(
			container: $container,
			appConfig: $appConfig,
			logger: $logger,
			integriq: FakeIntegriq::client($appConfig, $integriq, $logger),
			addresses: new ContactAddressLookup($container, $appConfig, $logger),
		);
		$result = $service->{$scenario['call']}(...$scenario['args']);

		$changes = [];
		foreach ($integriq->changeEvents as $event) {
			$request = $event->toRequest();
			if (isset($request['correlationId']) === true) {
				$request['correlationId'] = preg_replace('/^pipelinq-[0-9a-f]{16}$/', 'pipelinq-<random>', (string)$request['correlationId']);
			}

			$changes[] = $request;
		}

		return [
			'result' => $this->normalise(row: $result),
			'changes' => $changes,
			'integriqRows' => array_values($integriq->rows),
			'written' => array_map(fn (array $row): ?array => $this->normalise(row: $row), $store->written),
			'finds' => $store->finds,
			'warnings' => $this->warnings,
		];
	}//end runScenario()

	/**
	 * Replace the time a row was written now with a placeholder.
	 *
	 * @param array<string, mixed>|null $row The row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function normalise(?array $row): ?array {
		if ($row !== null && isset($row['recordedAt']) === true && str_starts_with((string)$row['uuid'], 'row-') === true) {
			$row['recordedAt'] = (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $row['recordedAt']) === 1 ? '<now>' : $row['recordedAt']);
		}

		return $row;
	}//end normalise()
}//end class

/**
 * OpenRegister's ObjectService as ConsentService and ContactAddressLookup use it.
 */
class ConsentRecordGoldenStore {
	/**
	 * Stored rows by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $rows = [];

	/**
	 * Rows saved during the scenario, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $written = [];

	/**
	 * Every find(), by its named arguments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $finds = [];

	/**
	 * Whether saveObject() throws.
	 *
	 * @var boolean
	 */
	public bool $saveThrows = false;

	/**
	 * Contacts by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $contacts = [
		'contact-1' => ['uuid' => 'contact-1', 'phone' => '+31612345678', 'email' => 'one@example.nl'],
		'contact-3' => ['uuid' => 'contact-3', 'email' => 'three@example.nl'],
	];

	/**
	 * A contact row.
	 *
	 * @param string $id       Id.
	 * @param mixed  $register Register.
	 * @param mixed  $schema   Schema.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find(string $id, $register = null, $schema = null): ?array {
		$this->finds[] = ['id' => $id, 'register' => $register, 'schema' => $schema];
		if ($schema === 'contact') {
			return ($this->contacts[$id] ?? null);
		}

		return null;
	}//end find()

	/**
	 * Save one row.
	 *
	 * @param array<string, mixed> $object   Payload.
	 * @param mixed                $register Register.
	 * @param mixed                $schema   Schema.
	 * @param string|null          $uuid     Existing id.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array {
		if ($this->saveThrows === true) {
			throw new RuntimeException('save failed');
		}

		$object['uuid'] = ($uuid ?? ('row-' . (count($this->written) + 1)));
		$object['_register'] = $register;
		$object['_schema'] = $schema;
		$this->rows[$object['uuid']] = $object;
		$this->written[] = $object;
		return $object;
	}//end saveObject()

	/**
	 * Rows matching the filters.
	 *
	 * @param array<string, mixed> $config Config with a `filters` map.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config = []): array {
		$filters = ($config['filters'] ?? []);
		unset($filters['register'], $filters['schema']);
		$out = [];
		foreach ($this->rows as $row) {
			foreach ($filters as $key => $value) {
				if (($row[$key] ?? null) !== $value) {
					continue 2;
				}
			}

			$out[] = $row;
		}

		return $out;
	}//end findAll()
}//end class
