<?php

/**
 * Pipelinq OpenRegisterEmailLeafLinkAdapter.
 *
 * Concrete {@see EmailLeafLinkAdapter} that delegates to the OpenRegister email
 * leaf's link service (`OCA\OpenRegister\Service\EmailLinkService`). OR is a peer
 * app, so the service is resolved lazily through the DI container — pipelinq must
 * not hard-depend on OR's autoloader at compile time.
 *
 * Note: the underlying leaf reads the live NC Mail message metadata at link time
 * and requires the NC Mail app to be installed and a session/user context to be
 * present. The pipelinq matching job is wired to call this adapter; exercising it
 * end-to-end requires a running Mail account (documented as deferred).
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
 * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Adapter that links Mail messages to OR objects through the email leaf.
 *
 * @spec openspec/changes/email-calendar-sync/tasks.md#task-2.1
 */
class OpenRegisterEmailLeafLinkAdapter implements EmailLeafLinkAdapter
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (lazily resolves OR services).
     * @param IAppConfig         $appConfig The app config (register slug).
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * {@inheritDoc}
     *
     * @return bool True when the leaf link service and NC Mail are available.
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-2.1
     */
    public function isAvailable(): bool
    {
        $service = $this->resolveLinkService();
        if ($service === null) {
            return false;
        }

        try {
            return (bool) $service->isMailAvailable();
        } catch (Throwable $e) {
            $this->logger->warning(
                'OpenRegisterEmailLeafLinkAdapter: availability probe failed',
                ['exception' => $e->getMessage()]
            );
            return false;
        }
    }//end isAvailable()

    /**
     * {@inheritDoc}
     *
     * @param string $objectUuid    The matched CRM object uuid.
     * @param int    $mailAccountId The NC Mail account id.
     * @param int    $mailMessageId The NC Mail message id.
     * @param string $messageUid    The IMAP message UID (may be empty).
     *
     * @return bool True when a NEW link was created.
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-2.1
     */
    public function linkMessage(string $objectUuid, int $mailAccountId, int $mailMessageId, string $messageUid): bool
    {
        $service = $this->resolveLinkService();
        if ($service === null) {
            return false;
        }

        $registerId = $this->resolveRegisterId();

        try {
            $service->linkEmail(
                objectUuid: $objectUuid,
                registerId: $registerId,
                schemaId: 0,
                mailAccountId: $mailAccountId,
                messageId: (string) $mailMessageId,
                messageUid: $messageUid
            );
            return true;
        } catch (Throwable $e) {
            // The leaf throws a 409 when the link already exists — treat that as
            // "no new link", not an error (idempotency, no duplicate links).
            if ($e->getCode() === 409) {
                return false;
            }

            $this->logger->error(
                'OpenRegisterEmailLeafLinkAdapter: link failed',
                ['objectUuid' => $objectUuid, 'exception' => $e->getMessage()]
            );
            return false;
        }//end try
    }//end linkMessage()

    /**
     * Lazily resolve the OpenRegister email-leaf link service.
     *
     * @return object|null The EmailLinkService, or null when OR is unavailable.
     */
    private function resolveLinkService(): ?object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\EmailLinkService');
        } catch (Throwable $e) {
            $this->logger->warning(
                'OpenRegisterEmailLeafLinkAdapter: OR EmailLinkService unavailable',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end resolveLinkService()

    /**
     * Resolve the numeric OR register id for pipelinq's configured register.
     *
     * @return int The register id (0 when unresolved — the leaf tolerates 0).
     */
    private function resolveRegisterId(): int
    {
        $value = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        if ($value !== '' && ctype_digit($value) === true) {
            return (int) $value;
        }

        return 0;
    }//end resolveRegisterId()
}//end class
