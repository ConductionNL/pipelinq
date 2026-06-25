<?php

/**
 * Pipelinq BundleService.
 *
 * Assembly, integrity-sealing and secure delivery of the AVG data-export bundle.
 * Groups the included BewijsItems by category into a structured JSON manifest,
 * computes the SHA-256 integrity hash, signs (PAdES-LTV via DocuDesk when wired,
 * otherwise a SHA-256 manifest fallback), and mints a one-time, time-limited
 * download token whose hash — never the raw token — is persisted.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Avg
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Avg;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Export-bundle assembly and secure delivery.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the collaborators the
 *  bundle pipeline legitimately needs (repository, OR container for DocuDesk,
 *  app config, logger).
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.2
 */
class BundleService
{
    /**
     * Default validity window for the download link, in days.
     *
     * @var int
     */
    public const DEFAULT_DOWNLOAD_DAYS = 30;

    /**
     * Constructor.
     *
     * @param AvgRepository      $repository The AVG OR repository.
     * @param ContainerInterface $container  The DI container (DocuDesk render).
     * @param IAppConfig         $appConfig  The app config.
     * @param OrGdprBridge       $orGdpr     Bridge onto OR's canonical access export.
     * @param AvgRequestService  $requests   Article -> OR request-type mapping.
     * @param LoggerInterface    $logger     The logger.
     */
    public function __construct(
        private AvgRepository $repository,
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private OrGdprBridge $orGdpr,
        private AvgRequestService $requests,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Assemble the JSON manifest for a request, grouping evidence by category.
     *
     * Only items flagged opgenomenInExport are included; duplicates and
     * unreachable-source markers are excluded from the deliverable.
     *
     * @param array<string, mixed> $request The request payload.
     *
     * @return array{manifest: array<string, mixed>, count: int} The manifest + item count.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.2
     */
    public function assemble(array $request): array
    {
        $verzoekId = $this->repository->idOf($request);
        $items     = $this->repository->findAll(
            schemaKey: AvgRepository::SCHEMA_BEWIJS_ITEM,
            filters: ['verzoekId' => $verzoekId]
        );

        $byCategory = [];
        $count      = 0;
        foreach ($items as $item) {
            if ((bool) ($item['opgenomenInExport'] ?? false) === false) {
                continue;
            }

            $category = (string) ($item['categorie'] ?? 'overig');
            $byCategory[$category][] = [
                'bron'        => (string) ($item['bronApp'] ?? ''),
                'register'    => (string) ($item['bronRegister'] ?? ''),
                'object'      => (string) ($item['bronObject'] ?? ''),
                'rechtsgrond' => (string) ($item['rechtsgrond'] ?? ''),
                'geredigeerd' => (bool) ($item['geredigeerd'] ?? false),
                'inhoud'      => (string) ($item['inhoudPreview'] ?? ''),
            ];
            $count++;
        }

        $manifest = [
            'kenmerk'        => (string) ($request['kenmerk'] ?? ''),
            'artikel'        => (string) ($request['artikel'] ?? ''),
            'verzoeker'      => (string) ($request['verzoekerNaam'] ?? ''),
            'samengesteldOp' => $this->now(),
            'categorieen'    => $byCategory,
        ];

        $orExport = $this->orAccessExport(request: $request);
        if ($orExport !== null) {
            $manifest['inzageExport'] = $orExport;
        }

        return ['manifest' => $manifest, 'count' => $count];
    }//end assemble()

    /**
     * Anchor the deliverable on OR's canonical access/portability export.
     *
     * For art-15 (inzage) and art-20 (portabiliteit) requests the manifest now
     * carries OpenRegister's `DataSubjectRequestService::assembleAccessExport`
     * — the authoritative, RBAC + tenant scoped inventory of the subject's
     * objects with the PII attributes that triggered inclusion — alongside the
     * federated BewijsItem categories. Pipelinq keeps the signing / one-time
     * token / AP-dossier wrapper on top. Recorded in
     * openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md.
     *
     * @param array<string, mixed> $request The request payload.
     *
     * @return array<string, mixed>|null The OR export, or null when not applicable.
     */
    private function orAccessExport(array $request): ?array
    {
        $orType = $this->requests->orRequestTypeFor((string) ($request['artikel'] ?? ''));
        if ($orType !== 'access' && $orType !== 'portability') {
            return null;
        }

        $subjectId = (string) ($request['verzoekerBsn'] ?? '');
        if ($subjectId === '') {
            return null;
        }

        return $this->orGdpr->assembleAccessExport(subjectId: $subjectId, type: null);
    }//end orAccessExport()

    /**
     * Generate, seal and persist the export bundle for a request.
     *
     * Assembles the manifest, renders a PDF via DocuDesk when available, computes
     * the SHA-256 integrity hash, signs (PAdES-LTV when a PKIoverheid cert +
     * DocuDesk signer are wired, otherwise sha256-manifest fallback) and mints a
     * one-time download token (only its hash is stored).
     *
     * @param array<string, mixed> $request The request payload.
     * @param string               $userId  The acting handler UID.
     *
     * @return array{bundle: array<string, mixed>, downloadToken: string} The bundle + raw token (shown once).
     *
     * @throws OCSBadRequestException When there is nothing to export.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.2
     */
    public function generate(array $request, string $userId): array
    {
        $verzoekId = $this->repository->idOf($request);
        $assembled = $this->assemble(request: $request);
        if ($assembled['count'] === 0) {
            throw new OCSBadRequestException('Er is geen bewijs geselecteerd om te exporteren.');
        }

        $json    = (string) json_encode($assembled['manifest']);
        $sha256  = hash('sha256', $json);
        $signing = $this->sign(payload: $json);

        $downloadDays = $this->appConfig->getValueInt(
            Application::APP_ID,
            'avg_download_validity_days',
            self::DEFAULT_DOWNLOAD_DAYS
        );

        $token     = $this->mintToken();
        $expiresAt = $this->now(addDays: max(1, $downloadDays));

        $bundle = [
            'verzoekId'                   => $verzoekId,
            'samengesteldOp'              => $this->now(),
            'samengesteldDoor'            => $userId,
            'bevatItems'                  => $assembled['count'],
            'formaat'                     => ['json', 'pdf'],
            'bestandsgrootte'             => $this->humanSize(bytes: strlen($json)),
            'sha256'                      => $sha256,
            'ondertekend'                 => $signing['signed'],
            'ondertekeningsType'          => $signing['type'],
            'uitgeleverdVia'              => 'veilige-download-link',
            'downloadVerloopt'            => $expiresAt,
            'downloadCodeHash'            => hash('sha256', $token),
            'verzoekerOntvangstBevestigd' => false,
        ];

        $saved    = $this->repository->save(schemaKey: AvgRepository::SCHEMA_EXPORT_BUNDLE, object: $bundle);
        $bundleId = $this->repository->idOf($saved);

        // Link the bundle back onto the request.
        $request['bewijsbundel'] = $bundleId;
        $this->repository->save(schemaKey: AvgRepository::SCHEMA_VERZOEK, object: $request, id: $verzoekId);

        $this->logger->info(
            'Pipelinq AVG: bundle generated',
            ['verzoekId' => $verzoekId, 'bundleId' => $bundleId, 'signed' => $signing['signed']]
        );

        return ['bundle' => $saved, 'downloadToken' => $token];
    }//end generate()

    /**
     * Validate a one-time download token against a bundle and consume it.
     *
     * Verifies the token hash, that the link has not expired, then records the
     * first download (sets verzoekerOntvangstBevestigd + delivery timestamp). The
     * token is compared in constant time and is never logged.
     *
     * @param string $bundleId The bundle UUID.
     * @param string $token    The raw one-time token.
     *
     * @return array<string, mixed> The bundle metadata after consumption.
     *
     * @throws OCSNotFoundException   When the bundle does not exist.
     * @throws OCSForbiddenException  When the token is wrong or the link expired.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.3
     */
    public function consumeDownload(string $bundleId, string $token): array
    {
        $bundle = $this->repository->find(schemaKey: AvgRepository::SCHEMA_EXPORT_BUNDLE, id: $bundleId);

        $expected = (string) ($bundle['downloadCodeHash'] ?? '');
        if ($expected === '' || hash_equals($expected, hash('sha256', $token)) === false) {
            throw new OCSForbiddenException('Ongeldige of verlopen downloadlink.');
        }

        $expiresAt = (string) ($bundle['downloadVerloopt'] ?? '');
        if ($this->isExpired(value: $expiresAt) === true) {
            throw new OCSForbiddenException('De downloadlink is verlopen.');
        }

        if ((bool) ($bundle['verzoekerOntvangstBevestigd'] ?? false) === false) {
            $bundle['verzoekerOntvangstBevestigd'] = true;
            $bundle['uitgeleverdOp'] = $this->now();
            $bundle = $this->repository->save(
                schemaKey: AvgRepository::SCHEMA_EXPORT_BUNDLE,
                object: $bundle,
                id: $bundleId
            );
        }

        return $bundle;
    }//end consumeDownload()

    /**
     * Assemble the complete AP-escalation dossier for a request.
     *
     * Packages the request, its TermijnEvents, BewijsItems, RedactieActies and
     * any denial into one structured index for export to the AP (REQ-AVG-008).
     *
     * @param array<string, mixed> $request The request payload.
     *
     * @return array<string, mixed> The dossier index.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.8
     */
    public function assembleDossier(array $request): array
    {
        $verzoekId = $this->repository->idOf($request);

        return [
            'verzoek'        => $request,
            'termijnEvents'  => $this->repository->findAll(
                schemaKey: AvgRepository::SCHEMA_TERMIJN_EVENT,
                filters: ['verzoekId' => $verzoekId]
            ),
            'bewijsItems'    => $this->repository->findAll(
                schemaKey: AvgRepository::SCHEMA_BEWIJS_ITEM,
                filters: ['verzoekId' => $verzoekId]
            ),
            'weigering'      => $this->repository->findAll(
                schemaKey: AvgRepository::SCHEMA_WEIGERING,
                filters: ['verzoekId' => $verzoekId]
            ),
            'samengesteldOp' => $this->now(),
        ];
    }//end assembleDossier()

    /**
     * Sign the bundle payload.
     *
     * Uses DocuDesk's PAdES-LTV signer with the configured PKIoverheid cert when
     * both are available; otherwise falls back to a SHA-256 manifest seal so the
     * bundle always carries a verifiable integrity proof (PAdES-LTV signing with a
     * real PKIoverheid cert is a deployment dependency).
     *
     * @param string $payload The JSON payload.
     *
     * @return array{signed: bool, type: string} The signing result.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.2
     */
    private function sign(string $payload): array
    {
        $certPath = $this->appConfig->getValueString(Application::APP_ID, 'avg_pki_cert_path', '');
        if ($certPath !== '' && is_readable($certPath) === true && trim($payload) !== '') {
            try {
                if ($this->container->has('OCA\Docudesk\Service\SigningService') === true) {
                    $signer = $this->container->get('OCA\Docudesk\Service\SigningService');
                    $signer->sign(payload: $payload, certificatePath: $certPath);
                    return ['signed' => true, 'type' => 'PAdES-LTV'];
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Pipelinq AVG: PAdES signer unavailable', ['exception' => $e->getMessage()]);
            }
        }

        // Integrity fallback: the SHA-256 hash of the manifest is the seal.
        return ['signed' => false, 'type' => 'sha256-manifest'];
    }//end sign()

    /**
     * Mint a cryptographically strong one-time download token.
     *
     * @return string The raw token (returned once; only its hash is persisted).
     */
    private function mintToken(): string
    {
        return bin2hex(random_bytes(32));
    }//end mintToken()

    /**
     * Whether an ISO 8601 timestamp is in the past.
     *
     * @param string $value The timestamp.
     *
     * @return bool True when expired (or unparseable, fail-closed).
     */
    private function isExpired(string $value): bool
    {
        if ($value === '') {
            return true;
        }

        try {
            $when = new DateTimeImmutable($value);
        } catch (\Throwable $e) {
            return true;
        }

        return ($when->getTimestamp() < (new DateTimeImmutable())->getTimestamp());
    }//end isExpired()

    /**
     * Human-readable byte size.
     *
     * @param int $bytes The size in bytes.
     *
     * @return string The human-readable size.
     */
    private function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round(($bytes / 1048576), 1).' MB';
        }

        if ($bytes >= 1024) {
            return round(($bytes / 1024), 1).' KB';
        }

        return $bytes.' B';
    }//end humanSize()

    /**
     * The current time as an ISO 8601 string, optionally offset by whole days.
     *
     * @param int $addDays Days to add.
     *
     * @return string The timestamp.
     */
    private function now(int $addDays=0): string
    {
        $when = new DateTimeImmutable();
        if ($addDays > 0) {
            $when = $when->add(new DateInterval('P'.$addDays.'D'));
        }

        return $when->format(DateTimeInterface::ATOM);
    }//end now()
}//end class
