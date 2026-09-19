<?php

/**
 * Unit tests for ConfigFileLoaderService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ConfigFileLoaderService.
 */
class ConfigFileLoaderServiceTest extends TestCase {
	/**
	 * The service under test.
	 *
	 * @var ConfigFileLoaderService
	 */
	private ConfigFileLoaderService $service;

	/**
	 * Set up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$appManager = $this->createMock(IAppManager::class);
		$this->service = new ConfigFileLoaderService($appManager);
	}//end setUp()

	/**
	 * Test ensureSourceType adds x-openregister if missing.
	 *
	 * @return void
	 */
	public function testEnsureSourceTypeAddsIfMissing(): void {
		$data = ['key' => 'value'];
		$result = $this->service->ensureSourceType($data);

		$this->assertSame('local', $result['x-openregister']['sourceType']);
	}//end testEnsureSourceTypeAddsIfMissing()

	/**
	 * Test ensureSourceType preserves existing sourceType.
	 *
	 * @return void
	 */
	public function testEnsureSourceTypePreservesExisting(): void {
		$data = ['x-openregister' => ['sourceType' => 'remote']];
		$result = $this->service->ensureSourceType($data);

		$this->assertSame('remote', $result['x-openregister']['sourceType']);
	}//end testEnsureSourceTypePreservesExisting()

	/**
	 * Test ensureSourceType adds sourceType when x-openregister exists but no sourceType.
	 *
	 * @return void
	 */
	public function testEnsureSourceTypeAddsSourceTypeToExisting(): void {
		$data = ['x-openregister' => ['other' => 'val']];
		$result = $this->service->ensureSourceType($data);

		$this->assertSame('local', $result['x-openregister']['sourceType']);
		$this->assertSame('val', $result['x-openregister']['other']);
	}//end testEnsureSourceTypeAddsSourceTypeToExisting()

	/**
	 * Test loadConfigurationFile throws on missing file.
	 *
	 * @return void
	 */
	public function testLoadConfigurationFileThrowsOnMissingFile(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn('/nonexistent/path');

		$service = new ConfigFileLoaderService($appManager);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Configuration file not found');

		$service->loadConfigurationFile();
	}//end testLoadConfigurationFileThrowsOnMissingFile()

	/**
	 * Build a fake app root with a monolith register file and optional
	 * fragments, returning a service whose app path resolves to it.
	 *
	 * @param array $monolith The monolith register data.
	 * @param array<string,array> $fragments Map of fragment filename => data.
	 *
	 * @return ConfigFileLoaderService The service bound to the fake app root.
	 */
	private function serviceWithFixture(array $monolith, array $fragments): ConfigFileLoaderService {
		$root = sys_get_temp_dir() . '/pq-cfl-' . uniqid('', true);
		mkdir($root . '/lib/Settings/register.d', 0777, true);
		file_put_contents($root . '/lib/Settings/pipelinq_register.json', json_encode($monolith));
		foreach ($fragments as $name => $data) {
			file_put_contents($root . '/lib/Settings/register.d/' . $name, json_encode($data));
		}

		$this->tempRoots[] = $root;

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn($root);

		return new ConfigFileLoaderService($appManager);
	}//end serviceWithFixture()

	/**
	 * Temp roots to clean up after the test.
	 *
	 * @var array<int, string>
	 */
	private array $tempRoots = [];

	/**
	 * Tear down: remove temp fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->tempRoots as $root) {
			if (is_dir($root) === true) {
				$files = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
					\RecursiveIteratorIterator::CHILD_FIRST
				);
				foreach ($files as $file) {
					$file->isDir() === true ? rmdir($file->getPathname()) : unlink($file->getPathname());
				}

				rmdir($root);
			}
		}

		$this->tempRoots = [];
	}//end tearDown()

	/**
	 * A fragment's components.objects[] MUST be unioned with the monolith's,
	 * not replace it (ADR-037). This is the core seed-membership guarantee:
	 * without it, a portal fragment with its own (or empty) objects list would
	 * silently drop every monolith seed object.
	 *
	 * @return void
	 */
	public function testFragmentObjectsAreUnionedNotReplaced(): void {
		$monolith = [
			'info' => ['version' => '1.0.0'],
			'components' => [
				'objects' => [
					['slug' => 'base-a'],
					['slug' => 'base-b'],
				],
			],
		];
		$fragment = [
			'components' => [
				'objects' => [
					['slug' => 'frag-c'],
				],
			],
		];

		$result = $this->serviceWithFixture($monolith, ['40-portal.json' => $fragment])
			->loadConfigurationFile();

		$slugs = array_column($result['components']['objects'], 'slug');
		$this->assertContains('base-a', $slugs, 'monolith seed objects must survive the merge');
		$this->assertContains('base-b', $slugs);
		$this->assertContains('frag-c', $slugs, 'fragment seed objects must be appended');
		$this->assertCount(3, $slugs);
	}//end testFragmentObjectsAreUnionedNotReplaced()

	/**
	 * An empty fragment objects[] MUST NOT wipe the monolith objects (the exact
	 * footgun the portal fragment carries with "objects": []).
	 *
	 * @return void
	 */
	public function testEmptyFragmentObjectsDoesNotWipeMonolith(): void {
		$monolith = [
			'info' => ['version' => '1.0.0'],
			'components' => ['objects' => [['slug' => 'keep-me']]],
		];
		$fragment = ['components' => ['objects' => []]];

		$result = $this->serviceWithFixture($monolith, ['40-portal.json' => $fragment])
			->loadConfigurationFile();

		$this->assertCount(1, $result['components']['objects']);
		$this->assertSame('keep-me', $result['components']['objects'][0]['slug']);
	}//end testEmptyFragmentObjectsDoesNotWipeMonolith()

	/**
	 * Duplicate seed objects (same content) MUST be deduplicated on union.
	 *
	 * @return void
	 */
	public function testUnionDeduplicatesIdenticalObjects(): void {
		$monolith = [
			'info' => ['version' => '1.0.0'],
			'components' => ['objects' => [['slug' => 'a', 'x' => 1]]],
		];
		$fragment = [
			'components' => ['objects' => [['x' => 1, 'slug' => 'a'], ['slug' => 'b']]],
		];

		$result = $this->serviceWithFixture($monolith, ['40-portal.json' => $fragment])
			->loadConfigurationFile();

		$slugs = array_column($result['components']['objects'], 'slug');
		$this->assertSame(['a', 'b'], $slugs, 'identical object (key order irrelevant) must not duplicate');
	}//end testUnionDeduplicatesIdenticalObjects()

	/**
	 * A new register added by a fragment must appear alongside the monolith
	 * register (object-key merge), and a fragment that extends an existing
	 * register's schemas[] membership must union, not replace it.
	 *
	 * @return void
	 */
	public function testRegisterSchemasMembershipIsUnioned(): void {
		$monolith = [
			'info' => ['version' => '1.0.0'],
			'components' => [
				'registers' => [
					'pipelinq' => ['slug' => 'pipelinq', 'schemas' => ['client', 'contact']],
				],
			],
		];
		$fragment = [
			'components' => [
				'registers' => [
					'pipelinq' => ['slug' => 'pipelinq', 'schemas' => ['request']],
					'pipelinq-portal' => ['slug' => 'pipelinq-portal', 'schemas' => ['crmPortalAccount']],
				],
			],
		];

		$result = $this->serviceWithFixture($monolith, ['40-portal.json' => $fragment])
			->loadConfigurationFile();
		$registers = $result['components']['registers'];

		$this->assertArrayHasKey('pipelinq-portal', $registers, 'new register must be added');
		$this->assertSame(
			['client', 'contact', 'request'],
			$registers['pipelinq']['schemas'],
			'existing register schemas[] membership must union, not replace'
		);
	}//end testRegisterSchemasMembershipIsUnioned()

	/**
	 * End-to-end #396 guard: the forecast fragment re-declares
	 * `lead.configuration` (to add `x-pipelinq-forecast-lifecycle`) and the
	 * loaded, fully-merged config MUST still carry the base's
	 * `x-openregister-mcp` block — otherwise OpenRegister never derives the
	 * `pipelinq.lead.search/get` tools. Exercises the real
	 * loadConfigurationFile() path (deep-merge of monolith + fragment), the
	 * exact shape ConfigurationService imports.
	 *
	 * @return void
	 */
	public function testForecastFragmentPreservesLeadMcpAnnotation(): void {
		$monolith = [
			'info' => ['version' => '1.0.0'],
			'components' => [
				'schemas' => [
					'lead' => [
						'slug' => 'lead',
						'version' => '1.3.0',
						'configuration' => [
							'x-openregister-mcp' => [
								'enabled' => true,
								'tools' => [
									'search' => ['scope' => 'read', 'filters' => ['status', 'stage', 'client']],
									'get' => ['scope' => 'read'],
								],
							],
						],
					],
				],
			],
		];
		// Mirrors lib/Settings/register.d/50-forecast.json: extends
		// lead.configuration with a pipelinq-namespaced lifecycle key.
		$fragment = [
			'components' => [
				'schemas' => [
					'lead' => [
						'slug' => 'lead',
						'configuration' => [
							'x-pipelinq-forecast-lifecycle' => ['field' => 'forecast_category'],
						],
					],
				],
			],
		];

		$result = $this->serviceWithFixture($monolith, ['50-forecast.json' => $fragment])
			->loadConfigurationFile();
		$leadConf = $result['components']['schemas']['lead']['configuration'];

		$this->assertArrayHasKey(
			'x-openregister-mcp',
			$leadConf,
			'lead x-openregister-mcp must survive the forecast fragment merge (pipelinq#396)'
		);
		$this->assertArrayHasKey('x-pipelinq-forecast-lifecycle', $leadConf);
		$this->assertSame(
			['status', 'stage', 'client'],
			$leadConf['x-openregister-mcp']['tools']['search']['filters']
		);
	}//end testForecastFragmentPreservesLeadMcpAnnotation()

	/**
	 * Non-additive list values (e.g. a schema enum) MUST still be replaced by
	 * the fragment, preserving the default ADR-037 list semantics.
	 *
	 * @return void
	 */
	public function testNonAdditiveListsAreStillReplaced(): void {
		$monolith = [
			'info' => ['version' => '1.0.0'],
			'components' => [
				'schemas' => [
					'thing' => ['properties' => ['status' => ['enum' => ['old']]]],
				],
			],
		];
		$fragment = [
			'components' => [
				'schemas' => [
					'thing' => ['properties' => ['status' => ['enum' => ['new1', 'new2']]]],
				],
			],
		];

		$result = $this->serviceWithFixture($monolith, ['40-portal.json' => $fragment])
			->loadConfigurationFile();

		$this->assertSame(
			['new1', 'new2'],
			$result['components']['schemas']['thing']['properties']['status']['enum'],
			'a normal list value must be replaced, not unioned'
		);
	}//end testNonAdditiveListsAreStillReplaced()
}//end class
