<?php

/**
 * Pipelinq Question Answered Notice
 *
 * Writes the message a resident gets when a KCC employee answers the question
 * they asked about their dossier (hydra woo-citizen-journey C3): one message in
 * portaliq's inbox that says the question was answered and links to it on the
 * site, with pipelinq's rule key `pipelinq.question.answered`, so portaliq also
 * sends the e-mail by the resident's preferences. Its record link opens the
 * question.
 *
 * It replaces a portaliq change rule on `customerMessage`, which could only say
 * "<question> is bijgewerkt". The same pattern as opencatalogi's
 * SavedSearchNoticeWriter (opencatalogi#1720).
 *
 * The message is Dutch: a Woo portal speaks Dutch, and pipelinq does not know
 * the resident's language. It is never Dutch and English in one string.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-questions-in-dutch/specs/dossier-questions/spec.md#requirement-the-answer-notice-says-the-question-was-answered
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Portal\PortalContributionProvider;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tells the resident their question was answered.
 *
 * @spec openspec/changes/portal-questions-in-dutch/specs/dossier-questions/spec.md#requirement-the-answer-notice-says-the-question-was-answered
 */
class QuestionAnsweredNotice {

	/**
	 * The language of the message.
	 */
	private const LANGUAGE = 'nl';

	/**
	 * The register of the resident's portal inbox (portaliq's own).
	 */
	public const PORTAL_MESSAGE_REGISTER = 'portaliq';

	/**
	 * The schema of one message in the resident's portal inbox.
	 */
	public const PORTAL_MESSAGE_SCHEMA = 'portalMessage';

	/**
	 * Portaliq's site. A link with `#open=<app>/<collection>/<id>` opens the
	 * page that shows that record, after the sign-in if needed.
	 */
	public const SITE = '/index.php/apps/portaliq/site';

	/**
	 * The portal collection that shows the resident's questions.
	 */
	public const COLLECTION = 'myQuestions';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService Writes the message as the system.
	 * @param IURLGenerator          $urlGenerator  Makes the link absolute, host and port included.
	 * @param IFactory               $l10nFactory   The Dutch text, whatever the request's language.
	 * @param LoggerInterface        $logger        Records a message that could not be written.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IFactory $l10nFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this save answered a resident's portal question.
	 *
	 * A question is a request ticket on the portal channel that carries the
	 * resident's subject reference (PortalContributionProvider `myQuestions`).
	 * It is answered when `customerMessage` changed to a text.
	 *
	 * @param array<string, mixed> $before The ticket before the save.
	 * @param array<string, mixed> $after  The ticket after the save.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-questions-in-dutch/specs/dossier-questions/spec.md#requirement-the-answer-notice-says-the-question-was-answered
	 */
	public function isAnswered(array $before, array $after): bool {
		$answer = trim((string)($after['customerMessage'] ?? ''));

		return ($after['ticketType'] ?? null) === 'request'
			&& ($after['channel'] ?? null) === 'portal'
			&& trim((string)($after['portalSubject'] ?? '')) !== ''
			&& $answer !== ''
			&& $answer !== trim((string)($before['customerMessage'] ?? ''));
	}//end isAnswered()

	/**
	 * Write the message for one answered question.
	 *
	 * Never throws: a message that cannot be written costs the notice, not
	 * the answer the employee saved.
	 *
	 * @param array<string, mixed> $ticket   The question, as saved.
	 * @param string               $ticketId The ticket uuid.
	 *
	 * @return bool Whether a message was written.
	 *
	 * @spec openspec/changes/portal-questions-in-dutch/specs/dossier-questions/spec.md#requirement-the-answer-notice-says-the-question-was-answered
	 */
	public function tell(array $ticket, string $ticketId): bool {
		$subjectRef = trim((string)($ticket['portalSubject'] ?? ''));
		if ($subjectRef === '' || $ticketId === '') {
			return false;
		}

		try {
			$l10n = $this->l10nFactory->get(Application::APP_ID, self::LANGUAGE);
			$link = $this->urlGenerator->getAbsoluteURL(self::SITE)
				.'#open='.Application::APP_ID.'/'.self::COLLECTION.'/'.rawurlencode($ticketId);

			$message = [
				'subjectRef' => $subjectRef,
				'subject' => $l10n->t('Your question has been answered'),
				'body' => $l10n->t('There is an answer to your question "%1$s".', [(string)($ticket['title'] ?? '')])
					."\n\n".$l10n->t('Read the answer here: %1$s', [$link]),
				'read' => false,
				'receivedAt' => gmdate('c'),
				'ruleKey' => PortalContributionProvider::RULE_QUESTION_ANSWERED,
				'recordLink' => ['app' => Application::APP_ID, 'collection' => self::COLLECTION, 'id' => $ticketId],
			];

			$this->objectService->runAsSystem(
				fn (): mixed => $this->objectService->saveObject(
					object: $message,
					register: self::PORTAL_MESSAGE_REGISTER,
					schema: self::PORTAL_MESSAGE_SCHEMA,
					_rbac: false,
					_multitenancy: false
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'QuestionAnsweredNotice: the resident could not be told their question was answered',
				['app' => Application::APP_ID, 'ticket' => $ticketId, 'error' => $e->getMessage()]
			);
			return false;
		}//end try

		return true;
	}//end tell()
}//end class
