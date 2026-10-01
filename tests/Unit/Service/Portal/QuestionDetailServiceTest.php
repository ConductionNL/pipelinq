<?php

/**
 * Unit tests for QuestionDetailService.
 *
 * What a resident reads on the detail of their question about a Woo dossier:
 * the conversation with its moments, the dossier as it was, and the Woo
 * request it became.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/question-detail-on-the-portal/specs/dossier-questions/spec.md#requirement-a-resident-reads-their-question-the-dossier-it-was-about-and-the-answers-req-qdp-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use OCA\Pipelinq\Service\Portal\MainRegisterReader;
use OCA\Pipelinq\Service\Portal\QuestionDetailService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Pin the question timeline and item list.
 *
 * @spec openspec/changes/question-detail-on-the-portal/specs/dossier-questions/spec.md#requirement-a-resident-reads-their-question-the-dossier-it-was-about-and-the-answers-req-qdp-001
 */
final class QuestionDetailServiceTest extends TestCase {
	/**
	 * A question as DossierQuestionService files it, answered twice and
	 * replied to once.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private function question(array $overrides = []): array {
		return array_merge(
			[
				'@self' => ['id' => 't-9', 'updated' => '2026-09-30T12:00:00+00:00'],
				'ticketType' => 'request',
				'title' => 'Windpark Noord',
				'description' => 'Wanneer valt het besluit?',
				'status' => 'awaiting_customer',
				'occurredAt' => '2026-09-27T09:00:00+00:00',
				'portalSubject' => 'subj-7f3a',
				'customerMessage' => 'In november.',
				'portalAnswers' => [
					['message' => 'We zoeken het uit.', 'createdAt' => '2026-09-28T10:00:00+00:00'],
					['message' => 'In november.', 'createdAt' => '2026-09-29T10:00:00+00:00'],
				],
				'portalReplies' => [
					['message' => 'Dank u.', 'createdAt' => '2026-09-30T08:00:00+00:00'],
				],
				'notes' => 'intern',
				'subjectReference' => [
					'type' => 'opencatalogi.collection',
					'id' => 'd-1',
					'title' => 'Windpark Noord',
					'items' => [
						['title' => 'Besluit omgevingsvergunning', 'url' => 'https://gemeente.example/index.php/apps/opencatalogi/api/search/p-1'],
						['title' => 'Mijn notitie', 'url' => ''],
					],
				],
			],
			$overrides
		);
	}//end question()

	/**
	 * The service over one stored ticket, translating as the source text.
	 *
	 * @param array<string, mixed>|null $ticket The ticket `find` answers.
	 *
	 * @return QuestionDetailService
	 */
	private function service(?array $ticket): QuestionDetailService {
		$reader = $this->createMock(MainRegisterReader::class);
		$reader->method('find')->with('ticket', 't-9')->willReturn($ticket);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new QuestionDetailService($reader, $l10n);
	}//end service()

	/**
	 * The question, both answers and the reply, each at its own moment.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/question-detail-on-the-portal/specs/dossier-questions/spec.md#requirement-a-resident-reads-their-question-the-dossier-it-was-about-and-the-answers-req-qdp-001
	 */
	public function testTheTimelineHoldsTheQuestionEveryAnswerAndTheReplies(): void {
		$entries = $this->service(ticket: $this->question())->timeline(ticketId: 't-9');

		$this->assertSame(
			[
				['id' => 'question', 'occurredAt' => '2026-09-27T09:00:00+00:00', 'message' => 'You asked: Wanneer valt het besluit?'],
				['id' => 'answer-0', 'occurredAt' => '2026-09-28T10:00:00+00:00', 'message' => 'Answer: We zoeken het uit.'],
				['id' => 'answer-1', 'occurredAt' => '2026-09-29T10:00:00+00:00', 'message' => 'Answer: In november.'],
				['id' => 'reply-0', 'occurredAt' => '2026-09-30T08:00:00+00:00', 'message' => 'Your reply: Dank u.'],
			],
			$entries
		);
		$this->assertStringNotContainsString('intern', json_encode($entries));
	}//end testTheTimelineHoldsTheQuestionEveryAnswerAndTheReplies()

	/**
	 * A question answered before `portalAnswers` existed still shows its
	 * answer, at the ticket's last change.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/question-detail-on-the-portal/specs/dossier-questions/spec.md#requirement-a-resident-reads-their-question-the-dossier-it-was-about-and-the-answers-req-qdp-001
	 */
	public function testAnAnswerWithoutHistoryShowsAtTheLastChange(): void {
		$entries = $this->service(ticket: $this->question(['portalAnswers' => null, 'portalReplies' => []]))->timeline(ticketId: 't-9');

		$this->assertSame(['id' => 'answer', 'occurredAt' => '2026-09-30T12:00:00+00:00', 'message' => 'Answer: In november.'], $entries[1]);
		$this->assertCount(2, $entries);
	}//end testAnAnswerWithoutHistoryShowsAtTheLastChange()

	/**
	 * A converted question says so and leads its item list with the Woo
	 * request, linked to the resident's case on the portal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/question-detail-on-the-portal/specs/dossier-questions/spec.md#requirement-a-converted-question-links-to-the-woo-request-req-qdp-002
	 */
	public function testAConvertedQuestionLinksToTheWooRequest(): void {
		$service = $this->service(ticket: $this->question(['status' => 'converted', 'caseReference' => 'case-42']));

		$timeline = $service->timeline(ticketId: 't-9');
		$this->assertSame('Your question is now a Woo request.', end($timeline)['message']);

		$items = $service->dossierItems(ticketId: 't-9');
		$this->assertSame('case-42', $items[0]['id']);
		$this->assertSame('Your Woo request', $items[0]['title']);
		$this->assertSame('/index.php/apps/portaliq/site#open=dossiq/mijnZaken/case-42', $items[0]['url']);
		$this->assertSame('Besluit omgevingsvergunning', $items[1]['title']);
	}//end testAConvertedQuestionLinksToTheWooRequest()

	/**
	 * The dossier as it was asked about: every document with its link, and no
	 * Woo request while the question is not converted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/question-detail-on-the-portal/specs/dossier-questions/spec.md#requirement-a-resident-reads-their-question-the-dossier-it-was-about-and-the-answers-req-qdp-001
	 */
	public function testTheItemsAreTheDossierAsItWas(): void {
		$items = $this->service(ticket: $this->question(['caseReference' => 'case-42']))->dossierItems(ticketId: 't-9');

		$this->assertSame(
			[
				['id' => '', 'title' => 'Besluit omgevingsvergunning', 'url' => 'https://gemeente.example/index.php/apps/opencatalogi/api/search/p-1', 'note' => ''],
				['id' => '', 'title' => 'Mijn notitie', 'url' => '', 'note' => ''],
			],
			$items
		);
	}//end testTheItemsAreTheDossierAsItWas()

	/**
	 * A ticket that is not a portal question, or none at all, answers nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/question-detail-on-the-portal/specs/dossier-questions/spec.md#requirement-a-resident-reads-their-question-the-dossier-it-was-about-and-the-answers-req-qdp-001
	 */
	public function testNotAPortalQuestionAnswersNothing(): void {
		foreach ([null, $this->question(['subjectReference' => null]), $this->question(['portalSubject' => ''])] as $ticket) {
			$service = $this->service(ticket: $ticket);
			$this->assertSame([], $service->timeline(ticketId: 't-9'));
			$this->assertSame([], $service->dossierItems(ticketId: 't-9'));
		}
	}//end testNotAPortalQuestionAnswersNothing()
}//end class
