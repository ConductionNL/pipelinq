<?php

/**
 * Pipelinq PortalQuestionActions.
 *
 * The question actions Pipelinq contributes to the resident portal.
 *
 * @category Portal
 * @package  OCA\Pipelinq\Portal
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
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Portal;

/**
 * The resident's question actions: asking a question, asking one about a
 * dossier, and replying to an answer.
 *
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
 */
final class PortalQuestionActions {
	/**
	 * The register a question is written to (the provider's own register).
	 *
	 * @var string
	 */
	private const REGISTER = 'pipelinq';

	/**
	 * Asking a question that is not about a dossier, from the contact page.
	 *
	 * @return array<string, mixed> The action declaration.
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	public function ask(): array {
		return [
			'id' => 'askQuestion',
			'type' => 'create',
			'label' => 'Stel een vraag',
			'register' => self::REGISTER,
			'schema' => 'ticket',
			// A question: the same kind and channel `myQuestions` lists.
			'defaults' => ['ticketType' => 'request', 'channel' => 'portal'],
			'scopeField' => 'portalSubject',
			'fields' => ['title', 'description'],
			// The ticket schema mandates neither, and portaliq honours a
			// field config's `required` only for a field the schema
			// mandates, so the action names them here.
			'requiredFields' => ['title', 'description'],
			'fieldConfigs' => [
				'title' => ['label' => 'Onderwerp', 'required' => true, 'placeholder' => 'Waar gaat uw vraag over?'],
				'description' => ['label' => 'Uw vraag', 'required' => true, 'size' => 'full'],
			],
			// The moment it was asked: "Gesteld" on Mijn vragen. Portaliq
			// stamps it when it writes the ticket.
			'createdAtField' => 'occurredAt',
			// The portal confirms receipt by mail, naming the subject only.
			'confirmationMail' => 'contact-confirmation',
			'topicField' => 'title',
			'submitLabel' => 'Vraag versturen',
			'successMessage' => 'Wij hebben uw vraag ontvangen. U vindt hem terug bij Mijn vragen.',
		];
	}//end ask()

	/**
	 * Asking a question about one of the resident's dossiers, on the dossier page.
	 *
	 * @return array<string, mixed> The action declaration.
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	public function askAboutDossier(): array {
		return [
			'id' => 'askAboutDossier',
			'label' => 'Stel een vraag over dit dossier',
			'endpoint' => '/index.php/apps/pipelinq/api/portal/questions',
			'method' => 'POST',
			// Shown on the resident's dossier page (hydra woo-citizen-journey
			// C7): portaliq proves the dossier is the resident's through
			// opencatalogi's own scope and forwards its id as `collectionId`.
			// The receiver checks the owner again.
			'attachTo' => ['app' => 'opencatalogi', 'schema' => 'collection'],
			'rowField' => 'collectionId',
			'fields' => ['question', 'title'],
			// The question is what the form is for, and the receiver refuses
			// one without it. An endpoint action names its required fields
			// here: portaliq honours a field config's `required` only for a
			// field a schema mandates.
			'requiredFields' => ['question'],
			'fieldConfigs' => [
				'title' => ['label' => 'Onderwerp', 'size' => 'large', 'placeholder' => 'Waar gaat uw vraag over?'],
				'question' => [
					'label' => 'Uw vraag',
					'required' => true,
					'size' => 'full',
					'help' => 'De medewerker ziet welke documenten in uw dossier staan. Uw aantekeningen ziet de medewerker niet.',
				],
			],
			'submitLabel' => 'Vraag versturen',
			'successMessage' => 'Uw vraag is verstuurd. U krijgt bericht als er een antwoord is.',
		];
	}//end askAboutDossier()

	/**
	 * Replying to the answer on a question that waits for the resident.
	 *
	 * @return array<string, mixed> The action declaration.
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	public function reply(): array {
		return [
			'id' => 'replyToQuestion',
			'label' => 'Reageren op het antwoord',
			'endpoint' => '/index.php/apps/pipelinq/api/portal/questions/reply',
			'method' => 'POST',
			// ON THE QUESTION'S DETAIL (question-detail-on-the-portal): attached
			// to pipelinq's own `myQuestions` only, so the form asks the reply
			// text on the question the resident opened. Portaliq proves the
			// question is theirs through that collection's scope and stamps its
			// id into `ticket`; the receiver checks the owner again.
			'attachTo' => ['app' => 'pipelinq', 'schema' => 'ticket', 'collection' => 'myQuestions'],
			'rowField' => 'ticket',
			// Offered only while the employee waits for the resident. Portaliq
			// leaves it off any other question and refuses it there with 409.
			'rowWhen' => ['field' => 'status', 'in' => ['awaiting_customer']],
			'fields' => ['ticket', 'message'],
			'requiredFields' => ['message'],
			'fieldConfigs' => [
				'ticket' => ['visible' => false, 'required' => true],
				'message' => ['label' => 'Uw reactie', 'required' => true, 'size' => 'full'],
			],
			'submitLabel' => 'Reactie versturen',
			'successMessage' => 'Uw reactie is verstuurd.',
		];
	}//end reply()
}//end class
