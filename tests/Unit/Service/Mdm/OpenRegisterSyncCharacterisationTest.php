<?php

/**
 * Characterisation test for OpenRegisterSyncService::syncMasterToRegister().
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Mdm
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-011-sync-golden-record-to-openregister
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Mdm;

use OCA\Pipelinq\Service\Mdm\MdmObjectRepository;
use OCA\Pipelinq\Service\Mdm\OpenRegisterSyncService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the repository calls, the log lines and the answer per path.
 */
class OpenRegisterSyncCharacterisationTest extends TestCase {
	/**
	 * Calls seen, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $seen = [];

	/**
	 * Every path of the sync.
	 *
	 * @return void
	 */
	public function testEveryPath(): void {
		$marker = ['masterEntityRef' => 'm-1', 'isMasterRecord' => true];
		$cases = [
			'missing entity' => [null, [], null, [['findMasterEntity', 'm-1']]],
			'unmapped type' => [['entityType' => 'vendor'], [], null, [
				['findMasterEntity', 'm-1'],
				['debug', 'Pipelinq MDM: no OR schema mapped for entity type; skipping sync', ['master' => 'm-1', 'entityType' => 'vendor']],
			]],
			'no type' => [[], [], null, [
				['findMasterEntity', 'm-1'],
				['debug', 'Pipelinq MDM: no OR schema mapped for entity type; skipping sync', ['master' => 'm-1', 'entityType' => '']],
			]],
			'new canonical object' => [['entityType' => 'account', 'goldenRecord' => ['name' => 'Acme', 'isMasterRecord' => false]], [], ['saved' => 1], [
				['findMasterEntity', 'm-1'],
				['findAll', 'client', $marker],
				['save', 'client', ['name' => 'Acme', 'isMasterRecord' => true, 'masterEntityRef' => 'm-1'], null],
				['info', 'Pipelinq MDM: golden record synced to OpenRegister', ['master' => 'm-1', 'schema' => 'client', 'created' => true]],
			]],
			'existing by id, golden not a list' => [['entityType' => 'contact', 'goldenRecord' => 'x'], [['id' => 'o-1', 'name' => 'Old', 'extra' => 1]], ['saved' => 2], [
				['findMasterEntity', 'm-1'],
				['findAll', 'contact', $marker],
				['save', 'contact', ['id' => 'o-1', 'name' => 'Old', 'extra' => 1, 'masterEntityRef' => 'm-1', 'isMasterRecord' => true], 'o-1'],
				['info', 'Pipelinq MDM: golden record synced to OpenRegister', ['master' => 'm-1', 'schema' => 'contact', 'created' => false]],
			]],
			'existing by @self id, golden wins' => [['entityType' => 'product', 'goldenRecord' => ['name' => 'New']], [['@self' => ['id' => 's-1'], 'name' => 'Old']], ['saved' => 3], [
				['findMasterEntity', 'm-1'],
				['findAll', 'product', $marker],
				['save', 'product', ['@self' => ['id' => 's-1'], 'name' => 'New', 'masterEntityRef' => 'm-1', 'isMasterRecord' => true], 's-1'],
				['info', 'Pipelinq MDM: golden record synced to OpenRegister', ['master' => 'm-1', 'schema' => 'product', 'created' => false]],
			]],
			'existing without an id' => [['entityType' => 'product'], [['name' => 'Old']], ['saved' => 4], [
				['findMasterEntity', 'm-1'],
				['findAll', 'product', $marker],
				['save', 'product', ['name' => 'Old', 'masterEntityRef' => 'm-1', 'isMasterRecord' => true], null],
				['info', 'Pipelinq MDM: golden record synced to OpenRegister', ['master' => 'm-1', 'schema' => 'product', 'created' => true]],
			]],
			'first row not an object' => [['entityType' => 'product'], ['nope'], ['saved' => 5], [
				['findMasterEntity', 'm-1'],
				['findAll', 'product', $marker],
				['save', 'product', ['masterEntityRef' => 'm-1', 'isMasterRecord' => true], null],
				['info', 'Pipelinq MDM: golden record synced to OpenRegister', ['master' => 'm-1', 'schema' => 'product', 'created' => true]],
			]],
		];
		foreach ($cases as $name => [$entity, $existing, $saved, $trace]) {
			$answer = $this->service(entity: $entity, existing: $existing, saved: $saved)->syncMasterToRegister('m-1');

			self::assertSame($trace, $this->seen, $name);
			self::assertSame($saved, $answer, $name);
		}
	}//end testEveryPath()

	/**
	 * Build the service over recording fakes.
	 *
	 * @param array<string, mixed>|null $entity   The master entity.
	 * @param array<int, mixed>         $existing The linked OR objects.
	 * @param array<string, mixed>|null $saved    What save answers.
	 *
	 * @return OpenRegisterSyncService
	 */
	private function service(?array $entity, array $existing, ?array $saved): OpenRegisterSyncService {
		$this->seen = [];
		$repo = $this->createMock(MdmObjectRepository::class);
		$repo->method('findMasterEntity')->willReturnCallback(function (string $id) use ($entity): ?array {
			$this->seen[] = ['findMasterEntity', $id];
			return $entity;
		});
		$repo->method('findAll')->willReturnCallback(function (string $schema, array $filters = []) use ($existing): array {
			$this->seen[] = ['findAll', $schema, $filters];
			return $existing;
		});
		$repo->method('save')->willReturnCallback(function (string $schema, array $object, ?string $uuid = null) use ($saved): array {
			$this->seen[] = ['save', $schema, $object, $uuid];
			return (array)$saved;
		});
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level): void {
				$this->seen[] = [$level, $message, $context];
			});
		}

		return new OpenRegisterSyncService($repo, $logger);
	}//end service()
}//end class
