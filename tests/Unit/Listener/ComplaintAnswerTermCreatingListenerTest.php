<?php

/**
 * Unit tests for ComplaintAnswerTermCreatingListener.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/specify-capability-rows/specs/klachtenregistratie/spec.md#requirement-a-complaint-is-held-to-the-legal-answer-term-by-default
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\Pipelinq\Listener\ComplaintAnswerTermCreatingListener;
use OCA\Pipelinq\Service\ComplaintSlaService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ComplaintAnswerTermCreatingListener.
 */
class ComplaintAnswerTermCreatingListenerTest extends TestCase {

	/**
	 * The listener over ticket schema "40", with the given category terms (hours).
	 *
	 * @param array<string, string> $terms App config `complaint_sla_<category>` values.
	 *
	 * @return ComplaintAnswerTermCreatingListener
	 */
	private function listener(array $terms=[]): ComplaintAnswerTermCreatingListener {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => ((['ticket_schema' => '40'] + $terms)[$key] ?? $default)
		);

		return new ComplaintAnswerTermCreatingListener(
			appConfig: $config,
			complaintSla: new ComplaintSlaService(appConfig: $config, logger: $this->createMock(originalClassName: LoggerInterface::class)),
		);
	}//end listener()

	/**
	 * A creating event for an object of the given schema and data.
	 *
	 * @param string               $schema The schema id.
	 * @param array<string, mixed> $data   The object data.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function event(string $schema, array $data): ObjectCreatingEvent {
		$entity = new ObjectEntity();
		$entity->setUuid('t-1');
		$entity->setSchema($schema);
		$entity->setObject($data);

		return new ObjectCreatingEvent($entity);
	}//end event()

	/**
	 * A complaint in a category without a term of its own answers six weeks after receipt.
	 *
	 * @return void
	 */
	public function testAComplaintWithoutACategoryTermGetsSixWeeks(): void {
		$event = $this->event(schema: '40', data: ['ticketType' => 'complaint', 'complaintCategory' => 'service', 'occurredAt' => '2026-11-02T09:00:00+01:00']);

		$this->listener()->handle($event);

		$this->assertSame(expected: '2026-12-14T09:00:00+01:00', actual: $event->getModifiedData()['slaDeadline']);
	}//end testAComplaintWithoutACategoryTermGetsSixWeeks()

	/**
	 * A shorter category term wins; a longer one does not stretch the legal term.
	 *
	 * @return void
	 */
	public function testAShorterCategoryTermWinsAndALongerOneDoesNot(): void {
		$short = $this->event(schema: '40', data: ['ticketType' => 'complaint', 'complaintCategory' => 'billing', 'occurredAt' => '2026-11-02T09:00:00+01:00']);
		$this->listener(terms: ['complaint_sla_billing' => '48'])->handle($short);
		$this->assertSame(expected: '2026-11-04T09:00:00+01:00', actual: $short->getModifiedData()['slaDeadline']);

		$long = $this->event(schema: '40', data: ['ticketType' => 'complaint', 'complaintCategory' => 'billing', 'occurredAt' => '2026-11-02T09:00:00+01:00']);
		$this->listener(terms: ['complaint_sla_billing' => '2000'])->handle($long);
		$this->assertSame(expected: '2026-12-14T09:00:00+01:00', actual: $long->getModifiedData()['slaDeadline']);
	}//end testAShorterCategoryTermWinsAndALongerOneDoesNot()

	/**
	 * A complaint that names its deadline keeps it, and an earlier hook's change stays.
	 *
	 * @return void
	 */
	public function testAGivenDeadlineIsKeptAndOtherChangesStay(): void {
		$given = $this->event(schema: '40', data: ['ticketType' => 'complaint', 'slaDeadline' => '2026-11-20T00:00:00+00:00']);
		$this->listener()->handle($given);
		$this->assertSame(expected: [], actual: $given->getModifiedData());

		$merged = $this->event(schema: '40', data: ['ticketType' => 'complaint']);
		$merged->setModifiedData(['reference' => 'PQ-2026-0001']);
		$this->listener()->handle($merged);
		$this->assertSame(expected: 'PQ-2026-0001', actual: $merged->getModifiedData()['reference']);
		$this->assertArrayHasKey(key: 'slaDeadline', array: $merged->getModifiedData());
	}//end testAGivenDeadlineIsKeptAndOtherChangesStay()

	/**
	 * Other tickets and other schemas are left alone.
	 *
	 * @return void
	 */
	public function testOnlyComplaintsAreStamped(): void {
		$request = $this->event(schema: '40', data: ['ticketType' => 'request']);
		$this->listener()->handle($request);
		$this->assertSame(expected: [], actual: $request->getModifiedData());

		$other = $this->event(schema: '41', data: ['ticketType' => 'complaint']);
		$this->listener()->handle($other);
		$this->assertSame(expected: [], actual: $other->getModifiedData());
	}//end testOnlyComplaintsAreStamped()
}//end class
