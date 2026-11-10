<?php

/**
 * Golden test for BlastService::sendBlast.
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
 * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\Pipelinq\Service\BlastService;
use OCA\Pipelinq\Service\Marketing\MailTransportService;
use OCA\Pipelinq\Service\SegmentService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every summary sendBlast() gives, the audience it asks for, every store
 * call it makes and every log line, compared with BlastSendGolden.json. Set
 * BLAST_SEND_GOLDEN_RECORD=1 to rewrite the file, then run once more
 * without it.
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.3
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class BlastSendGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/BlastSendGolden.json';

	/**
	 * Store and audience calls and log lines of the current scenario.
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

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
		$json = (string)preg_replace('/"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[^"]*"/', '"<now>"', $json);
		if (getenv('BLAST_SEND_GOLDEN_RECORD') !== false) {
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
	 * The scenarios: the stored blast, the audience and the call.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$members = [];
		for ($i = 1; $i <= 6; $i++) {
			$members[] = ['contactId' => 'ab-' . $i, 'email' => 'ab' . $i . '@example.test', 'phone' => '+3160000000' . $i];
		}

		$audience = ['members' => $members, 'missingConsent' => ['m-1', 'm-2'], 'suppressed' => ['s-1']];
		$segment = ['uuid' => 'b-1', 'name' => 'Q4', 'segmentId' => 'seg-1', 'templateId' => 't-1', 'channel' => 'email', 'status' => 'draft'];
		return [
			'not found'                        => ['blast' => null, 'audience' => $audience],
			'not a draft'                      => ['blast' => [...$segment, 'status' => 'sent'], 'audience' => $audience],
			'no status, no audience'           => ['blast' => ['uuid' => 'b-1', 'name' => 'x'], 'audience' => $audience],
			'list audience, default channel'   => ['blast' => ['uuid' => 'b-1', 'listId' => 'list-1', 'status' => 'draft'], 'audience' => $audience],
			'segment and list'                 => ['blast' => [...$segment, 'listId' => 'list-2'], 'audience' => $audience],
			'nobody compliant'                 => ['blast' => $segment, 'audience' => ['members' => [], 'missingConsent' => ['m-1'], 'suppressed' => ['s-1', 's-2']]],
			'nobody compliant, draft'          => ['blast' => $segment, 'audience' => ['members' => [], 'missingConsent' => ['m-1']], 'draft' => true],
			'draft preview with a split'       => ['blast' => [...$segment, 'abSplitPercent' => 50], 'audience' => $audience, 'draft' => true],
			'a/b split at 50'                  => ['blast' => [...$segment, 'abSplitPercent' => '50'], 'audience' => $audience],
			'a/b split at 0'                   => ['blast' => [...$segment, 'abSplitPercent' => 0], 'audience' => $audience],
			'split out of range'               => ['blast' => [...$segment, 'abSplitPercent' => 150], 'audience' => $audience],
			'split not numeric'                => ['blast' => [...$segment, 'abSplitPercent' => 'half'], 'audience' => $audience],
			'one delivery fails to save'       => ['blast' => $segment, 'audience' => ['members' => [...array_slice($members, 0, 2), ['contactId' => 'c-fail', 'email' => 'f@example.test']], 'missingConsent' => []]],
			'sms with unsubscribe links'       => ['blast' => [...$segment, 'channel' => 'sms', 'sentAt' => 'earlier'], 'audience' => ['members' => [['contactId' => 'c-1', 'phone' => '+31611', 'unsubscribeUrl' => 'https://u/1'], ['contactId' => 'c-2']], 'missingConsent' => [], 'suppressed' => []]],
			'variant cannot be saved'          => ['blast' => [...$segment, 'abSplitPercent' => 50, 'name' => 'fail-variant'], 'audience' => $audience],
			'members without a contact id'     => ['blast' => $segment, 'audience' => ['members' => [['email' => 'a@x'], ['contactId' => '', 'email' => 'b@x']], 'missingConsent' => []]],
		];
	}//end scenarios()

	/**
	 * Run one scenario against a fresh service and store.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function runScenario(array $scenario): array {
		$this->trace = [];
		$store = new BlastSendGoldenStore(test: $this);
		if ($scenario['blast'] !== null) {
			$store->rows['b-1'] = $scenario['blast'];
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($store): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $store;
				}

				throw new RuntimeException('not registered: ' . $id);
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return match ($key) {
					'register' => 'pipelinq',
					'blast_schema' => 'blast',
					'blastDelivery_schema' => 'blastDelivery',
					default => $default,
				};
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->trace[] = ['log' => $level, 'message' => $message, 'context' => $context];
				}
			);
		}

		$test = $this;
		$audience = $scenario['audience'];
		$service = new class($container, $appConfig, $this->createMock(SegmentService::class), $this->createMock(MailTransportService::class), $logger, $test, $audience) extends BlastService {
			/**
			 * Constructor.
			 *
			 * @param ContainerInterface    $container    Container.
			 * @param IAppConfig            $appConfig    App config.
			 * @param SegmentService        $segments     Segments.
			 * @param MailTransportService  $transport    Transport.
			 * @param LoggerInterface       $logger       Logger.
			 * @param BlastSendGoldenTest   $test         The test, for the trace.
			 * @param array<string, mixed>  $audienceRows The audience to answer.
			 */
			public function __construct(
				ContainerInterface $container,
				IAppConfig $appConfig,
				SegmentService $segments,
				MailTransportService $transport,
				LoggerInterface $logger,
				private BlastSendGoldenTest $test,
				private array $audienceRows,
			) {
				parent::__construct($container, $appConfig, $segments, $transport, $logger);
			}//end __construct()

			/**
			 * Record the audience question and answer the scenario's audience.
			 *
			 * @param string $segmentId Segment id.
			 * @param string $listId    List id.
			 * @param string $channel   Channel.
			 *
			 * @return array<string, mixed>
			 */
			protected function resolveAudience(string $segmentId, string $listId, string $channel): array {
				$this->test->trace[] = ['audience' => ['segmentId' => $segmentId, 'listId' => $listId, 'channel' => $channel]];
				return $this->audienceRows;
			}//end resolveAudience()
		};

		$summary = $service->sendBlast('b-1', ($scenario['draft'] ?? false));
		return ['summary' => $summary, 'trace' => $this->trace];
	}//end runScenario()
}//end class

/**
 * In-memory ObjectService that records every call by parameter name.
 *
 * Deliveries for contact `c-fail` and the variant of a blast named
 * `fail-variant` fail to save.
 */
class BlastSendGoldenStore {
	/**
	 * Stored objects by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $rows = [];

	/**
	 * Saved deliveries.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $deliveries = [];

	/**
	 * Constructor.
	 *
	 * @param BlastSendGoldenTest $test The test, for the trace.
	 */
	public function __construct(private BlastSendGoldenTest $test) {
	}//end __construct()

	/**
	 * Find one object.
	 *
	 * @param string $id       Id.
	 * @param mixed  $register Register.
	 * @param mixed  $schema   Schema.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find(string $id, $register = null, $schema = null): ?array {
		$this->test->trace[] = ['find' => ['id' => $id, 'register' => $register, 'schema' => $schema]];
		return ($this->rows[$id] ?? null);
	}//end find()

	/**
	 * Find deliveries by filters.
	 *
	 * @param array<string, mixed> $config Config.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config = []): array {
		$this->test->trace[] = ['findAll' => $config];
		$filters = ($config['filters'] ?? []);
		unset($filters['register'], $filters['schema']);
		$out = [];
		foreach ($this->deliveries as $row) {
			foreach ($filters as $key => $value) {
				if (($row[$key] ?? null) !== $value) {
					continue 2;
				}
			}

			$out[] = $row;
		}

		return array_slice($out, (int)($config['offset'] ?? 0), ($config['limit'] ?? null));
	}//end findAll()

	/**
	 * Save one object.
	 *
	 * @param array<string, mixed> $object   Payload.
	 * @param mixed                $register Register.
	 * @param mixed                $schema   Schema.
	 * @param string|null          $uuid     Existing id.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): array {
		$register = $options->target->register;
		$schema = $options->target->schema;
		$uuid = $options->uuid;
		$this->test->trace[] = ['saveObject' => ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid]];
		if (($object['contactId'] ?? '') === 'c-fail' || str_starts_with((string)($object['name'] ?? ''), 'fail-variant') === true && isset($object['abVariantOf']) === true) {
			throw new RuntimeException('save failed');
		}

		if ($uuid === null || $uuid === '') {
			$uuid = 'saved-' . count($this->rows) . '-' . count($this->deliveries);
		}

		$object['uuid'] = $uuid;
		if ($schema === 'blastDelivery') {
			$this->deliveries[] = $object;
			return $object;
		}

		$this->rows[$uuid] = $object;
		return $object;
	}//end saveObject()
}//end class
