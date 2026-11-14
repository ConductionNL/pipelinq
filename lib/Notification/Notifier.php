<?php

/**
 * Pipelinq Notifier.
 *
 * Notifier for preparing Pipelinq notification messages.
 *
 * @category Notification
 * @package  OCA\Pipelinq\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/notifications-activity/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Notification;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Notifier for Pipelinq notifications.
 *
 * @spec openspec/specs/notifications-activity/spec.md
 */
class Notifier implements INotifier {
	/**
	 * The handler group of every subject this notifier renders.
	 *
	 * @var array<string, string>
	 */
	private const SUBJECT_GROUPS = [
		'lead_assigned' => 'assignment',
		'request_assigned' => 'assignment',
		'task_assigned' => 'assignment',
		'task_reassigned' => 'assignment',
		'lead_lost' => 'assignment',
		'task_completed' => 'task',
		'task_expired' => 'task',
		'followup_event_upcoming' => 'reminder',
		'followup_task_due' => 'reminder',
		'lead_stage_changed' => 'change',
		'request_status_changed' => 'change',
		'note_added' => 'change',
		'lead_won' => 'change',
		'forecast_snapshot_partial_failure' => 'system',
		'wip_sync_failed' => 'system',
		'billing_handoff_failed' => 'system',
		'export_job_switched_off' => 'system',
		'export_mail_delivery_failed' => 'system',
		'social_share_requested' => 'social',
		'social_relink_needed' => 'social',
		'full_export_ready' => 'export',
		'full_export_failed' => 'export',
	];

	/**
	 * The route path of an object type whose page is not at `#/<type>s/<id>`.
	 *
	 * @var array<string, string>
	 */
	private const OBJECT_PATHS = [
		'exportJob' => 'export/jobs',
	];

	/**
	 * Constructor.
	 *
	 * @param IFactory $l10nFactory The l10n factory.
	 * @param IURLGenerator $urlGenerator The URL generator.
	 */
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * Get the notifier ID.
	 *
	 * @return string The notifier ID.
	 *
	 * @spec openspec/specs/notifications-activity/spec.md
	 */
	public function getID(): string {
		return AppIdentity::APP_ID;
	}//end getID()

	/**
	 * Get the notifier name.
	 *
	 * @return string The notifier name.
	 *
	 * @spec openspec/specs/notifications-activity/spec.md
	 */
	public function getName(): string {
		return $this->l10nFactory->get(AppIdentity::APP_ID)->t('Pipelinq');
	}//end getName()

	/**
	 * Prepare a notification for display.
	 *
	 * @param INotification $notification The notification to prepare.
	 * @param string $languageCode The language code.
	 *
	 * @return INotification The prepared notification.
	 *
	 * @spec openspec/specs/notifications-activity/spec.md
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== AppIdentity::APP_ID) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(AppIdentity::APP_ID, $languageCode);
		$params = $notification->getSubjectParameters();

		$this->applyNotificationSubject(
			notification: $notification,
			l: $l,
			params: $params
		);

		$notification->setIcon(
			$this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->imagePath(appName: AppIdentity::APP_ID, file: 'app-dark.svg')
			)
		);
		$fileId = (string)($params['fileId'] ?? '');
		if ($notification->getSubject() === 'full_export_ready' && $fileId !== '') {
			// The archive itself, opened in Files (data-portability REQ-DPE-002).
			$notification->setLink($this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $fileId]));
			return $notification;
		}

		$baseUrl = $this->urlGenerator->linkToRouteAbsolute('pipelinq.dashboard.page');
		$objectType = $notification->getObjectType();
		$objectPath = '#/' . (self::OBJECT_PATHS[$objectType] ?? $objectType . 's') . '/' . $notification->getObjectId();
		$notification->setLink($baseUrl . $objectPath);

		return $notification;
	}//end prepare()

	/**
	 * Apply the subject text to a notification based on its subject type.
	 *
	 * @param INotification $notification The notification.
	 * @param object $l The l10n translator.
	 * @param array $params The subject parameters.
	 *
	 * @return void
	 *
	 * @throws UnknownNotificationException If the subject is not recognized.
	 */
	private function applyNotificationSubject(INotification $notification, object $l, array $params): void {
		$title = $params['title'] ?? '';
		$context = [
			'l' => $l,
			'params' => $params,
			'title' => $title,
			'richParams' => $this->buildRichParams(notification: $notification, title: $title),
		];

		match (self::SUBJECT_GROUPS[$notification->getSubject()] ?? null) {
			'assignment' => $this->applyAssignmentSubject(notification: $notification, context: $context),
			'task' => $this->applyTaskSubject(notification: $notification, context: $context),
			'reminder' => $this->applyReminderSubject(notification: $notification, context: $context),
			'change' => $this->applyChangeSubject(notification: $notification, context: $context),
			'system' => $this->applySystemSubject(notification: $notification, context: $context),
			'social' => $this->applySocialSubject(notification: $notification, context: $context),
			'export' => $this->applyExportSubject(notification: $notification, context: $context),
			default => throw new UnknownNotificationException(),
		};
	}//end applyNotificationSubject()

	/**
	 * Build rich parameters for a notification.
	 *
	 * @param INotification $notification The notification.
	 * @param string $title The entity title.
	 *
	 * @return array The rich parameters.
	 */
	private function buildRichParams(INotification $notification, string $title): array {
		return [
			'title' => [
				'type' => 'highlight',
				'id' => $notification->getObjectId(),
				'name' => $title,
			],
		];
	}//end buildRichParams()

	/**
	 * An item assigned, reassigned or lost: the title in both subjects.
	 *
	 * @param INotification $notification The notification.
	 * @param array<string, mixed> $context The translator (`l`), the parameters, the title and the rich parameters.
	 *
	 * @return void
	 */
	private function applyAssignmentSubject(INotification $notification, array $context): void {
		$l = $context['l'];
		$title = $context['title'];
		[$parsed, $rich] = match ($notification->getSubject()) {
			'lead_assigned' => [$l->t('Lead assigned: %s', [$title]), $l->t('Lead assigned: {title}')],
			'request_assigned' => [$l->t('Request assigned: %s', [$title]), $l->t('Request assigned: {title}')],
			'task_assigned' => [$l->t('Task assigned: %s', [$title]), $l->t('Task assigned: {title}')],
			'task_reassigned' => [$l->t('Task reassigned to you: %s', [$title]), $l->t('Task reassigned to you: {title}')],
			default => [$l->t('Deal lost: %s', [$title]), $l->t('Deal lost: {title}')],
		};

		$notification->setParsedSubject($parsed);
		$notification->setRichSubject(subject: $rich, parameters: $context['richParams']);
	}//end applyAssignmentSubject()

	/**
	 * A follow-up reminder: a meeting linked to a lead starts soon, or a follow-up task falls due.
	 *
	 * @param INotification $notification The notification.
	 * @param array<string, mixed> $context The translator (`l`), the parameters, the title and the rich parameters.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/calendar-deepening/tasks.md#3-followupreminderjob
	 */
	private function applyReminderSubject(INotification $notification, array $context): void {
		['l' => $l, 'params' => $params, 'title' => $title] = $context;
		if ($notification->getSubject() === 'followup_event_upcoming') {
			$notification->setParsedSubject(
				$l->t('Upcoming meeting for %1$s: %2$s at %3$s', [$params['lead'] ?? '', $title, $params['start'] ?? ''])
			);
			$notification->setRichSubject(subject: $l->t('Upcoming meeting: {title}'), parameters: $context['richParams']);
			return;
		}

		$notification->setParsedSubject($l->t('Follow-up due: %1$s (deadline: %2$s)', [$title, $params['deadline'] ?? '']));
		$notification->setRichSubject(subject: $l->t('Follow-up due: {title}'), parameters: $context['richParams']);
	}//end applyReminderSubject()

	/**
	 * A task completed (with its result) or expired (with its deadline).
	 *
	 * @param INotification $notification The notification.
	 * @param array<string, mixed> $context The translator (`l`), the parameters, the title and the rich parameters.
	 *
	 * @return void
	 */
	private function applyTaskSubject(INotification $notification, array $context): void {
		['l' => $l, 'params' => $params, 'title' => $title] = $context;
		if ($notification->getSubject() === 'task_completed') {
			$notification->setParsedSubject($l->t('Task completed: %1$s (%2$s)', [$title, $params['resultText'] ?? '']));
			$notification->setRichSubject(subject: $l->t('Task completed: {title}'), parameters: $context['richParams']);
			return;
		}

		$notification->setParsedSubject($l->t('Task expired: %1$s (deadline: %2$s)', [$title, $params['deadline'] ?? '']));
		$notification->setRichSubject(subject: $l->t('Task expired: {title}'), parameters: $context['richParams']);
	}//end applyTaskSubject()

	/**
	 * A lead moved stage or was won, a request changed status, a note was added.
	 *
	 * @param INotification $notification The notification.
	 * @param array<string, mixed> $context The translator (`l`), the parameters, the title and the rich parameters.
	 *
	 * @return void
	 */
	private function applyChangeSubject(INotification $notification, array $context): void {
		['l' => $l, 'params' => $params, 'title' => $title] = $context;
		[$parsed, $rich] = match ($notification->getSubject()) {
			'lead_stage_changed' => [
				$l->t('Lead %1$s moved to %2$s', [$title, $params['stage'] ?? '']),
				$l->t('{title} moved to %1$s', [$params['stage'] ?? '']),
			],
			'request_status_changed' => [
				$l->t('Request %1$s: %2$s', [$title, $params['status'] ?? '']),
				$l->t('{title}: %1$s', [$params['status'] ?? '']),
			],
			'note_added' => [
				$l->t('New note on %1$s: %2$s', [$params['entityType'] ?? 'item', $title]),
				$l->t('New note on %1$s: {title}', [$params['entityType'] ?? 'item']),
			],
			default => [
				$l->t('Deal won: %1$s (EUR %2$s)', [$title, $params['value'] ?? '']),
				$l->t('Deal won: {title} (EUR %1$s)', [$params['value'] ?? '']),
			],
		};

		$notification->setParsedSubject($parsed);
		$notification->setRichSubject(subject: $rich, parameters: $context['richParams']);
	}//end applyChangeSubject()

	/**
	 * A background job reporting a failure: forecast snapshots, WIP sync,
	 * billing handoff, an export job switched off.
	 *
	 * @param INotification $notification The notification.
	 * @param array<string, mixed> $context The translator (`l`), the parameters, the title and the rich parameters.
	 *
	 * @return void
	 */
	private function applySystemSubject(INotification $notification, array $context): void {
		['l' => $l, 'params' => $params, 'title' => $title] = $context;
		$subject = $notification->getSubject();
		if ($subject === 'forecast_snapshot_partial_failure') {
			$notification->setParsedSubject($l->t('Forecast snapshot job: partial failure for %1$s', [(string)($params['period'] ?? '')]));
			$notification->setParsedMessage($l->t('Some forecast snapshots could not be generated: %1$s', [(string)($params['errors'] ?? '')]));
			return;
		}

		if ($subject === 'wip_sync_failed') {
			$title = (string)($params['title'] ?? '');
			$notification->setParsedSubject($l->t('WIP sync failed for time entry %1$s', [$title]));
			$richParams = $this->buildRichParams(notification: $notification, title: $title);
			$notification->setRichSubject($l->t('WIP sync failed for time entry {title}'), $richParams);
			return;
		}

		if ($subject === 'export_job_switched_off') {
			$this->applyExportSwitchOffSubject(notification: $notification, context: $context);
			return;
		}

		if ($subject === 'export_mail_delivery_failed') {
			$this->applyExportMailFailedSubject(notification: $notification, context: $context);
			return;
		}

		$batchId = (string)($params['batchId'] ?? '');
		$notification->setParsedSubject($l->t('Billing handoff failed for %1$s', [$title]));
		$notification->setParsedMessage(
			$l->t('The shillinq time-intake batch %1$s could not be delivered. Re-send it from the client\'s billing section.', [$batchId])
		);
		$notification->setRichSubject($l->t('Billing handoff failed for {title}'), $context['richParams']);
	}//end applySystemSubject()

	/**
	 * An export job switched off after repeated failed runs: the job, the
	 * number of failures and the last error.
	 *
	 * @param INotification $notification The notification.
	 * @param array<string, mixed> $context The translator (`l`), the parameters, the title and the rich parameters.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-administrators-are-told-once-when-a-job-is-switched-off-req-efs-002
	 */
	private function applyExportSwitchOffSubject(INotification $notification, array $context): void {
		['l' => $l, 'params' => $params, 'title' => $title] = $context;
		$failures = (string)(int)($params['failures'] ?? 0);
		$notification->setParsedSubject(
			$l->t('Export job %1$s was switched off after %2$s failed runs in a row', [$title, $failures])
		);
		$notification->setRichSubject(
			$l->t('Export job {title} was switched off after %1$s failed runs in a row', [$failures]),
			$context['richParams']
		);
		$notification->setParsedMessage(
			$l->t(
				'Last error: %1$s. Fix the destination, then switch the job on again on the BI export page.',
				[(string)($params['error'] ?? '')]
			)
		);
	}//end applyExportSwitchOffSubject()

	/**
	 * A mailed report did not reach some of its recipients: the job, how many
	 * and why (reports-compare-periods-and-scheduled-delivery, REQ-RPD-002).
	 *
	 * @param INotification $notification The notification.
	 * @param array<string, mixed> $context The translator (`l`), the parameters, the title and the rich parameters.
	 *
	 * @return void
	 */
	private function applyExportMailFailedSubject(INotification $notification, array $context): void {
		['l' => $l, 'params' => $params, 'title' => $title] = $context;
		$failed = (string)(int)($params['failed'] ?? 0);
		$notification->setParsedSubject(
			$l->t('Report %1$s could not be mailed to %2$s recipients', [$title, $failed])
		);
		$notification->setRichSubject(
			$l->t('Report {title} could not be mailed to %1$s recipients', [$failed]),
			$context['richParams']
		);
		$notification->setParsedMessage(
			$l->t('Reason: %1$s', [(string)($params['error'] ?? '')])
		);
	}//end applyExportMailFailedSubject()

	/**
	 * A social post to share by hand, or an account to reconnect.
	 *
	 * @param INotification $notification The notification.
	 * @param array<string, mixed> $context The translator (`l`), the parameters, the title and the rich parameters.
	 *
	 * @return void
	 */
	private function applySocialSubject(INotification $notification, array $context): void {
		$l = $context['l'];
		$handle = (string)($context['params']['handle'] ?? '');
		if ($notification->getSubject() === 'social_share_requested') {
			$notification->setParsedSubject($l->t('Please share this post on %1$s', [$handle]));
			$notification->setParsedMessage(
				$l->t(
					'The text is ready for you. Copy it, open the network and post it, '
					. 'then confirm in Pipelinq that you did. No application may post to '
					. 'this account for you.'
				)
			);
			return;
		}

		$notification->setParsedSubject($l->t('Reconnect your %1$s account', [$handle]));
		$notification->setParsedMessage(
			$l->t(
				'The connection to this account has ended, so posts to it are not '
				. 'going out. Reconnect it in Social accounts and the scheduled posts '
				. 'go out again.'
			)
		);
	}//end applySocialSubject()
	/**
	 * The full data export: the archive is ready, or it could not be built.
	 *
	 * @param INotification $notification The notification.
	 * @param array<string, mixed> $context The translator (`l`) and the parameters.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-002
	 */
	private function applyExportSubject(INotification $notification, array $context): void {
		$l = $context['l'];
		$name = (string)($context['params']['name'] ?? '');
		if ($notification->getSubject() === 'full_export_ready') {
			$notification->setParsedSubject($l->t('Your data export is ready: %1$s', [$name]));
			$notification->setParsedMessage($l->t('The archive with all Pipelinq data is in your Files.'));
			return;
		}

		$notification->setParsedSubject($l->t('Your data export could not be built'));
		$notification->setParsedMessage($l->t('Nothing was stored. Try again; if it fails again, ask your administrator to check the log.'));
	}//end applyExportSubject()
}//end class
