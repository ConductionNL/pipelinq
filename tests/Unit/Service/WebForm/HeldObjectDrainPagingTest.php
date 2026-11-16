<?php

/**
 * Paging, query shape and form lookup of HeldObjectDrain.
 *
 * Pins how the drain reads the held objects (page size, stop on a short
 * page, the filters and access flags it queries with), that each form key
 * is looked up once, and that a held object without the key property is
 * reported unmapped under an empty key.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\WebForm
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\WebForm;

use OCA\Pipelinq\Service\SystemServiceAccount;
use OCA\Pipelinq\Service\WebForm\HeldObjectDrain;
use OCA\Pipelinq\Service\WebForm\WebFormFinder;
use OCA\Pipelinq\Service\WebForm\WebFormIntakeService;
use OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes\FakeContainer;
use OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes\FakeObjectStore;
use OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes\ObjectServiceContract;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * How the drain pages through held objects and looks up their forms.
 *
 * @covers \OCA\Pipelinq\Service\WebForm\HeldObjectDrain
 * @uses   \OCA\Pipelinq\Service\WebForm\WebFormFinder
 * @uses   \OCA\Pipelinq\Service\WebForm\WebFormIntakeService
 * @uses   \OCA\Pipelinq\Service\SystemServiceAccount
 */
class HeldObjectDrainPagingTest extends TestCase {

	use ObjectServiceContract;

	/**
	 * The store of the last built drain.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * More than two pages are read in pages of 200 until a short page.
	 *
	 * @return void
	 */
	public function testReadsInPagesOf200UntilAShortPage(): void {
		$report = $this->drain(held: self::held(count: 450))
			->drain(register: '20', schema: '26', keyProperty: 'source', openFilter: ['status' => 'new'], apply: false);

		self::assertSame([0, 200, 400], array_column($this->heldQueries(), 'offset'));
		self::assertSame([200, 200, 200], array_column($this->heldQueries(), 'limit'));
		self::assertCount(450, $report['delivered']);
		self::assertSame(450, $report['pending']);
		self::assertSame('h-0', $report['delivered'][0]['id']);
		self::assertSame('h-449', $report['delivered'][449]['id']);
	}//end testReadsInPagesOf200UntilAShortPage()

	/**
	 * A full last page asks for one more page, which comes back empty.
	 *
	 * @return void
	 */
	public function testAFullLastPageIsFollowedByOneEmptyRead(): void {
		$report = $this->drain(held: self::held(count: 200))
			->drain(register: '20', schema: '26', keyProperty: 'source', openFilter: ['status' => 'new'], apply: false);

		self::assertSame([0, 200], array_column($this->heldQueries(), 'offset'));
		self::assertCount(200, $report['delivered']);
	}//end testAFullLastPageIsFollowedByOneEmptyRead()

	/**
	 * The query names the register and schema, adds the open filter and reads without access checks.
	 *
	 * @return void
	 */
	public function testQueriesWithRegisterSchemaAndOpenFilterWithoutRbac(): void {
		$this->drain(held: [])
			->drain(register: '20', schema: '26', keyProperty: 'source', openFilter: ['status' => 'new', 'channel' => 'web'], apply: false);

		self::assertSame(
			[['filters' => ['register' => '20', 'schema' => '26', 'status' => 'new', 'channel' => 'web'], 'limit' => 200, 'offset' => 0, '_rbac' => false]],
			$this->heldQueries()
		);
	}//end testQueriesWithRegisterSchemaAndOpenFilterWithoutRbac()

	/**
	 * Each distinct key is looked up once, however many held objects share it.
	 *
	 * @return void
	 */
	public function testEachKeyIsLookedUpOnce(): void {
		$held = self::held(count: 5);
		$held[] = ['@self' => ['id' => 'x-1'], 'source' => 'website-careers', 'status' => 'new'];
		$held[] = ['@self' => ['id' => 'x-2'], 'source' => 'website-careers', 'status' => 'new'];

		$report = $this->drain(held: $held)
			->drain(register: '20', schema: '26', keyProperty: 'source', openFilter: ['status' => 'new'], apply: false);

		$keysLookedUp = array_map(
			static fn (array $query): string => (string)($query['filters']['key'] ?? ''),
			$this->formQueries()
		);
		self::assertSame(['website-support', 'website-careers'], $keysLookedUp);
		self::assertSame([['id' => 'x-1', 'key' => 'website-careers'], ['id' => 'x-2', 'key' => 'website-careers']], $report['unmapped']);
	}//end testEachKeyIsLookedUpOnce()

	/**
	 * A held object without the key property is unmapped under an empty key, without a form lookup.
	 *
	 * @return void
	 */
	public function testMissingKeyPropertyIsUnmappedWithoutLookup(): void {
		$report = $this->drain(held: [['@self' => ['id' => 'nokey'], 'title' => 'x', 'status' => 'new']])
			->drain(register: '20', schema: '26', keyProperty: 'source', openFilter: ['status' => 'new'], apply: false);

		self::assertSame([['id' => 'nokey', 'key' => '']], $report['unmapped']);
		self::assertSame([], $report['delivered']);
		self::assertSame(1, $report['pending']);
		self::assertSame([], $this->formQueries());
	}//end testMissingKeyPropertyIsUnmappedWithoutLookup()

	/**
	 * Held rows mapped to the support form.
	 *
	 * @param int $count How many.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function held(int $count): array {
		$rows = [];
		for ($i = 0; $i < $count; $i++) {
			$rows[] = ['@self' => ['id' => 'h-' . $i], 'title' => 'T' . $i, 'message' => 'M', 'source' => 'website-support', 'status' => 'new'];
		}

		return $rows;
	}//end held()

	/**
	 * The queries the drain made on the held schema.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function heldQueries(): array {
		return array_values(
			array_filter($this->store->queries, static fn (array $query): bool => ($query['filters']['schema'] ?? '') === '26')
		);
	}//end heldQueries()

	/**
	 * The queries made on the form schema.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function formQueries(): array {
		return array_values(
			array_filter($this->store->queries, static fn (array $query): bool => ($query['filters']['schema'] ?? '') === '40')
		);
	}//end formQueries()

	/**
	 * The drain over the shipped forms and the given held rows.
	 *
	 * @param array<int, array<string, mixed>> $held The held rows.
	 *
	 * @return HeldObjectDrain
	 */
	private function drain(array $held): HeldObjectDrain {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ['register' => '20', 'webForm_schema' => '40'][$key] ?? $default
		);
		$this->store = new FakeObjectStore(rows: ['40' => WebFormIntakeServiceTest::shippedForms(), '26' => $held]);
		$account = new SystemServiceAccount(
			$this->createMock(IUserManager::class),
			$this->createMock(IUserSession::class),
			$this->createMock(ISecureRandom::class)
		);
		$finder = new WebFormFinder($config, $this->contractOver($this->store));

		return new HeldObjectDrain(
			$this->contractOver($this->store),
			$finder,
			new WebFormIntakeService($finder, new FakeContainer(services: []), $account, $this->createMock(LoggerInterface::class)),
			$account
		);
	}//end drain()
}//end class
