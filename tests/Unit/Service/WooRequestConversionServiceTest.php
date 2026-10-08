<?php

/**
 * An employee turns a question about a dossier into a Woo request.
 *
 * dossiq owns the one creation path (hydra woo-citizen-journey C5); pipelinq
 * calls `OCA\Dossiq\Woo\WooRequestIntake::start()` duck-typed. These tests
 * stand a fake intake in for dossiq and check the request array C5 defines,
 * the ticket write afterwards, and that nothing happens without dossiq.
 *
 * @category Tests
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.pipelinq.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\Portal\MainRegisterReader;
use OCA\Pipelinq\Service\WooRequestConversionService;
use OCA\Pipelinq\Tests\Unit\Settings\DossierQuestionSchemaTest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A stand-in for dossiq's WooRequestIntake (C5 signature).
 */
class FakeWooRequestIntake {
	/** @var array<int, array<string, mixed>> */
	public array $requests = [];

	/** @var \Throwable|null */
	public ?\Throwable $failure = null;

	/**
	 * Record the request and answer like dossiq does.
	 *
	 * @param array<string, mixed> $request The C5 request.
	 *
	 * @return array{caseId: string, caseUrl: string}
	 */
	public function start(array $request): array {
		if ($this->failure !== null) {
			throw $this->failure;
		}

		$this->requests[] = $request;
		return ['caseId' => 'c0a8f1e2-0000-4000-8000-000000000042', 'caseUrl' => '/index.php/apps/dossiq/#/cases/c0a8f1e2-0000-4000-8000-000000000042'];
	}//end start()
}//end class

/**
 * Tests for WooRequestConversionService.
 *
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
 */
class WooRequestConversionServiceTest extends TestCase {

	private FakeWooRequestIntake $intake;

	/** @var array<int, array<string, mixed>> */
	private array $saves = [];

	protected function setUp(): void {
		parent::setUp();
		$this->intake = new FakeWooRequestIntake();
		$this->saves = [];
	}//end setUp()

	/**
	 * The service, with dossiq installed or not.
	 *
	 * @param bool $withDossiq Whether the intake class resolves.
	 * @param bool $saveFails  Whether the ticket write fails.
	 *
	 * @return WooRequestConversionService
	 */
	private function service(bool $withDossiq = true, bool $saveFails = false): WooRequestConversionService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => ($id === FakeWooRequestIntake::class) ? $this->intake : throw new RuntimeException('unknown service')
		);

		$tickets = $this->createMock(MainRegisterReader::class);
		$tickets->method('save')->willReturnCallback(
			function (string $schemaKey, array $data, ?string $id = null) use ($saveFails): array {
				if ($saveFails === true) {
					throw new RuntimeException('Failed to persist object.');
				}

				$this->saves[] = ['schemaKey' => $schemaKey, 'data' => $data, 'id' => $id];
				return $data;
			}
		);

		return new WooRequestConversionService(
			container: $container,
			tickets: $tickets,
			logger: $this->createMock(LoggerInterface::class),
			intakeClass: ($withDossiq === true) ? FakeWooRequestIntake::class : 'OCA\\Dossiq\\Woo\\NotInstalledIntake'
		);
	}//end service()

	/**
	 * A question asked from a dossier, being handled.
	 *
	 * @return array<string, mixed>
	 */
	private function question(): array {
		return DossierQuestionSchemaTest::questionTicket() + ['id' => 't-9'];
	}//end question()

	/**
	 * Scenario: An employee converts a question.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
	 */
	public function testAnEmployeeConvertsAQuestion(): void {
		$result = $this->service()->convert(ticketId: 't-9', ticket: $this->question());

		$this->assertSame(
			[
				[
					'subjectRef' => 'subj-7f3a',
					'collectionId' => '5b0e8c55-1f7e-4a53-9a0e-2f1f0c1d9a11',
					'onderwerp' => 'Windpark Noord',
					'omschrijving' => 'Wanneer valt het besluit over de vergunning?',
					'periodeVan' => null,
					'periodeTot' => null,
					'origin' => 'pipelinq',
					'originReference' => 't-9',
				],
			],
			$this->intake->requests,
			'the C5 request: same resident, same dossier, origin pipelinq'
		);

		$this->assertSame('converted', $result['status']);
		$this->assertSame('c0a8f1e2-0000-4000-8000-000000000042', $result['caseReference']);
		$this->assertCount(1, $this->saves);
		$this->assertSame('t-9', $this->saves[0]['id']);
		$this->assertSame('converted', $this->saves[0]['data']['status']);
		$this->assertSame('c0a8f1e2-0000-4000-8000-000000000042', $this->saves[0]['data']['caseReference']);
		$this->assertSame('request', $this->saves[0]['data']['ticketType']);
		$this->assertSame($this->question()['subjectReference'], $this->saves[0]['data']['subjectReference'], 'the snapshot stays');
	}//end testAnEmployeeConvertsAQuestion()

	/**
	 * Availability: offered for a question with dossiq present.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
	 */
	public function testAQuestionCanBeConvertedWhenDossiqIsThere(): void {
		$this->assertSame(
			['available' => true, 'canConvert' => true, 'status' => 'new', 'caseReference' => ''],
			$this->service()->availability(ticket: $this->question())
		);
	}//end testAQuestionCanBeConvertedWhenDossiqIsThere()

	/**
	 * Scenario: dossiq is not installed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
	 */
	public function testWithoutDossiqNothingIsOfferedOrWritten(): void {
		$service = $this->service(withDossiq: false);

		$this->assertSame(
			['available' => false, 'canConvert' => false, 'status' => 'new', 'caseReference' => ''],
			$service->availability(ticket: $this->question())
		);
		$this->assertSame(['status' => 'not-available'], $service->convert(ticketId: 't-9', ticket: $this->question()));
		$this->assertSame([], $this->saves);
	}//end testWithoutDossiqNothingIsOfferedOrWritten()

	/**
	 * A ticket without a dossier, or one already converted, is not convertible.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
	 */
	public function testOnlyAnUnconvertedQuestionAboutADossierConverts(): void {
		$ordinary = $this->question();
		unset($ordinary['subjectReference']);
		$converted = $this->question();
		$converted['status'] = 'converted';
		$converted['caseReference'] = 'c-1';

		foreach ([$ordinary, $converted] as $ticket) {
			$this->assertFalse($this->service()->availability(ticket: $ticket)['canConvert']);
			$this->assertSame(['status' => 'not-convertible'], $this->service()->convert(ticketId: 't-9', ticket: $ticket));
		}

		$this->assertSame([], $this->intake->requests);
		$this->assertSame([], $this->saves);
	}//end testOnlyAnUnconvertedQuestionAboutADossierConverts()

	/**
	 * A refusal by dossiq changes nothing on the ticket.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
	 */
	public function testARefusalByDossiqChangesNothing(): void {
		$this->intake->failure = new RuntimeException('collection not owned by subject');

		$this->assertSame(['status' => 'intake-failed'], $this->service()->convert(ticketId: 't-9', ticket: $this->question()));
		$this->assertSame([], $this->saves);
	}//end testARefusalByDossiqChangesNothing()

	/**
	 * When the case exists but the ticket write fails, the answer says so.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-turns-a-question-into-a-woo-request-req-qcd-008
	 */
	public function testACaseWithAnUnsavedTicketIsReported(): void {
		$result = $this->service(saveFails: true)->convert(ticketId: 't-9', ticket: $this->question());

		$this->assertSame('converted-unsynced', $result['status']);
		$this->assertSame('c0a8f1e2-0000-4000-8000-000000000042', $result['caseReference']);
	}//end testACaseWithAnUnsavedTicketIsReported()
}//end class
