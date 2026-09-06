<?php

/**
 * The five standard audiences, evaluated against the demo data.
 *
 * 🔴 IT READS BOTH SIDES RATHER THAN RESTATING EITHER. The rule trees come out
 * of `lib/Settings/register.d/99-marketing-integrated-campaigns.json` and the
 * customers, contracts and leads come out of `lib/Settings/demo_seed_data.json`,
 * `@days:` offsets resolved the way DemoSeedService resolves them. A test that
 * retyped the rules would pass while the shipped fragment matched nobody.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-five-standard-audiences-ship-as-segments-a-marketer-copies
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\BookkeepingSignals;
use OCA\Pipelinq\Service\Marketing\SegmentSignalService;
use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Service\SegmentService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests that each seeded audience is a rule tree that actually resolves.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-five-standard-audiences-ship-as-segments-a-marketer-copies
 */
class StandardAudiencesTest extends TestCase {

	/**
	 * The five slugs the register fragment seeds.
	 *
	 * @var array<int, string>
	 */
	private const SLUGS = [
		'segment-lapsed-customers',
		'segment-top-tier-customers',
		'segment-service-without-product',
		'segment-renewing-within-ninety-days',
		'segment-stalled-leads-thirty-days',
	];

	/**
	 * The seeded segments, keyed by slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $audiences = [];

	/**
	 * The demo store: clients, contracts and leads with dates resolved.
	 *
	 * @var InMemoryListObjectStore
	 */
	private InMemoryListObjectStore $store;

	/**
	 * Read the shipped fragment and the shipped demo data.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$root = dirname(__DIR__, 4);

		$fragment = json_decode(
			(string)file_get_contents($root . '/lib/Settings/register.d/99-marketing-integrated-campaigns.json'),
			true
		);
		foreach (($fragment['components']['objects'] ?? []) as $object) {
			$slug = (string)($object['@self']['slug'] ?? '');
			if ($slug !== '') {
				$this->audiences[$slug] = $object;
			}
		}

		$demo = json_decode((string)file_get_contents($root . '/lib/Settings/demo_seed_data.json'), true);
		$this->store = new InMemoryListObjectStore([
			'client' => $this->clientsOf(demo: $demo),
			'salesContract' => $this->linkedOf(demo: $demo, collection: 'contracts', field: 'clientRef'),
			'lead' => $this->linkedOf(demo: $demo, collection: 'leads', field: 'client'),
			'product' => [],
		]);
	}//end setUp()

	/**
	 * All five audiences are seeded, and each one satisfies the segment
	 * schema's required list. OpenRegister refuses an object that does not
	 * and the import drops it without an error, so a missing key here is a
	 * standard audience nobody would ever see.
	 *
	 * @return void
	 */
	public function testEveryAudienceIsSeededAndSatisfiesItsSchema(): void {
		foreach (self::SLUGS as $slug) {
			$this->assertArrayHasKey($slug, $this->audiences, $slug . ' is not seeded');
			foreach (['name', 'rules', 'entityType'] as $required) {
				$this->assertArrayHasKey($required, $this->audiences[$slug], $slug . ' is missing ' . $required);
			}

			$this->assertNotSame('', (string)$this->audiences[$slug]['name']);
			$this->assertContains($this->audiences[$slug]['entityType'], ['contact', 'customer']);
		}
	}//end testEveryAudienceIsSeededAndSatisfiesItsSchema()

	/**
	 * Every audience's rule tree passes the validator, so a marketer who
	 * copies one can save the copy.
	 *
	 * @return void
	 */
	public function testEveryAudienceValidates(): void {
		$service = $this->segmentService();
		foreach (self::SLUGS as $slug) {
			$error = $service->validateRules(
				(array)$this->audiences[$slug]['rules'],
				(string)$this->audiences[$slug]['entityType']
			);
			$this->assertNull($error, $slug . ': ' . (string)$error);
		}
	}//end testEveryAudienceValidates()

	/**
	 * The renewal audience picks the demo customer whose contract ends in
	 * 45 days and leaves the one ending in 245 days alone.
	 *
	 * @return void
	 */
	public function testRenewalAudienceMatchesTheDemoContract(): void {
		$matched = $this->matchesOf(slug: 'segment-renewing-within-ninety-days');

		$this->assertContains('bakkerij', $matched);
		$this->assertNotContains('gemeente', $matched);
	}//end testRenewalAudienceMatchesTheDemoContract()

	/**
	 * The stalled-lead audience picks the customer whose open lead has sat
	 * in Qualified for 31 days and not the one that moved three days ago.
	 *
	 * @return void
	 */
	public function testStalledLeadAudienceEvaluates(): void {
		$matched = $this->matchesOf(slug: 'segment-stalled-leads-thirty-days');

		$this->assertContains('gemeente', $matched);
		$this->assertNotContains('bakkerij', $matched);
	}//end testStalledLeadAudienceEvaluates()

	/**
	 * The three bookkeeping audiences match NOBODY against the demo data,
	 * and that is the correct answer rather than a defect: every seeded
	 * client's shillinqOrganisationRef is a nil-UUID placeholder, and CI
	 * installs no shillinq at all. The assertion exists so the day that
	 * changes, somebody is told rather than surprised.
	 *
	 * @return void
	 */
	public function testTheBookkeepingAudiencesAreEmptyAgainstTheDemoData(): void {
		foreach (['segment-lapsed-customers', 'segment-top-tier-customers', 'segment-service-without-product'] as $slug) {
			$this->assertSame([], $this->matchesOf(slug: $slug), $slug . ' should resolve to nobody here');
		}
	}//end testTheBookkeepingAudiencesAreEmptyAgainstTheDemoData()

	/**
	 * Which demo customers one audience matches, by their demo key.
	 *
	 * @param string $slug The seeded segment slug.
	 *
	 * @return array<int, string> The demo keys.
	 */
	private function matchesOf(string $slug): array {
		$service = $this->segmentService();
		$rules = (array)$this->audiences[$slug]['rules'];

		$matched = [];
		foreach ($this->store->findAll('client') as $client) {
			if ($service->evaluateRules($rules, $client) === true) {
				$matched[] = (string)($client['demoKey'] ?? '');
			}
		}

		return $matched;
	}//end matchesOf()

	/**
	 * A SegmentService whose signal service reads the demo store.
	 *
	 * @return SegmentService The service.
	 */
	private function segmentService(): SegmentService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $default
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => $default
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(time());

		$signals = new SegmentSignalService(
			$this->store,
			new BookkeepingSignals(new FakeShillinqInvoiceReader(), $appConfig, $time),
			$time,
		);

		$schemaMapper = new class {
			/**
			 * A schema whose properties are the client schema's own.
			 *
			 * @param string $id The slug.
			 * @param bool $rbac Ignored.
			 * @param bool $multitenancy Ignored.
			 *
			 * @return object The schema.
			 */
			public function find(string $id, bool $_rbac = true, bool $_multitenancy = true): object {
				return new class {
					/**
					 * The client schema's properties.
					 *
					 * @return array<string, array<string, string>> The map.
					 */
					public function getProperties(): array {
						return [
							'name' => ['type' => 'string'],
							'type' => ['type' => 'string'],
							'email' => ['type' => 'string'],
							'industry' => ['type' => 'string'],
						];
					}
				};
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($schemaMapper) {
				if ($id === 'OCA\\OpenRegister\\Db\\SchemaMapper') {
					return $schemaMapper;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		return new SegmentService(
			$container,
			$appConfig,
			$this->createMock(SchemaMapService::class),
			$this->createMock(ICacheFactory::class),
			$this->createMock(LoggerInterface::class),
			$signals,
		);
	}//end segmentService()

	/**
	 * The demo clients, each carrying its demo key so a match can be named.
	 *
	 * @param array<string, mixed> $demo The demo seed document.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function clientsOf(array $demo): array {
		$rows = [];
		foreach (($demo['clients'] ?? []) as $client) {
			$row = $this->resolve(data: (array)($client['data'] ?? []));
			$row['uuid'] = (string)$client['key'];
			$row['demoKey'] = (string)$client['key'];
			// Exactly what 91-time-billing-handoff.json seeds: an obvious
			// placeholder that resolves to no bookkeeping at all.
			$row['shillinqOrganisationRef'] = '00000000-0000-0000-0000-000000000000';
			$rows[] = $row;
		}

		return $rows;
	}//end clientsOf()

	/**
	 * One demo collection, linked to its client by the named field.
	 *
	 * @param array<string, mixed> $demo The demo seed document.
	 * @param string $collection The collection key.
	 * @param string $field The field carrying the client link.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function linkedOf(array $demo, string $collection, string $field): array {
		$rows = [];
		foreach (($demo[$collection] ?? []) as $entry) {
			$row = $this->resolve(data: (array)($entry['data'] ?? []));
			$row['uuid'] = (string)$entry['key'];
			$row[$field] = (string)($entry['clientKey'] ?? '');
			$rows[] = $row;
		}

		return $rows;
	}//end linkedOf()

	/**
	 * Resolve `@days:N` placeholders exactly as DemoSeedService does.
	 *
	 * @param array<string, mixed> $data The raw definition.
	 *
	 * @return array<string, mixed> The resolved data.
	 */
	private function resolve(array $data): array {
		foreach ($data as $field => $value) {
			$matches = [];
			if (is_string($value) === false || preg_match('/^@(days|datetime):(-?\d+)$/', $value, $matches) !== 1) {
				continue;
			}

			$stamp = strtotime(sprintf('%+d days', (int)$matches[2]));
			$data[$field] = ($matches[1] === 'days' ? date('Y-m-d', (int)$stamp) : date('c', (int)$stamp));
		}

		return $data;
	}//end resolve()
}//end class
