<?php

/**
 * Golden test for PartyOrganisationTreeService::setParent().
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-organisations-shall-nest-as-a-guarded-tree-carrying-their-own-fields-req-pfi-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\PartyOrganisationTreeService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The answer and every write of a move, for moves under another node, to the
 * root, across the depth cap, into a cycle, onto itself, of unknown nodes and
 * with a failing write, compared with PartyTreeMoveGolden.json. Set
 * PARTY_TREE_MOVE_GOLDEN_RECORD=1 to rewrite the file, then run once more
 * without it.
 *
 * @covers \OCA\Pipelinq\Service\PartyOrganisationTreeService
 *
 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-organisations-shall-nest-as-a-guarded-tree-carrying-their-own-fields-req-pfi-005
 */
class PartyTreeMoveGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/PartyTreeMoveGolden.json';

	/**
	 * Writes made in the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $writes = [];

	/**
	 * Run every case and compare with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$cases = [
			'child under other'        => ['child', 'other'],
			'grandchild to root'       => ['grandchild', null],
			'grandchild to empty root' => ['grandchild', '  '],
			'root under other'         => ['root', 'other'],
			'root under its grandchild' => ['root', 'grandchild'],
			'onto itself'              => ['child', 'child'],
			'padded ids'               => [' child ', ' other '],
			'no node'                  => ['', 'other'],
			'unknown node'             => ['ghost', 'other'],
			'unknown parent'           => ['child', 'ghost'],
			'past the cap'             => ['root', 'deep4', ['cap' => '6']],
			'at the cap'               => ['child', 'deep3', ['cap' => '6']],
			'cap not configured'       => ['root', 'deep4', ['cap' => '0']],
			'pathless node'            => ['loose', 'other'],
			'pathless parent'          => ['child', 'loose'],
			'write fails'              => ['root', 'other', ['failOn' => 'grandchild']],
			'no register'              => ['child', 'other', ['register' => '']],
		];

		$actual = [];
		foreach ($cases as $name => $case) {
			$this->writes = [];
			$result = $this->service(options: ($case[2] ?? []))->setParent(nodeId: $case[0], parentId: $case[1]);
			$actual[$name] = ['result' => $result, 'writes' => $this->writes];
		}

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (getenv('PARTY_TREE_MOVE_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped(message: 'recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(expected: $expected, actual: $actual);
	}//end testGolden()

	/**
	 * A service over a fixed organisation tree.
	 *
	 * @param array<string, string> $options The depth cap, a uuid whose write fails, the register.
	 *
	 * @return PartyOrganisationTreeService
	 */
	private function service(array $options): PartyOrganisationTreeService {
		$tree = [
			'root' => ['id' => 'root', 'organisationPath' => '/root'],
			'child' => ['id' => 'child', 'parentOrganisation' => 'root', 'organisationPath' => '/root/child'],
			'grandchild' => ['id' => 'grandchild', 'parentOrganisation' => 'child', 'organisationPath' => '/root/child/grandchild'],
			'nameless' => ['uuid' => 'nameless', 'organisationPath' => '/root/child/nameless'],
			'idless' => ['organisationPath' => '/root/child/idless'],
			'other' => ['id' => 'other', 'organisationPath' => '/other'],
			'deep3' => ['id' => 'deep3', 'organisationPath' => '/a/b/deep3'],
			'deep4' => ['id' => 'deep4', 'organisationPath' => '/a/b/c/deep4'],
			'loose' => ['id' => 'loose'],
			'rootish' => ['id' => 'rootish', 'organisationPath' => '/rootish'],
		];

		$config = ['register' => 'reg-1', 'client_schema' => 'sch-client', 'party_organisation_max_depth' => ($options['cap'] ?? '4')];
		if (array_key_exists('register', $options) === true) {
			$config['register'] = $options['register'];
		}

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id) use ($tree): ?ObjectEntityInterface {
				if (isset($tree[(string)$id]) === false) {
					return null;
				}

				$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($tree[(string)$id]);
				return $entity;
			}
		);
		$objects->method('findAll')->willReturn(array_values($tree));
		$objects->method('saveObject')->willReturnCallback(
			function (array $object) use ($options): ObjectEntityInterface {
				$this->writes[] = $object;
				if (($object['id'] ?? '') === ($options['failOn'] ?? null)) {
					throw new RuntimeException('locked');
				}

				return $this->createMock(originalClassName: ObjectEntityInterface::class);
			}
		);

		return new PartyOrganisationTreeService(
			appConfig: $appConfig,
			objectService: $objects,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end service()
}//end class
