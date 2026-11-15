<?php

/**
 * Pipelinq TemplateInUseGuard.
 *
 * Refuses deleting a mail template that a scheduled mailing still uses, from
 * any screen, naming the mailing that uses it.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-mail-templates/specs/marketing-mail-templates/spec.md#REQ-MMT-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\Pipelinq\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Vetoes the delete of a template a scheduled mailing references.
 *
 * Listens on OpenRegister's stoppable pre-delete event, which the soft and the
 * permanent delete both dispatch, so the rule holds for the Templates page, the
 * API and any other writer alike.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/marketing-mail-templates/specs/marketing-mail-templates/spec.md#REQ-MMT-001
 */
class TemplateInUseGuard implements IEventListener {

	/**
	 * The mailing status that blocks a template delete.
	 *
	 * @var string
	 */
	public const BLOCKING_STATUS = 'scheduled';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param IAppConfig             $appConfig     App config (register and schema ids).
	 * @param IL10N                  $l10n          Translator for the refusal.
	 * @param LoggerInterface        $logger        Logger.
	 */
	public function __construct(
		private ObjectServiceInterface $objectService,
		private IAppConfig $appConfig,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse the delete when the object is a template a scheduled mailing uses.
	 *
	 * @param Event $event The pre-delete event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-mail-templates/specs/marketing-mail-templates/spec.md#REQ-MMT-001
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectDeletingEvent) === false) {
			return;
		}

		$template = $event->getObject();
		if ($this->isTemplate(object: $template) === false) {
			return;
		}

		$mailing = $this->scheduledMailingUsing(templateId: (string)$template->getUuid());
		if ($mailing === null) {
			return;
		}

		$event->setErrors(
			[
				'message' => $this->l10n->t('This template is used by the scheduled mailing %s', [$mailing]),
			]
		);
		$event->stopPropagation();
	}//end handle()

	/**
	 * Whether the object is a campaign template.
	 *
	 * @param ObjectEntity $object The object about to be deleted.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/marketing-mail-templates/specs/marketing-mail-templates/spec.md#REQ-MMT-001
	 */
	private function isTemplate(ObjectEntity $object): bool {
		$schema = (string)$object->getSchema();
		$configured = $this->appConfig->getValueString(Application::APP_ID, 'campaignTemplate_schema', '');

		return $schema !== '' && $configured !== '' && $schema === $configured;
	}//end isTemplate()

	/**
	 * The name of a scheduled mailing that references the template, if any.
	 *
	 * @param string $templateId The template UUID.
	 *
	 * @return string|null The mailing's name, a placeholder when the mailings
	 *                     cannot be read, or null when none uses it.
	 *
	 * @spec openspec/changes/marketing-mail-templates/specs/marketing-mail-templates/spec.md#REQ-MMT-001
	 */
	private function scheduledMailingUsing(string $templateId): ?string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'blast_schema', '');
		if ($templateId === '' || $register === '' || $schema === '') {
			return null;
		}

		try {
			$rows = $this->objectService->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'templateId' => $templateId,
						'status' => self::BLOCKING_STATUS,
					],
					'limit' => 10,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			// Unreadable mailings: refuse rather than risk deleting a template a
			// scheduled send still needs.
			$this->logger->warning('TemplateInUseGuard: mailings not readable', ['template' => $templateId, 'exception' => $e->getMessage()]);
			return $this->l10n->t('(the mailings could not be checked)');
		}

		foreach ($rows as $row) {
			$data = $row;
			if ($row instanceof ObjectEntity) {
				$data = (array)$row->getObject();
			}

			if (is_array($data) === false) {
				continue;
			}

			if ((string)($data['templateId'] ?? '') === $templateId && (string)($data['status'] ?? '') === self::BLOCKING_STATUS) {
				return (string)($data['name'] ?? '');
			}
		}

		return null;
	}//end scheduledMailingUsing()
}//end class
