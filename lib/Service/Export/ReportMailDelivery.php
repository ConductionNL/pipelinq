<?php

/**
 * Pipelinq ReportMailDelivery.
 *
 * Mails a formatted export to one Nextcloud user as an attachment, in that
 * user's language, through Nextcloud's own mailer. Generic: any export job
 * whose destination is of type `mail` can use it
 * (reports-compare-periods-and-scheduled-delivery).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Export
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use Psr\Log\LoggerInterface;

/**
 * Sends one export file to one user by mail.
 *
 * @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md
 */
class ReportMailDelivery {
	/**
	 * The outcome of a mail that was handed to the mailer.
	 *
	 * @var string
	 */
	public const DELIVERED = 'delivered';

	/**
	 * The outcome of a mail that could not be sent.
	 *
	 * @var string
	 */
	public const FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param IMailer $mailer Nextcloud's mailer.
	 * @param IUserManager $userManager Resolves the recipient and their address.
	 * @param IFactory $l10nFactory The recipient's language.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IMailer $mailer,
		private IUserManager $userManager,
		private IFactory $l10nFactory,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Mail one export file to one user.
	 *
	 * @param array<string, mixed> $job The export job (its name titles the mail).
	 * @param string $userId The recipient.
	 * @param array{contents: string, filename: string, mimeType: string} $file The formatted export.
	 *
	 * @return array{userId: string, status: string, reason: string} The outcome; a failure says why.
	 *
	 * @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md
	 */
	public function deliver(array $job, string $userId, array $file): array {
		return $this->deliverFiles(job: $job, userId: $userId, files: [$file]);
	}//end deliver()

	/**
	 * Mail the export files of one job to one user, all attached to one mail.
	 *
	 * @param array<string, mixed> $job The export job (its name titles the mail).
	 * @param string $userId The recipient.
	 * @param list<array{contents: string, filename: string, mimeType: string}> $files The formatted exports.
	 *
	 * @return array{userId: string, status: string, reason: string} The outcome; a failure says why.
	 *
	 * @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md#requirement-a-scheduled-report-can-be-delivered-by-mail-req-rpd-002
	 */
	public function deliverFiles(array $job, string $userId, array $files): array {
		$user = $this->userManager->get($userId);
		$problem = self::recipientProblem(user: $user);
		if ($problem !== '') {
			return self::outcome(userId: $userId, status: self::FAILED, reason: $problem);
		}

		$l = $this->l10nFactory->get(AppIdentity::APP_ID, $this->l10nFactory->getUserLanguage($user));
		$name = (string)($job['name'] ?? '');

		try {
			$mail = [
				'subject' => $l->t('Report: %1$s', [$name]),
				'body' => $l->t(
					'Attached is the report %1$s, as scheduled. You receive it because you are a recipient of this report in Pipelinq.',
					[$name]
				),
				'files' => $files,
			];
			$refused = $this->mailer->send($this->composedMail(user: $user, mail: $mail));
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: a report mail could not be sent',
				['userId' => $userId, 'job' => $name, 'exception' => $e->getMessage()]
			);
			return self::outcome(userId: $userId, status: self::FAILED, reason: $e->getMessage());
		}//end try

		// The mailer answers the addresses it could not deliver to.
		if ($refused !== []) {
			return self::outcome(userId: $userId, status: self::FAILED, reason: 'The mail server refused the recipient.');
		}

		return self::outcome(userId: $userId, status: self::DELIVERED, reason: '');
	}//end deliverFiles()

	/**
	 * Why a recipient cannot be mailed, or '' when it can.
	 *
	 * @param IUser|null $user The recipient, null when it does not exist.
	 *
	 * @return string The reason, or ''.
	 */
	private static function recipientProblem(?IUser $user): string {
		if ($user === null) {
			return 'The recipient does not exist.';
		}

		if (trim((string)$user->getEMailAddress()) === '') {
			return 'The recipient has no email address.';
		}

		return '';
	}//end recipientProblem()

	/**
	 * The report mail to one recipient, with every file attached.
	 *
	 * @param IUser                                                     $user The recipient, who has an email address.
	 * @param array{subject: string, body: string, files: list<array<string, string>>} $mail The subject, body and files.
	 *
	 * @return IMessage The message.
	 */
	private function composedMail(IUser $user, array $mail): IMessage {
		$message = $this->mailer->createMessage();
		$message->setTo([trim((string)$user->getEMailAddress()) => $user->getDisplayName()]);
		$message->setSubject($mail['subject']);
		$message->setPlainBody($mail['body']);
		foreach ($mail['files'] as $file) {
			$message->attach(
				$this->mailer->createAttachment($file['contents'], $file['filename'], $file['mimeType'])
			);
		}

		return $message;
	}//end composedMail()

	/**
	 * A delivery outcome.
	 *
	 * @param string $userId The recipient.
	 * @param string $status DELIVERED or FAILED.
	 * @param string $reason Why it failed, else ''.
	 *
	 * @return array{userId: string, status: string, reason: string}
	 */
	private static function outcome(string $userId, string $status, string $reason): array {
		return ['userId' => $userId, 'status' => $status, 'reason' => $reason];
	}//end outcome()
}//end class
