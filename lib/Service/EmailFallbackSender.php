<?php

/**
 * Pipelinq EmailFallbackSender.
 *
 * Sends the email fallback for a Berichtenbox message — used both when no
 * mailbox exists / the citizen opted out, and when a delivered message remains
 * unread for 5 working days (BBK 1.7 Art. 3.5). The same rendered body is
 * reused with a prepended notice. Mail is sent through the Nextcloud mailer
 * (configured by the tenant via openconnector SMTP).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-MAILBOX-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Exception\EmailSendException;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends Berichtenbox fallback emails via the Nextcloud mailer.
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-MAILBOX-003
 */
class EmailFallbackSender
{
    /**
     * Constructor.
     *
     * @param IMailer         $mailer    The Nextcloud mailer.
     * @param IAppConfig      $appConfig The app config.
     * @param IL10N           $l10n      The localization service.
     * @param LoggerInterface $logger    The logger.
     */
    public function __construct(
        private IMailer $mailer,
        private IAppConfig $appConfig,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Send a fallback email for a message.
     *
     * @param array<string, mixed> $message The message (subject, body).
     * @param string               $toEmail The recipient email address.
     *
     * @return void
     *
     * @throws EmailSendException When the email cannot be sent.
     */
    public function send(array $message, string $toEmail): void
    {
        if ($this->mailer->validateMailAddress($toEmail) === false) {
            throw new EmailSendException(message: 'Invalid fallback recipient address.');
        }

        $subject = (string) ($message['subject'] ?? '');
        $body    = (string) ($message['body'] ?? '');
        $notice  = $this->l10n->t('This message is also available in your MijnOverheid Berichtenbox.');
        $html    = '<p><em>'.htmlspecialchars($notice, (ENT_QUOTES | ENT_XHTML), 'UTF-8').'</em></p>'.$body;

        try {
            $mail = $this->mailer->createMessage();
            $mail->setTo([$toEmail]);
            $mail->setSubject($subject);
            $mail->setHtmlBody($html);
            $mail->setPlainBody($notice."\n\n".strip_tags($body));

            $sender = $this->appConfig->getValueString(Application::APP_ID, 'berichtenbox_fallback_sender', '');
            if ($sender !== '' && $this->mailer->validateMailAddress($sender) === true) {
                $mail->setFrom([$sender]);
            }

            $failed = $this->mailer->send($mail);
            if (count($failed) > 0) {
                throw new EmailSendException(message: 'Mailer reported failed recipients.');
            }
        } catch (EmailSendException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error('Berichtenbox: fallback email failed', ['exception' => $e->getMessage()]);
            throw new EmailSendException(message: 'Fallback email dispatch failed.');
        }//end try
    }//end send()
}//end class
