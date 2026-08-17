<?php

/**
 * Unit tests for MigrateAvgVerzoekenToOrDsar.
 *
 * Covers REQ-AVG-017: the article/status mapping matrix, extension + denial +
 * evidence + redaction mapping, the structured NL notes block, idempotency via
 * the `migratedTo` marker, and the unprovisioned no-op.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Repair\MigrateAvgVerzoekenToOrDsar;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for MigrateAvgVerzoekenToOrDsar.
 */
final class MigrateAvgVerzoekenToOrDsarTest extends TestCase {
	/**
	 * Schema-config-key => id.
	 *
	 * @var array<string, string>
	 */
	private const IDS = [
		'register' => '11',
		'avgVerzoek_schema' => '40',
		'bewijsItem_schema' => '41',
		'weigering_schema' => '42',
		'redactieActie_schema' => '43',
	];

	/**
	 * Mocked app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * Mocked ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * findAll store keyed by schema id.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Objects saved into OR's DSAR register (schema slug key).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $savedCases = [];

	/**
	 * avgVerzoek objects re-saved with the migratedTo marker.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $markedSources = [];

	/**
	 * The repair step under test.
	 *
	 * @var MigrateAvgVerzoekenToOrDsar
	 */
	private MigrateAvgVerzoekenToOrDsar $step;

	/**
	 * Wire the step over an in-memory OR ObjectService.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->store = [];
		$this->savedCases = [];
		$this->markedSources = [];

		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => self::IDS[$key] ?? $default
		);

		$store = &$this->store;
		$this->objectService->method('findAll')->willReturnCallback(
			static function (array $config) use (&$store): array {
				$schemaId = (string)($config['filters']['schema'] ?? '');
				return $store[$schemaId] ?? [];
			}
		);

		$savedCases = &$this->savedCases;
		$markedSources = &$this->markedSources;
		$store2 = &$this->store;
		$seq = 0;
		$this->objectService->method('saveObject')->willReturnCallback(
			static function (array $data, array $extend, $register, $schema, $uuid) use (&$savedCases, &$markedSources, &$store2, &$seq): object {
				if ((string)$schema === 'dataSubjectRequest') {
					$seq++;
					$data['id'] = 'dsar-' . $seq;
					$savedCases[] = $data;
					return self::entity('dsar-' . $seq);
				}

				// avgVerzoek re-save carrying the migratedTo marker.
				$markedSources[] = $data;
				// Reflect the marker back into the store so a re-run skips it.
				foreach (($store2['40'] ?? []) as $i => $row) {
					if (($row['id'] ?? null) === ($data['id'] ?? null)) {
						$store2['40'][$i] = $data;
					}
				}
				return self::entity((string)($data['id'] ?? 'x'));
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objectService);

		$this->step = new MigrateAvgVerzoekenToOrDsar(
			appConfig: $this->appConfig,
			container: $container,
			logger: new NullLogger(),
		);
	}//end setUp()

	/**
	 * Build the entity saveObject() returns.
	 *
	 * A bespoke anonymous class exposing only getUuid() no longer satisfies
	 * the contract: since ADR-084 `saveObject()` is declared to return
	 * `ObjectEntityInterface`, so the mock raised a TypeError on the way out —
	 * which the repair step's own `catch (\Throwable)` recorded as a failed
	 * migration. The real entity is used instead.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntityInterface The entity.
	 */
	private static function entity(string $uuid): ObjectEntityInterface {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);

		return $entity;
	}//end entity()

	/**
	 * A full avgVerzoek with satellites maps across every field group.
	 *
	 * @return void
	 */
	public function testMapsFullVerzoek(): void {
		$this->store = [
			'40' => [
				[
					'id' => 'v1',
					'kenmerk' => 'AVG-2026-1',
					'ingediendOp' => '2026-01-01T00:00:00Z',
					'ingediendVia' => 'web',
					'verzoekerContact' => 'contact-9',
					'artikel' => 'art-15-inzage',
					'specifiekeVraag' => 'all data',
					'scope' => ['crm'],
					'wettelijkeTermijnVerloopt' => '2026-02-01T00:00:00Z',
					'verlengdMet' => 30,
					'verlengingsgrond' => 'complex',
					'status' => 'in-progress',
					'behandelaar' => 'steward-a',
					'dpiaFlag' => true,
					'retentionTo' => '2027-01-01T00:00:00Z',
				],
			],
			'41' => [
				['id' => 'b1', 'requestId' => 'v1', 'bronApp' => 'pipelinq-crm', 'contentHash' => 'sha256:abc'],
				['id' => 'b2', 'requestId' => 'v1', 'bronApp' => 'pipelinq-crm', 'contentHash' => 'sha256:def'],
			],
			'42' => [
				// grond is the Art-23 sub-paragraph the refusal rests on — the
				// source enum, not a descriptive slug.
				['id' => 'w1', 'requestId' => 'v1', 'grond' => 'art-23-lid-1-sub-i', 'weigering' => 'gedeeltelijk', 'toelichtingAvg23' => 'x'],
			],
			'43' => [
				// A redactieActie hangs off a bewijsItem (bewijsItemId), never
				// off the verzoek — it has no verzoekId field at all.
				['id' => 'r1', 'bewijsItemId' => 'b1', 'veldpad' => 'email', 'voorWaarde' => 'a@b', 'naWaarde' => '***', 'grond' => 'bescherming-rechten-derden'],
			],
		];

		$this->step->run($this->createStub(IOutput::class));

		self::assertCount(1, $this->savedCases);
		$case = $this->savedCases[0];

		self::assertSame('contact-9', $case['subjectId']);
		self::assertSame('contact', $case['subjectType']);
		self::assertSame('NL', $case['jurisdiction']);
		self::assertSame('access', $case['type']);
		self::assertSame('in-progress', $case['status']);
		self::assertSame('2026-01-01T00:00:00Z', $case['receivedAt']);
		self::assertSame('steward-a', $case['handler']);
		self::assertTrue($case['dpiaRequired']);
		self::assertSame('2026-02-01T00:00:00Z', $case['dueAt']);
		self::assertSame('2027-01-01T00:00:00Z', $case['retainUntil']);
		self::assertStringStartsWith('2026-03-03', $case['extendedUntil']);
		self::assertSame('complex', $case['extensionReason']);
		self::assertSame('third-party-rights', $case['denialGround']);
		self::assertCount(2, $case['evidence']);
		self::assertSame('sha256:abc', $case['evidence'][0]['contentHash']);
		self::assertCount(1, $case['redactions']);
		self::assertSame('email', $case['redactions'][0]['field']);

		// NL extras preserved in the structured notes block.
		$notes = json_decode((string)$case['notes'], true);
		self::assertSame('pipelinq/avgVerzoek', $notes['migratedFrom']);
		self::assertSame('AVG-2026-1', $notes['kenmerk']);
		self::assertSame('web', $notes['ingediendVia']);

		// Source marked migrated.
		self::assertCount(1, $this->markedSources);
		self::assertSame('dsar-1', $this->markedSources[0]['migratedTo']);
	}//end testMapsFullVerzoek()

	/**
	 * Article + status mapping matrix, over the real `artikel` enum.
	 *
	 * @return void
	 */
	public function testArticleAndStatusMatrix(): void {
		$this->store = [
			'40' => [
				['id' => 'a', 'artikel' => 'art-17-wissing', 'status' => 'ingediend'],
				['id' => 'b', 'artikel' => 'art-16-rectificatie', 'status' => 'completed', 'outcome' => 'geweigerd'],
				['id' => 'd', 'artikel' => 'art-20-portabiliteit', 'status' => 'completed', 'outcome' => 'toegekend'],
			],
		];

		$this->step->run($this->createStub(IOutput::class));

		$byType = [];
		foreach ($this->savedCases as $c) {
			$byType[] = [$c['type'], $c['status']];
		}

		self::assertContains(['erasure', 'received'], $byType);
		self::assertContains(['rectification', 'refused'], $byType);
		self::assertContains(['portability', 'fulfilled'], $byType);
	}//end testArticleAndStatusMatrix()

	/**
	 * An erasure request must never be filed as an access request.
	 *
	 * Guards the mapping bug this replaces: ARTICLE_TYPE was keyed on bare
	 * article numbers ('art-17') while the source enum is 'art-17-wissing', so
	 * every lookup missed and fell through to a default of 'access'. The old
	 * fixtures used the bare keys, so the suite was green against a mapper that
	 * mistyped every request it touched.
	 *
	 * @return void
	 */
	public function testErasureIsNotFiledAsAccess(): void {
		$this->store = ['40' => [['id' => 'e1', 'artikel' => 'art-17-wissing', 'status' => 'ingediend']]];

		$this->step->run($this->createStub(IOutput::class));

		self::assertCount(1, $this->savedCases);
		self::assertSame('erasure', $this->savedCases[0]['type']);
	}//end testErasureIsNotFiledAsAccess()

	/**
	 * `geen-avg` has no dataSubjectRequest equivalent, so it is left in place
	 * rather than coerced into a type — filing a "not a GDPR request" as an
	 * access request would invent a compliance record that never existed.
	 *
	 * @return void
	 */
	public function testGeenAvgIsNotMigrated(): void {
		$this->store = ['40' => [['id' => 'c', 'artikel' => 'geen-avg', 'status' => 'ingediend']]];

		$this->step->run($this->createStub(IOutput::class));

		self::assertSame([], $this->savedCases);
		self::assertSame([], $this->markedSources);
	}//end testGeenAvgIsNotMigrated()

	/**
	 * A trashed (soft-deleted) verzoek is not resurrected into the compliance
	 * register. `findAll()` does not filter soft-deletes, so the step must.
	 *
	 * @return void
	 */
	public function testSoftDeletedVerzoekIsSkipped(): void {
		$this->store = [
			'40' => [
				[
					'id' => 'gone',
					'artikel' => 'art-15-inzage',
					'status' => 'completed',
					'deleted' => ['deletedAt' => '2026-06-23T12:58:15+00:00', 'deletedBy' => 'admin'],
				],
			],
		];

		$this->step->run($this->createStub(IOutput::class));

		self::assertSame([], $this->savedCases);
	}//end testSoftDeletedVerzoekIsSkipped()

	/**
	 * A LIVE verzoek carries an empty `deleted` block rather than a null one, so
	 * the trashed-object guard must test emptiness, not `!== null`. Guards the
	 * regression where a null-check skipped every object — including the ones
	 * the migration exists to move.
	 *
	 * @return void
	 */
	public function testEmptyDeletedBlockIsNotTreatedAsTrashed(): void {
		$this->store = [
			'40' => [
				['id' => 'live1', 'artikel' => 'art-15-inzage', 'status' => 'ingediend', 'deleted' => []],
				['id' => 'live2', 'artikel' => 'art-15-inzage', 'status' => 'ingediend', 'deleted' => null],
				['id' => 'live3', 'artikel' => 'art-15-inzage', 'status' => 'ingediend', 'deleted' => ''],
			],
		];

		$this->step->run($this->createStub(IOutput::class));

		self::assertCount(3, $this->savedCases);
	}//end testEmptyDeletedBlockIsNotTreatedAsTrashed()

	/**
	 * Redactions reach the case through the verzoek's bewijsItems, since a
	 * redactieActie is keyed by `bewijsItemId` and carries no `verzoekId`.
	 * Indexing it by `verzoekId` silently produced an empty `redactions[]` for
	 * every migrated request.
	 *
	 * @return void
	 */
	public function testRedactionsResolveThroughBewijsItems(): void {
		$this->store = [
			'40' => [['id' => 'v9', 'artikel' => 'art-15-inzage', 'status' => 'ingediend']],
			'41' => [['id' => 'b9', 'requestId' => 'v9', 'bronApp' => 'pipelinq-crm']],
			'43' => [
				['id' => 'r9', 'bewijsItemId' => 'b9', 'veldpad' => 'bsn', 'grond' => 'bescherming-rechten-derden'],
			],
		];

		$this->step->run($this->createStub(IOutput::class));

		self::assertCount(1, $this->savedCases);
		self::assertCount(1, $this->savedCases[0]['redactions']);
		self::assertSame('bsn', $this->savedCases[0]['redactions'][0]['field']);
	}//end testRedactionsResolveThroughBewijsItems()

	/**
	 * The source write-back must not carry nulls.
	 *
	 * An object read out of OpenRegister carries its unset properties as null,
	 * and the schema types them (`uitkomst` is a string). Saving the row back
	 * unchanged therefore failed validation on a field the migration never
	 * touched — so the `migratedTo` marker never landed, and because the case
	 * had already been created, every re-run produced a DUPLICATE case.
	 *
	 * @return void
	 */
	public function testMarkerWriteBackStripsNulls(): void {
		$this->store = [
			'40' => [
				[
					'id' => 'v1',
					'artikel' => 'art-15-inzage',
					'status' => 'ingediend',
					'outcome' => null,
				],
			],
		];

		$this->step->run($this->createStub(IOutput::class));

		self::assertCount(1, $this->savedCases);
		self::assertCount(1, $this->markedSources);

		$marked = $this->markedSources[0];
		self::assertSame('dsar-1', $marked['migratedTo']);
		self::assertArrayNotHasKey('outcome', $marked, 'null properties must not be written back');
	}//end testMarkerWriteBackStripsNulls()

	/**
	 * A re-run must not duplicate the case even when the source was never marked.
	 *
	 * This is the regression that mattered: the marker write-back CANNOT succeed
	 * from a repair step (the verzoek is system-owned and its folder is
	 * unreachable), so keying idempotency on the source marker meant the case was
	 * created, the marker failed, and the next run created a SECOND case. It ran
	 * three times here and produced three duplicates.
	 *
	 * Idempotency is therefore keyed on the target: the case records the source
	 * uuid in `notes.migratedFromId`.
	 *
	 * @return void
	 */
	public function testRerunDoesNotDuplicateWhenSourceWasNeverMarked(): void {
		$this->store = [
			'40' => [['id' => 'v1', 'artikel' => 'art-15-inzage', 'status' => 'ingediend']],
		];

		// First pass creates the case.
		$this->step->run($this->createStub(IOutput::class));
		self::assertCount(1, $this->savedCases);

		$notes = json_decode((string)$this->savedCases[0]['notes'], true);
		self::assertSame('v1', $notes['migratedFromId'], 'the case must record its source');

		// Simulate the real world: the marker never landed on the source, but the
		// case is now present in the DSAR register.
		$this->store['dataSubjectRequest'] = $this->savedCases;
		$this->savedCases = [];

		$this->step->run($this->createStub(IOutput::class));

		self::assertSame([], $this->savedCases, 're-run must not create a second case');
	}//end testRerunDoesNotDuplicateWhenSourceWasNeverMarked()

	/**
	 * The existing-case scan must recognise a case whose `notes` OpenRegister
	 * hands back as an already-decoded array rather than a JSON string.
	 *
	 * This is the live-only regression the string-notes fixture above missed:
	 * `mapVerzoek()` json_encodes notes, so a case built in-process carries a
	 * string — but OpenRegister hydrates a stored `notes` value into an array on
	 * read. Casting that array to string and json_decode()-ing it yields null, so
	 * `migratedFromId` was never extracted and every re-run duplicated the case.
	 *
	 * @return void
	 */
	public function testRerunSkipsWhenExistingCaseNotesIsAnArray(): void {
		$this->store = [
			'40' => [['id' => 'v1', 'artikel' => 'art-15-inzage', 'status' => 'ingediend']],
			// An existing case exactly as OR returns it on read: notes decoded.
			'dataSubjectRequest' => [
				['id' => 'dsar-existing', 'notes' => ['migratedFrom' => 'pipelinq/avgVerzoek', 'migratedFromId' => 'v1']],
			],
		];

		$this->step->run($this->createStub(IOutput::class));

		self::assertSame([], $this->savedCases, 'array-shaped notes must still be recognised as already-migrated');
	}//end testRerunSkipsWhenExistingCaseNotesIsAnArray()

	/**
	 * A re-run migrates nothing (idempotent via migratedTo marker).
	 *
	 * @return void
	 */
	public function testIdempotentRerun(): void {
		$this->store = [
			'40' => [
				['id' => 'v1', 'artikel' => 'art-15-inzage', 'status' => 'ingediend'],
			],
		];

		$this->step->run($this->createStub(IOutput::class));
		self::assertCount(1, $this->savedCases);

		// Re-run: the source now carries migratedTo (reflected into the store).
		$this->savedCases = [];
		$this->step->run($this->createStub(IOutput::class));
		self::assertCount(0, $this->savedCases);
	}//end testIdempotentRerun()

	/**
	 * An unprovisioned install is a no-op.
	 *
	 * @return void
	 */
	public function testUnprovisionedNoOp(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objectService);

		$step = new MigrateAvgVerzoekenToOrDsar($appConfig, $container, new NullLogger());
		$step->run($this->createStub(IOutput::class));

		self::assertCount(0, $this->savedCases);
	}//end testUnprovisionedNoOp()
}//end class
