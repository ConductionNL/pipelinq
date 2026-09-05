<?php

/**
 * Unit tests for JourneyStepRunner.
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
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\ComplianceService;
use OCA\Pipelinq\Service\Marketing\JourneyFlowCompiler;
use OCA\Pipelinq\Service\Marketing\JourneyService;
use OCA\Pipelinq\Service\Marketing\JourneyStepRunner;
use OCA\Pipelinq\Service\Marketing\MailTransportService;
use OCA\Pipelinq\Service\SegmentService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for JourneyStepRunner: the gate first, the action second, and the
 * refusal written down with the contact in it.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
 */
class JourneyStepRunnerTest extends TestCase {

	/**
	 * The store journeys, contacts, templates, blasts and runs live in.
	 *
	 * @var InMemoryListObjectStore
	 */
	private InMemoryListObjectStore $store;

	/**
	 * Set up one mailing journey, one task journey and one contact.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryListObjectStore([
			'journey' => [
				[
					'uuid' => 'journey-mail',
					'name' => 'Win back',
					'action' => [
						'kind' => 'sendMailing',
						'templateId' => 'template-1',
						'intent' => 'promotional',
					],
				],
				[
					'uuid' => 'journey-task',
					'name' => 'Call them',
					'action' => ['kind' => 'createTask', 'taskSubject' => 'Call about the renewal'],
				],
			],
			'contact' => [
				['uuid' => 'contact-1', 'name' => 'Anna', 'email' => 'anna@example.org', 'client' => 'client-1'],
			],
			'campaignTemplate' => [
				['uuid' => 'template-1', 'name' => 'Win back', 'channel' => 'email'],
			],
		]);
	}//end setUp()

	/**
	 * A contact with no lawful basis is refused, nothing is sent, and the
	 * refusal names the contact. Skipping quietly would make a journey with
	 * no consent look exactly like a journey with a small audience.
	 *
	 * @return void
	 */
	public function testRefusesASendToAContactWithoutConsent(): void {
		$transports = $this->createMock(MailTransportService::class);
		$transports->expects($this->never())->method('sendOneDelivery');

		$outcomes = $this->runner(
			gate: ['allowed' => false, 'reason' => ComplianceService::REASON_NO_CONSENT],
			transports: $transports
		)->run(journeyId: 'journey-mail', subject: ['contact' => 'contact-1']);

		$this->assertSame('refused', $outcomes[0]['state']);
		$this->assertSame('no_consent', $outcomes[0]['reason']);
		$this->assertSame('contact-1', $outcomes[0]['contactId']);
	}//end testRefusesASendToAContactWithoutConsent()

	/**
	 * The refusal is written to a journeyRun with the contact and the
	 * reason on it, which is the only place it is ever visible.
	 *
	 * @return void
	 */
	public function testRecordsTheContactAndTheReasonOnTheRun(): void {
		$this->runner(gate: ['allowed' => false, 'reason' => ComplianceService::REASON_SUPPRESSED])
			->run(journeyId: 'journey-mail', subject: ['contact' => 'contact-1'], flowRunUuid: 'run-9');

		$runs = $this->store->findAll('journeyRun');

		$this->assertCount(1, $runs);
		$this->assertSame('contact-1', $runs[0]['contactId']);
		$this->assertSame('client-1', $runs[0]['clientId']);
		$this->assertSame('suppressed_dunning', $runs[0]['reason']);
		$this->assertSame('run-9', $runs[0]['flowRunUuid']);
	}//end testRecordsTheContactAndTheReasonOnTheRun()

	/**
	 * An allowed send goes through the transport and writes a delivery
	 * against a blast the journey owns, so tracking and unsubscribes keep
	 * working on a journey send without a second code path.
	 *
	 * @return void
	 */
	public function testAnAllowedSendWritesADeliveryAgainstTheJourneysBlast(): void {
		$transports = $this->createMock(MailTransportService::class);
		$transports->method('resolveTransport')->willReturn(['uuid' => 'transport-1', 'kind' => 'instance']);
		$transports->method('sendOneDelivery')->willReturn(true);

		$outcomes = $this->runner(gate: ['allowed' => true, 'reason' => ''], transports: $transports)
			->run(journeyId: 'journey-mail', subject: ['contact' => 'contact-1']);

		$this->assertSame('sent', $outcomes[0]['state']);

		$blasts = $this->store->findAll('blast');
		$this->assertCount(1, $blasts);
		$this->assertSame('journey-mail', $blasts[0]['journeyId']);
		// `segmentId` is on the blast schema's required list. An absent key is
		// refused by OpenRegister and the write is dropped without an error.
		$this->assertArrayHasKey('segmentId', $blasts[0]);

		$deliveries = $this->store->findAll('blastDelivery');
		$this->assertSame('anna@example.org', $deliveries[0]['email']);
		$this->assertSame($this->store->idOf($blasts[0]), $deliveries[0]['blastId']);
	}//end testAnAllowedSendWritesADeliveryAgainstTheJourneysBlast()

	/**
	 * A second send reuses the same blast rather than minting one per run.
	 *
	 * @return void
	 */
	public function testASecondSendReusesTheJourneysBlast(): void {
		$transports = $this->createMock(MailTransportService::class);
		$transports->method('resolveTransport')->willReturn(['uuid' => 'transport-1']);
		$transports->method('sendOneDelivery')->willReturn(true);

		$runner = $this->runner(gate: ['allowed' => true, 'reason' => ''], transports: $transports);
		$runner->run(journeyId: 'journey-mail', subject: ['contact' => 'contact-1']);
		$runner->run(journeyId: 'journey-mail', subject: ['contact' => 'contact-1']);

		$this->assertCount(1, $this->store->findAll('blast'));
		$this->assertCount(2, $this->store->findAll('blastDelivery'));
	}//end testASecondSendReusesTheJourneysBlast()

	/**
	 * A task is internal work, not a message to the contact, so no consent
	 * question arises and none is asked.
	 *
	 * @return void
	 */
	public function testATaskStepDoesNotConsultTheConsentGate(): void {
		$compliance = $this->createMock(ComplianceService::class);
		$compliance->expects($this->never())->method('permitsSend');

		$outcomes = $this->runner(gate: ['allowed' => false, 'reason' => 'no_consent'], compliance: $compliance)
			->run(journeyId: 'journey-task', subject: ['contact' => 'contact-1']);

		$this->assertSame('task-created', $outcomes[0]['state']);

		$tasks = $this->store->findAll('task');
		$this->assertSame('Call about the renewal', $tasks[0]['subject']);
		$this->assertSame('client-1', $tasks[0]['clientId']);
	}//end testATaskStepDoesNotConsultTheConsentGate()

	/**
	 * A contract names a customer, not a person, so every contact recorded
	 * at that customer is a candidate and the gate then decides.
	 *
	 * @return void
	 */
	public function testAContractSubjectReachesTheCustomersContacts(): void {
		$outcomes = $this->runner(gate: ['allowed' => false, 'reason' => 'no_consent'])
			->run(journeyId: 'journey-mail', subject: ['clientRef' => 'client-1']);

		$this->assertCount(1, $outcomes);
		$this->assertSame('contact-1', $outcomes[0]['contactId']);
	}//end testAContractSubjectReachesTheCustomersContacts()

	/**
	 * A template that has been deleted fails the step rather than sending
	 * an empty message.
	 *
	 * @return void
	 */
	public function testAMissingTemplateFailsTheStep(): void {
		$this->store->rows['campaignTemplate'] = [];

		$outcomes = $this->runner(gate: ['allowed' => true, 'reason' => ''])
			->run(journeyId: 'journey-mail', subject: ['contact' => 'contact-1']);

		$this->assertSame('failed', $outcomes[0]['state']);
		$this->assertSame('template_missing', $outcomes[0]['reason']);
	}//end testAMissingTemplateFailsTheStep()

	/**
	 * A runner whose gate answers as instructed.
	 *
	 * @param array{allowed: bool, reason: string} $gate What the consent gate answers.
	 * @param MailTransportService|null $transports The transport service.
	 * @param ComplianceService|null $compliance A prepared compliance mock.
	 *
	 * @return JourneyStepRunner The runner.
	 */
	private function runner(array $gate, ?MailTransportService $transports = null, ?ComplianceService $compliance = null): JourneyStepRunner {
		if ($compliance === null) {
			$compliance = $this->createMock(ComplianceService::class);
			$compliance->method('permitsSend')->willReturn($gate);
		}

		if ($transports === null) {
			$transports = $this->createMock(MailTransportService::class);
			$transports->method('resolveTransport')->willReturn(['uuid' => 'transport-1']);
			$transports->method('sendOneDelivery')->willReturn(true);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => throw new RuntimeException('not registered: ' . $id)
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((int)strtotime('2026-09-05 12:00:00'));

		$journeys = new JourneyService(
			$this->store,
			new JourneyFlowCompiler(),
			$container,
			$time,
			$this->createMock(LoggerInterface::class),
		);

		return new JourneyStepRunner(
			$this->store,
			$journeys,
			$compliance,
			$this->createMock(SegmentService::class),
			$transports,
			$time,
			$this->createMock(LoggerInterface::class),
		);
	}//end runner()
}//end class
