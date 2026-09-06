<?php

/**
 * Unit tests for the BackfillContactChannelArrays repair step.
 *
 * The step seeds `emails[]`/`phones[]` from the legacy scalar `email`/`phone`
 * fields for every stored client and contact whose array is missing or
 * empty. These tests cover what it has to get right:
 *
 *   - a record with a legacy email/phone but no array gets one seeded,
 *     marked `kind: "work"`, `primary: true`, `verified: false`
 *   - a record that already carries a non-empty array is left untouched —
 *     idempotent and non-destructive, a re-run is a no-op
 *   - a record with neither array nor scalar value is skipped, not saved
 *   - both `client` and `contact` schemas are covered independently
 *
 * The fake mirrors OpenRegister's real ObjectService signatures
 * (`findAll(array $config, bool $_rbac, bool $_multitenancy)` and
 * `saveObject(object:, extend:, register:, schema:, uuid:, _rbac:, ...)`),
 * not the shape this caller happens to use.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/contact-channel-details/specs/contacts-sync/spec.md#requirement-existing-records-backfill-channel-arrays-on-upgrade
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\BackfillContactChannelArrays;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * In-memory ObjectService double matching OpenRegister's real signatures.
 */
class FakeChannelBackfillObjectService {
	/**
	 * Stored objects keyed by schema then id.
	 *
	 * @var array<string, array<string, array<string,mixed>>>
	 */
	public array $store = [];

	/**
	 * Every saveObject() call, for asserting what the step wrote.
	 *
	 * @var array<int, array<string,mixed>>
	 */
	public array $saves = [];

	/**
	 * Filter-aware lookup mirroring ObjectService::findAll().
	 *
	 * @param array<string,mixed> $config Config carrying `filters` and `limit`.
	 * @param bool $_rbac Ignored by the fake.
	 * @param bool $_multitenancy Ignored by the fake.
	 *
	 * @return array<int, array<string,mixed>> Matching rows.
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$schema = (string)($config['filters']['schema'] ?? '');

		return array_values($this->store[$schema] ?? []);
	}//end findAll()

	/**
	 * Record and apply a save, mirroring ObjectService::saveObject().
	 *
	 * @param array<string,mixed> $object The object data.
	 * @param array|null $extend Ignored by the fake.
	 * @param string|null $register Register id.
	 * @param string|null $schema Schema id.
	 * @param string|null $uuid Existing uuid, or null to create.
	 * @param bool $_rbac Ignored by the fake.
	 * @param bool $_multitenancy Ignored by the fake.
	 * @param bool $silent Ignored by the fake.
	 * @param bool $_validation Ignored by the fake.
	 * @param array|null $uploadedFiles Ignored by the fake.
	 * @param mixed $currentUser Ignored by the fake.
	 *
	 * @return array<string,mixed> The stored object.
	 */
	public function saveObject(
		array $object,
		?array $extend = [],
		?string $register = null,
		?string $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $silent = false,
		bool $_validation = true,
		?array $uploadedFiles = null,
		mixed $currentUser = null,
	): array {
		$id = ($uuid ?? ($object['id'] ?? 'new'));
		$object['id'] = $id;
		$this->store[(string)$schema][$id] = $object;
		$this->saves[] = $object;

		return $object;
	}//end saveObject()
}//end class

/**
 * Tests for BackfillContactChannelArrays.
 */
class BackfillContactChannelArraysTest extends TestCase {
	/**
	 * The fake object store.
	 *
	 * @var FakeChannelBackfillObjectService
	 */
	private FakeChannelBackfillObjectService $objects;

	/**
	 * Build the step with the given client/contact rows.
	 *
	 * @param array<int,array<string,mixed>> $clients Client rows.
	 * @param array<int,array<string,mixed>> $contacts Contact rows.
	 *
	 * @return BackfillContactChannelArrays The step under test.
	 */
	private function makeStep(array $clients, array $contacts): BackfillContactChannelArrays {
		$this->objects = new FakeChannelBackfillObjectService();
		foreach ($clients as $row) {
			$this->objects->store['client-schema'][$row['id']] = $row;
		}

		foreach ($contacts as $row) {
			$this->objects->store['contact-schema'][$row['id']] = $row;
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return match ($key) {
					'register' => 'reg',
					'client_schema' => 'client-schema',
					'contact_schema' => 'contact-schema',
					default => $default,
				};
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objects);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn(null);

		return new BackfillContactChannelArrays(
			$appConfig,
			$container,
			$groupManager,
			$this->createMock(LoggerInterface::class),
		);
	}//end makeStep()

	/**
	 * The saved version of one row.
	 *
	 * @param string $id The row id.
	 *
	 * @return array<string,mixed>|null The saved row, or null when never saved.
	 */
	private function saved(string $id): ?array {
		foreach ($this->objects->saves as $save) {
			if (($save['id'] ?? '') === $id) {
				return $save;
			}
		}

		return null;
	}//end saved()

	/**
	 * A client with a legacy email and phone but no arrays gets both
	 * seeded as a single primary, unverified, "work" entry.
	 *
	 * @return void
	 */
	public function testSeedsEmailsAndPhonesFromLegacyScalars(): void {
		$step = $this->makeStep(
			clients: [['id' => 'c1', 'name' => 'Acme', 'email' => 'info@acme.example', 'phone' => '+31600000001']],
			contacts: []
		);

		$step->run($this->createMock(IOutput::class));

		$saved = $this->saved('c1');
		$this->assertSame(
			[['kind' => 'work', 'value' => 'info@acme.example', 'primary' => true, 'verified' => false]],
			$saved['emails'] ?? null
		);
		$this->assertSame(
			[['kind' => 'work', 'value' => '+31600000001', 'primary' => true, 'verified' => false]],
			$saved['phones'] ?? null
		);
	}//end testSeedsEmailsAndPhonesFromLegacyScalars()

	/**
	 * A record whose `emails`/`phones` array is already non-empty is left
	 * untouched — idempotent and non-destructive, a re-run is a no-op.
	 *
	 * @return void
	 */
	public function testLeavesRecordsWithExistingArraysUntouched(): void {
		$step = $this->makeStep(
			clients: [[
				'id' => 'c1',
				'name' => 'Acme',
				'email' => 'stale@acme.example',
				'emails' => [['kind' => 'private', 'value' => 'real@acme.example', 'primary' => true, 'verified' => true]],
				'phone' => '+31600000001',
				'phones' => [['kind' => 'mobile', 'value' => '+31699999999', 'primary' => true, 'verified' => true]],
			]],
			contacts: []
		);

		$step->run($this->createMock(IOutput::class));

		$this->assertSame([], $this->objects->saves, 'a record with non-empty arrays must not be saved again');
	}//end testLeavesRecordsWithExistingArraysUntouched()

	/**
	 * A record with neither a legacy scalar value nor an array is skipped
	 * (not saved) rather than written with an empty patch.
	 *
	 * @return void
	 */
	public function testSkipsRecordsWithNoChannelDataAtAll(): void {
		$step = $this->makeStep(
			clients: [['id' => 'c1', 'name' => 'No Channels']],
			contacts: []
		);

		$step->run($this->createMock(IOutput::class));

		$this->assertSame([], $this->objects->saves);
	}//end testSkipsRecordsWithNoChannelDataAtAll()

	/**
	 * Both `client` and `contact` schemas are backfilled independently in
	 * the same run.
	 *
	 * @return void
	 */
	public function testBackfillsBothClientAndContactSchemas(): void {
		$step = $this->makeStep(
			clients: [['id' => 'c1', 'name' => 'Acme', 'email' => 'info@acme.example']],
			contacts: [['id' => 'p1', 'name' => 'Jane', 'email' => 'jane@acme.example']]
		);

		$step->run($this->createMock(IOutput::class));

		$this->assertNotNull($this->saved('c1'));
		$this->assertNotNull($this->saved('p1'));
		$this->assertSame('jane@acme.example', $this->saved('p1')['emails'][0]['value'] ?? null);
	}//end testBackfillsBothClientAndContactSchemas()

	/**
	 * Only the missing array is seeded when one of the two already has
	 * data — the email backfill and the phone backfill are independent.
	 *
	 * @return void
	 */
	public function testSeedsOnlyTheMissingArray(): void {
		$step = $this->makeStep(
			clients: [[
				'id' => 'c1',
				'name' => 'Acme',
				'email' => 'info@acme.example',
				'emails' => [['kind' => 'work', 'value' => 'info@acme.example', 'primary' => true, 'verified' => false]],
				'phone' => '+31600000001',
			]],
			contacts: []
		);

		$step->run($this->createMock(IOutput::class));

		$saved = $this->saved('c1');
		$this->assertNotNull($saved, 'the phone-only patch must still trigger a save');
		$this->assertSame(
			[['kind' => 'work', 'value' => 'info@acme.example', 'primary' => true, 'verified' => false]],
			$saved['emails'] ?? null,
			'the already-populated emails array must be carried through unchanged, not duplicated'
		);
		$this->assertSame(
			[['kind' => 'work', 'value' => '+31600000001', 'primary' => true, 'verified' => false]],
			$saved['phones'] ?? null
		);
	}//end testSeedsOnlyTheMissingArray()
}//end class
