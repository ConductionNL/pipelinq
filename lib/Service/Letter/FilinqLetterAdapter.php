<?php

/**
 * FilinqLetterAdapter: pipelinq's side of a letter made by filinq.
 *
 * filinq owns document generation for the fleet (hydra ADR-075), so this class
 * holds no rendering of its own. It resolves filinq's TemplateService and
 * DocumentService through {@see FleetAppId}, because the id and the namespace
 * both moved when docudesk became filinq, and it hands filinq references to
 * the client, the contact person and the ticket, never copies of their values.
 * filinq reads them again under its own checks.
 *
 * Absence is an error here, not a fallback: when filinq cannot be resolved,
 * every method that needs it throws `filinq_unavailable`. No placeholder PDF
 * is ever returned.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Letter
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Letter;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Support\FleetAppId;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Lists pipelinq's letter templates in filinq and has filinq render one.
 *
 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
 */
class FilinqLetterAdapter
{

    /**
     * The template namespace pipelinq's letters live under in filinq.
     *
     * @var string
     */
    public const TEMPLATE_NAMESPACE = 'pipelinq';

    /**
     * filinq's template service, below its app namespace root.
     *
     * @var string
     */
    private const TEMPLATE_SERVICE = 'Service\TemplateService';

    /**
     * filinq's document generation service, below its app namespace root.
     *
     * @var string
     */
    private const DOCUMENT_SERVICE = 'Service\DocumentService';


    /**
     * Constructor.
     *
     * @param ContainerInterface $container   Resolves filinq's services across the rename.
     * @param IUserSession       $userSession The acting user; filinq files the copy in their Files.
     * @param LoggerInterface    $logger      Logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()


    /**
     * Why filinq cannot make a letter on this instance, or null when it can.
     *
     * @return string|null The reason, or null when both services resolve.
     *
     * @spec openspec/specs/client-letters/spec.md#requirement-without-filinq-no-letter-is-offered-or-faked-req-wlt-003
     */
    public function unavailableReason(): ?string
    {
        if ($this->templateService() === null || $this->documentService() === null) {
            return 'filinq_unavailable: filinq is not installed or not enabled on this instance';
        }

        return null;

    }//end unavailableReason()


    /**
     * The letter templates that belong to pipelinq, as id, name and description.
     *
     * @return list<array{id: string, name: string, description: string}> The templates, by name.
     *
     * @throws RuntimeException When filinq is absent or refuses the listing.
     *
     * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
     */
    public function listTemplates(): array
    {
        $service = $this->requireService(service: $this->templateService());

        try {
            $rows = (array) $service->getTemplatesByNamespace(self::TEMPLATE_NAMESPACE);
        } catch (Throwable $e) {
            $this->logger->error(
                'Pipelinq: filinq refused the letter template listing',
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
            throw new RuntimeException('filinq_failed: '.$e->getMessage(), 0, $e);
        }

        $templates = [];
        foreach ($rows as $row) {
            $template = $this->summarise(row: (array) $row);
            if ($template !== null) {
                $templates[] = $template;
            }
        }

        usort($templates, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $templates;

    }//end listTemplates()


    /**
     * One of pipelinq's templates by id, or null when filinq has none by that
     * id in the pipelinq namespace.
     *
     * A template of another app is answered as absent, so this endpoint cannot
     * be used to render another app's documents.
     *
     * @param string $templateId The filinq template id.
     *
     * @return array{id: string, name: string, description: string}|null The template.
     *
     * @throws RuntimeException When filinq is absent.
     *
     * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
     */
    public function findTemplate(string $templateId): ?array
    {
        $service = $this->requireService(service: $this->templateService());

        try {
            $row = (array) $service->getTemplate($templateId);
        } catch (Throwable $e) {
            return null;
        }

        if (($row['namespace'] ?? '') !== self::TEMPLATE_NAMESPACE) {
            return null;
        }

        return $this->summarise(row: $row);

    }//end findTemplate()


    /**
     * Have filinq fill a template, keep a copy in the user's Files and return the PDF.
     *
     * @param string                                                  $templateId The filinq template id.
     * @param list<array{register: string, schema: string, id: string}> $dataRefs   The records the letter reads.
     * @param string                                                  $filename   The file name, without extension.
     *
     * @return array{content: string, fileId: int|null, path: string|null, warnings: list<string>} The letter.
     *
     * @throws RuntimeException When filinq is absent, no user acts, or the render fails.
     *
     * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
     */
    public function render(string $templateId, array $dataRefs, string $filename): array
    {
        $service = $this->requireService(service: $this->documentService());

        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new RuntimeException('user_required: a letter is filed in the acting user\'s Files');
        }

        try {
            $result = (array) $service->generateDocument(
                $templateId,
                $dataRefs,
                [
                    'format'   => 'pdf',
                    'userId'   => $user->getUID(),
                    'filename' => $filename,
                    'output'   => ['mode' => 'both'],
                ]
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Pipelinq: filinq refused a letter render',
                ['app' => Application::APP_ID, 'templateId' => $templateId, 'exception' => $e->getMessage()]
            );
            throw new RuntimeException('filinq_render_failed: '.$e->getMessage(), 0, $e);
        }

        $content = (string) ($result['content'] ?? '');
        if ($content === '') {
            throw new RuntimeException('filinq_render_failed: filinq returned no document');
        }

        $output = (array) ($result['output'] ?? []);
        $fileId = $output['fileId'] ?? null;

        return [
            'content'  => $content,
            'fileId'   => is_numeric($fileId) === true ? (int) $fileId : null,
            'path'     => isset($output['path']) === true ? (string) $output['path'] : null,
            'warnings' => array_values(array_map('strval', (array) ($result['warnings'] ?? []))),
        ];

    }//end render()


    /**
     * Reduce a filinq template object to what the dialog shows.
     *
     * @param array<string, mixed> $row The template object.
     *
     * @return array{id: string, name: string, description: string}|null The summary, or null without an id.
     */
    private function summarise(array $row): ?array
    {
        $self = (array) ($row['@self'] ?? []);
        $id   = (string) ($row['id'] ?? ($self['id'] ?? ($row['uuid'] ?? '')));
        if ($id === '') {
            return null;
        }

        $name = trim((string) ($row['name'] ?? ''));

        return [
            'id'          => $id,
            'name'        => $name === '' ? $id : $name,
            'description' => (string) ($row['description'] ?? ''),
        ];

    }//end summarise()


    /**
     * The service, or the named refusal when filinq did not resolve.
     *
     * @param object|null $service The resolved service.
     *
     * @return object The service.
     *
     * @throws RuntimeException When the service is absent.
     */
    private function requireService(?object $service): object
    {
        if ($service === null) {
            throw new RuntimeException((string) $this->unavailableReason());
        }

        return $service;

    }//end requireService()


    /**
     * filinq's TemplateService, or null.
     *
     * @return object|null The service.
     */
    private function templateService(): ?object
    {
        return FleetAppId::getService(container: $this->container, canonical: 'filinq', relative: self::TEMPLATE_SERVICE);

    }//end templateService()


    /**
     * filinq's DocumentService, or null.
     *
     * @return object|null The service.
     */
    private function documentService(): ?object
    {
        return FleetAppId::getService(container: $this->container, canonical: 'filinq', relative: self::DOCUMENT_SERVICE);

    }//end documentService()


}//end class
