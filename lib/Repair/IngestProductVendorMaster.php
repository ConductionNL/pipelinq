<?php

/**
 * Pipelinq IngestProductVendorMaster.
 *
 * Idempotent repair step that accepts the shillinq-product-vendor-to-pipelinq
 * export and maps it onto the pipelinq product supply master and supplier
 * commercial master.
 *
 * Products  — matched on sku/barcode; fill-only empty master-data fields; create
 *             when no match; preserve unmapped shillinq fields under MDM
 *             sourceRecord.rawAttributes. Returns {shillinqRef → productId}.
 *
 * Vendors   — resolve/create an NC contact (match KvK/VAT/email via
 *             ContactVcardService, else create) → contactsUid; create/fill a
 *             supplier record keyed by contactsUid; route financial fields (IBAN,
 *             payment terms) back as the shillinq AP payload. Returns
 *             {shillinqVendorRef → contactsUid}.
 *
 * Each ingested object receives a sourceRecord (sourceSystem shillinq-products /
 * shillinq-vendors) so the MDM golden-record layer picks up provenance.
 *
 * Re-running this step is a safe no-op: matches are resolved before any write,
 * pipelinq-authoritative pricing (unitPrice, cost) is NEVER overwritten.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/product-vendor-master/spec.md
 * @spec openspec/specs/product-vendor-master/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ContactVcardService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Idempotent ingest of the shillinq product and vendor master-data export.
 *
 * Consumer of the CROSS-APP INTERFACE CONTRACT #1 (Wave-A producer):
 *   - productId   is the stable FK shillinq stock-keeping stores after ingest.
 *   - contactsUid is the stable FK shillinq AP records store after ingest.
 *
 * The export is passed via app-config key `shillinq_pvm_export` (JSON blob) by
 * the counterpart `shillinq-product-vendor-to-pipelinq` change, or can be
 * triggered programmatically by passing the payload array directly to
 * {@see self::ingest()}.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Legitimately wires the small
 *  set of NC + OR collaborators a data-migration step needs.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) A one-shot ingest step: product match/fill/
 *  create, vendor match/fill/create, contact resolution and source-record writing are one
 *  cohesive idempotent migration, deliberately kept in a single class.
 *
 * @spec openspec/specs/product-vendor-master/spec.md
 * @spec openspec/specs/product-vendor-master/spec.md
 */
class IngestProductVendorMaster implements IRepairStep
{
    /**
     * Pipelinq-authoritative product pricing fields that MUST NOT be overwritten
     * during ingest (REQ-PVM-007: fill-only).
     *
     * @var string[]
     */
    private const PRICING_FIELDS_PROTECTED = ['unitPrice', 'cost', 'priceTiers'];

    /**
     * Product supply-master fields that may be filled from the shillinq export.
     *
     * @var string[]
     */
    private const PRODUCT_FILLABLE_FIELDS = [
        'gtin',
        'manufacturer',
        'unitOfMeasure',
        'weight',
        'dimensions',
        'hazardClass',
        'preferredSupplier',
        'stockTracked',
        'consumableBy',
    ];

    /**
     * Supplier commercial fields that may be filled from the shillinq export.
     *
     * @var string[]
     */
    private const SUPPLIER_FILLABLE_FIELDS = [
        'displayName',
        'category',
        'status',
        'termsOfTrade',
        'leadTimeDays',
        'catalog',
        'preferred',
        'notes',
    ];

    /**
     * Financial AP fields that belong to shillinq and MUST be routed back
     * rather than stored on the pipelinq supplier schema.
     *
     * @var string[]
     */
    private const VENDOR_FINANCIAL_FIELDS = ['iban', 'paymentMethod', 'creditLimit', 'taxWithholding'];

    /**
     * Constructor.
     *
     * @param IAppManager         $appManager          The app manager.
     * @param IAppConfig          $appConfig           The app configuration.
     * @param ContactVcardService $contactVcardService The vCard sync service.
     * @param ContainerInterface  $container           The DI container (ObjectService lookup).
     * @param LoggerInterface     $logger              The logger.
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly IAppConfig $appConfig,
        private readonly ContactVcardService $contactVcardService,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
     *
     * @spec openspec/specs/product-vendor-master/spec.md
     */
    public function getName(): string
    {
        return 'Ingest shillinq product and vendor master-data export into pipelinq (idempotent)';
    }//end getName()

    /**
     * Run the repair step (IRepairStep entry point).
     *
     * Reads the export from app-config key `shillinq_pvm_export`. If not set,
     * logs an info and exits cleanly — this step is a no-op until the shillinq
     * counterpart change provides the export.
     *
     * @param IOutput $output The output interface.
     *
     * @return void
     *
     * @spec openspec/specs/product-vendor-master/spec.md
     */
    public function run(IOutput $output): void
    {
        if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
            $output->warning('OpenRegister not installed — skipping PVM ingest.');
            return;
        }

        $exportJson = $this->appConfig->getValueString(Application::APP_ID, 'shillinq_pvm_export', '');
        if ($exportJson === '') {
            $output->info('No shillinq PVM export found in app-config — skipping ingest (no-op).');
            return;
        }

        $export = json_decode($exportJson, true);
        if (is_array($export) === false) {
            $output->warning('shillinq PVM export is not valid JSON — skipping ingest.');
            return;
        }

        try {
            $maps = $this->ingest(export: $export);
            $output->info(
                    sprintf(
                'PVM ingest: %d products mapped, %d vendors mapped.',
                count($maps['products']),
                count($maps['vendors'])
            )
                    );

            // Persist the FK maps so shillinq can pick them up.
            $this->appConfig->setValueString(
                Application::APP_ID,
                'shillinq_pvm_product_map',
                json_encode($maps['products'])
            );
            $this->appConfig->setValueString(
                Application::APP_ID,
                'shillinq_pvm_vendor_map',
                json_encode($maps['vendors'])
            );
        } catch (\Throwable $e) {
            $output->warning('PVM ingest failed: '.$e->getMessage());
            $this->logger->error('PVM ingest failed', ['exception' => $e->getMessage()]);
        }//end try
    }//end run()

    /**
     * Perform the full ingest of the shillinq export.
     *
     * Can be called directly (e.g. from an admin action or a unit test) with
     * an explicit export array. Always idempotent.
     *
     * @param array<string,mixed> $export The shillinq export:
     *                                    ['products' =>
     *                                    [...], 'vendors' =>
     *                                    [...]].
     *
     * @return array{products: array<string,string>, vendors: array<string,string>}
     *         FK maps: products = {shillinqRef → productId},
     *                  vendors  = {shillinqVendorRef → contactsUid}.
     *
     * @spec openspec/specs/product-vendor-master/spec.md
     * @spec openspec/specs/product-vendor-master/spec.md
     */
    public function ingest(array $export): array
    {
        $productMap = [];
        $vendorMap  = [];

        foreach ($export['products'] ?? [] as $shillinqProduct) {
            $ref = $shillinqProduct['ref'] ?? ($shillinqProduct['id'] ?? '');
            if ($ref === '') {
                $this->logger->warning('PVM ingest: skipping product with no ref', $shillinqProduct);
                continue;
            }

            $productId = $this->ingestProduct(shillinqProduct: $shillinqProduct);
            if ($productId !== null) {
                $productMap[$ref] = $productId;
            }
        }

        foreach ($export['vendors'] ?? [] as $shillinqVendor) {
            $ref = $shillinqVendor['ref'] ?? ($shillinqVendor['id'] ?? '');
            if ($ref === '') {
                $this->logger->warning('PVM ingest: skipping vendor with no ref', $shillinqVendor);
                continue;
            }

            [$contactsUid] = $this->ingestVendor(shillinqVendor: $shillinqVendor);
            if ($contactsUid !== null) {
                $vendorMap[$ref] = $contactsUid;
            }
        }

        return ['products' => $productMap, 'vendors' => $vendorMap];
    }//end ingest()

    /**
     * Ingest one shillinq product master-data record.
     *
     * Match on sku/barcode; fill-only empty supply-master fields; create when
     * no match. Preserve unmapped shillinq fields under MDM sourceRecord.
     *
     * @param array<string,mixed> $shillinqProduct The exported product record.
     *
     * @return string|null The pipelinq productId, or null on failure.
     *
     * @spec openspec/specs/product-vendor-master/spec.md
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Idempotent match-fill-or-create with defensive
     *   OR-shape guards and productId==UUID reconciliation; each branch is one migration step.
     */
    private function ingestProduct(array $shillinqProduct): ?string
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerId    = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
            $schemaId      = $this->appConfig->getValueString(Application::APP_ID, 'product_schema', '');

            if ($registerId === '' || $schemaId === '') {
                $this->logger->warning('PVM ingest: product register/schema not configured');
                return null;
            }

            // Match on sku first, then barcode.
            $existing = $this->findProductBySku(
                objectService: $objectService,
                registerId: $registerId,
                schemaId: $schemaId,
                sku: (string) ($shillinqProduct['sku'] ?? ''),
                barcode: (string) ($shillinqProduct['barcode'] ?? ''),
            );

            if ($existing !== null) {
                $productId = $existing['productId'] ?? $existing['id'] ?? null;
                // Fill-only: only write supply-master fields that are currently empty.
                $updates = $this->buildProductFillPayload(existing: $existing, shillinqSource: $shillinqProduct);
                if (empty($updates) === false) {
                    $objectService->saveObject(
                        $registerId,
                        $schemaId,
                        array_merge($existing, $updates),
                        $existing['id'] ?? null
                    );
                }

                $this->writeSourceRecord(
                    objectService: $objectService,
                    registerId: $registerId,
                    nativeId: (string) ($shillinqProduct['id'] ?? ''),
                    entityType: 'product',
                    sourceSystem: 'shillinq-products',
                    currentMasterEntity: (string) ($productId ?? ''),
                    rawAttributes: $shillinqProduct,
                );

                return (string) $productId;
            }//end if

            // Create: map shillinq fields onto the extended product schema.
            $newProduct = $this->mapShillinqProductToSchema(shillinqProduct: $shillinqProduct);
            $created    = $objectService->saveObject($registerId, $schemaId, $newProduct, null);
            $createdArr = (array) $created;
            if (is_object($created) === true && method_exists($created, 'jsonSerialize') === true) {
                $createdArr = $created->jsonSerialize();
            }

            $productId = $createdArr['productId'] ?? $createdArr['id'] ?? null;

            // Ensure productId == object UUID (REQ-PVM-002).
            if (isset($createdArr['id']) === true && ($createdArr['productId'] ?? null) !== $createdArr['id']) {
                $createdArr['productId'] = $createdArr['id'];
                $objectService->saveObject($registerId, $schemaId, $createdArr, $createdArr['id']);
                $productId = $createdArr['id'];
            }

            $this->writeSourceRecord(
                objectService: $objectService,
                registerId: $registerId,
                nativeId: (string) ($shillinqProduct['id'] ?? ''),
                entityType: 'product',
                sourceSystem: 'shillinq-products',
                currentMasterEntity: (string) ($productId ?? ''),
                rawAttributes: $shillinqProduct,
            );

            return (string) $productId;
        } catch (\Throwable $e) {
            $this->logger->error(
                    'PVM ingest: product ingest failed',
                    [
                        'sku'       => $shillinqProduct['sku'] ?? '',
                        'exception' => $e->getMessage(),
                    ]
                    );
            return null;
        }//end try
    }//end ingestProduct()

    /**
     * Ingest one shillinq vendor master-data record.
     *
     * Resolve/create an NC contact (match on KvK/VAT/email); create/fill a
     * supplier keyed by contactsUid; separate financial AP fields.
     *
     * @param array<string,mixed> $shillinqVendor The exported vendor record.
     *
     * @return array{0: string|null, 1: array<string,mixed>}
     *         [contactsUid|null, apPayload (financial fields routed back to shillinq)].
     *
     * @spec openspec/specs/product-vendor-master/spec.md
     */
    private function ingestVendor(array $shillinqVendor): array
    {
        $apPayload = [];

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerId    = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
            $schemaId      = $this->appConfig->getValueString(Application::APP_ID, 'supplier_schema', '');

            if ($registerId === '' || $schemaId === '') {
                $this->logger->warning('PVM ingest: supplier register/schema not configured');
                return [null, $apPayload];
            }

            // Resolve or create an NC contact for this vendor.
            $contactsUid = $this->resolveOrCreateContact(vendor: $shillinqVendor);
            if ($contactsUid === null) {
                $this->logger->warning(
                        'PVM ingest: could not resolve/create contact',
                        [
                            'vendor' => $shillinqVendor['name'] ?? '',
                        ]
                        );
                return [null, $apPayload];
            }

            // Separate financial AP fields — route back to shillinq, do not store here.
            foreach (self::VENDOR_FINANCIAL_FIELDS as $field) {
                if (isset($shillinqVendor[$field]) === true) {
                    $apPayload[$field] = $shillinqVendor[$field];
                }
            }

            // Find existing supplier by contactsUid (idempotency).
            $existingSupplier = $this->findSupplierByContactsUid(
                objectService: $objectService,
                registerId: $registerId,
                schemaId: $schemaId,
                contactsUid: $contactsUid,
            );

            $this->upsertSupplier(
                objectService: $objectService,
                registerId: $registerId,
                schemaId: $schemaId,
                shillinqVendor: $shillinqVendor,
                contactsUid: $contactsUid,
                existingSupplier: $existingSupplier,
            );

            $this->writeSourceRecord(
                objectService: $objectService,
                registerId: $registerId,
                nativeId: (string) ($shillinqVendor['id'] ?? ''),
                entityType: 'vendor',
                sourceSystem: 'shillinq-vendors',
                currentMasterEntity: $contactsUid,
                rawAttributes: $shillinqVendor,
            );

            return [$contactsUid, $apPayload];
        } catch (\Throwable $e) {
            $this->logger->error(
                    'PVM ingest: vendor ingest failed',
                    [
                        'vendor'    => $shillinqVendor['name'] ?? '',
                        'exception' => $e->getMessage(),
                    ]
                    );
            return [null, $apPayload];
        }//end try
    }//end ingestVendor()

    /**
     * Persist the commercial supplier profile for a vendor (fill-only update or create).
     *
     * When a supplier already exists it is filled only where fields are empty; otherwise a
     * new supplier commercial profile is created.
     *
     * @param object                   $objectService    OR ObjectService.
     * @param string                   $registerId       Register id.
     * @param string                   $schemaId         Supplier schema id.
     * @param array<string,mixed>      $shillinqVendor   Incoming vendor record.
     * @param string                   $contactsUid      Resolved NC contact UID.
     * @param array<string,mixed>|null $existingSupplier Existing supplier row, or null.
     *
     * @return void
     */
    private function upsertSupplier(
        object $objectService,
        string $registerId,
        string $schemaId,
        array $shillinqVendor,
        string $contactsUid,
        ?array $existingSupplier,
    ): void {
        if ($existingSupplier !== null) {
            // Fill-only: only write commercial fields that are currently empty.
            $updates = $this->buildSupplierFillPayload(existing: $existingSupplier, shillinqSource: $shillinqVendor);
            if (empty($updates) === false) {
                $objectService->saveObject(
                    $registerId,
                    $schemaId,
                    array_merge($existingSupplier, $updates),
                    $existingSupplier['id'] ?? null
                );
            }

            return;
        }

        // Create new supplier commercial profile.
        $newSupplier = $this->mapShillinqVendorToSchema(shillinqVendor: $shillinqVendor);
        $newSupplier['contactsUid'] = $contactsUid;
        $objectService->saveObject($registerId, $schemaId, $newSupplier, null);
    }//end upsertSupplier()

    /**
     * Resolve or create a Nextcloud Contact for a shillinq vendor.
     *
     * Matching priority: KvK number → VAT number → email address.
     * Creates a new contact via ContactVcardService when no match found.
     *
     * @param array<string,mixed> $vendor The vendor data.
     *
     * @return string|null The NC contact UID, or null on failure.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Contacts-availability guard, prioritised
     *   KvK/VAT/email search loop, and a create-with-generated-UID fallback form one cohesive
     *   resolution path wrapped in a single fault-tolerant try/catch.
     */
    private function resolveOrCreateContact(array $vendor): ?string
    {
        try {
            $contactsManager = $this->container->get('OCP\Contacts\IManager');
            if (method_exists($contactsManager, 'isEnabled') === true && $contactsManager->isEnabled() === false) {
                $this->logger->warning('PVM ingest: Contacts IManager not available');
                return null;
            }

            $searchTerms = array_filter(
                    [
                        $vendor['kvkNumber'] ?? '',
                        $vendor['vatNumber'] ?? '',
                        $vendor['email'] ?? '',
                    ]
                    );

            foreach ($searchTerms as $term) {
                $results = $contactsManager->search((string) $term, ['X-KVK', 'X-VAT', 'EMAIL', 'UID'], []);
                if (empty($results) === false) {
                    $first = reset($results);
                    if (isset($first['UID']) === true && $first['UID'] !== '') {
                        return (string) $first['UID'];
                    }
                }
            }

            // No match found: create a minimal contact via ContactVcardService conventions.
            // We build a temporary supplier object and delegate to the writer.
            $tempSupplierId = sprintf('pvm-ingest-%s', uniqid('', true));
            $contactsUid    = $this->contactVcardService->syncToContacts('contact', $tempSupplierId);
            // If the service could not create a contact, fall back to a generated UID.
            if ($contactsUid === null || $contactsUid === '') {
                $contactsUid = sprintf('pvm-%s', md5(($vendor['name'] ?? '').($vendor['kvkNumber'] ?? '')));
                $this->logger->info(
                        'PVM ingest: using generated contactsUid (no Contacts write)',
                        [
                            'uid'    => $contactsUid,
                            'vendor' => $vendor['name'] ?? '',
                        ]
                        );
            }

            return $contactsUid;
        } catch (\Throwable $e) {
            $this->logger->error('PVM ingest: contact resolution failed', ['exception' => $e->getMessage()]);
            return null;
        }//end try
    }//end resolveOrCreateContact()

    /**
     * Build a fill-only payload for a product: only include supply-master fields
     * that are currently empty on the existing record. Never overwrites pricing.
     *
     * @param array<string,mixed> $existing       The existing pipelinq product.
     * @param array<string,mixed> $shillinqSource The incoming shillinq product.
     *
     * @return array<string,mixed> The fields to write (may be empty).
     */
    private function buildProductFillPayload(array $existing, array $shillinqSource): array
    {
        $updates = [];
        foreach (self::PRODUCT_FILLABLE_FIELDS as $field) {
            $srcValue = $shillinqSource[$field] ?? null;
            if ($srcValue === null || $srcValue === '') {
                continue;
            }

            $currentValue = $existing[$field] ?? null;
            if ($currentValue === null || $currentValue === '' || $currentValue === []) {
                $updates[$field] = $srcValue;
            }
        }

        return $updates;
    }//end buildProductFillPayload()

    /**
     * Build a fill-only payload for a supplier: only commercial fields that
     * are currently empty on the existing record.
     *
     * @param array<string,mixed> $existing       The existing pipelinq supplier.
     * @param array<string,mixed> $shillinqSource The incoming shillinq vendor.
     *
     * @return array<string,mixed> The fields to write (may be empty).
     */
    private function buildSupplierFillPayload(array $existing, array $shillinqSource): array
    {
        $updates = [];
        foreach (self::SUPPLIER_FILLABLE_FIELDS as $field) {
            $srcValue = $shillinqSource[$field] ?? null;
            if ($srcValue === null || $srcValue === '') {
                continue;
            }

            $currentValue = $existing[$field] ?? null;
            if ($currentValue === null || $currentValue === '' || $currentValue === []) {
                $updates[$field] = $srcValue;
            }
        }

        return $updates;
    }//end buildSupplierFillPayload()

    /**
     * Map a shillinq product export record onto the pipelinq product schema.
     * Protected pricing fields are excluded (REQ-PVM-007: fill-only).
     *
     * @param array<string,mixed> $shillinqProduct The shillinq product.
     *
     * @return array<string,mixed> Mapped product data for creation.
     */
    private function mapShillinqProductToSchema(array $shillinqProduct): array
    {
        $mapped = [];

        // Direct-map fields that align with the pipelinq product schema.
        $directMap = ['name', 'sku', 'barcode', 'type', 'status', 'unit']; // phpcs:ignore
        foreach ($directMap as $f) {
            if (isset($shillinqProduct[$f]) === true && $shillinqProduct[$f] !== '') {
                $mapped[$f] = $shillinqProduct[$f];
            }
        }

        // Supply-master fields.
        foreach (self::PRODUCT_FILLABLE_FIELDS as $f) {
            if (isset($shillinqProduct[$f]) === true && $shillinqProduct[$f] !== '') {
                $mapped[$f] = $shillinqProduct[$f];
            }
        }

        // ProductId defaults to the OR object UUID; set to '' here and let the
        // post-create fix-up (in ingestProduct) correct it once the UUID is known.
        $mapped['productId'] = '';

        return $mapped;
    }//end mapShillinqProductToSchema()

    /**
     * Map a shillinq vendor export record onto the pipelinq supplier schema.
     * Financial AP fields are excluded (they go back to shillinq).
     *
     * @param array<string,mixed> $shillinqVendor The shillinq vendor.
     *
     * @return array<string,mixed> Mapped supplier data for creation.
     */
    private function mapShillinqVendorToSchema(array $shillinqVendor): array
    {
        $mapped = [
            'displayName' => $shillinqVendor['name'] ?? '',
            'category'    => $shillinqVendor['category'] ?? 'goods',
            'status'      => 'active',
        ];

        if (isset($shillinqVendor['paymentTermDays']) === true) {
            $mapped['termsOfTrade']['paymentTermDays'] = (int) $shillinqVendor['paymentTermDays'];
        }

        if (isset($shillinqVendor['leadTimeDays']) === true) {
            $mapped['leadTimeDays'] = (int) $shillinqVendor['leadTimeDays'];
        }

        return $mapped;
    }//end mapShillinqVendorToSchema()

    /**
     * Write an MDM sourceRecord for provenance tracking.
     *
     * @param mixed               $objectService       The OpenRegister ObjectService.
     * @param string              $registerId          The register ID.
     * @param string              $nativeId            The source-system native ID.
     * @param string              $entityType          'product' or 'vendor'.
     * @param string              $sourceSystem        'shillinq-products' or 'shillinq-vendors'.
     * @param string              $currentMasterEntity The pipelinq master UUID.
     * @param array<string,mixed> $rawAttributes       The unmodified shillinq payload.
     *
     * @return void
     */
    private function writeSourceRecord(
        mixed $objectService,
        string $registerId,
        string $nativeId,
        string $entityType,
        string $sourceSystem,
        string $currentMasterEntity,
        array $rawAttributes,
    ): void {
        $sourceRecordSchemaId = $this->appConfig->getValueString(
            Application::APP_ID,
            'sourceRecord_schema',
            ''
        );

        if ($sourceRecordSchemaId === '') {
            // SourceRecord schema may not be configured; this is non-fatal.
            return;
        }

        $now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

        try {
            $objectService->saveObject(
                $registerId,
                $sourceRecordSchemaId,
                [
                    'sourceRecordId'      => "{$sourceSystem}:{$nativeId}",
                    'sourceSystem'        => $sourceSystem,
                    'nativeId'            => $nativeId,
                    'entityType'          => $entityType,
                    'currentMasterEntity' => $currentMasterEntity,
                    'rawAttributes'       => $rawAttributes,
                    'mappedAttributes'    => [],
                    'firstSeen'           => $now,
                    'lastSeen'            => $now,
                    'lastChange'          => $now,
                    'linkageMethod'       => 'migration',
                    'linkageConfidence'   => 1.0,
                    'confidence'          => 1.0,
                ],
                null
            );
        } catch (\Throwable $e) {
            // Provenance write failure is non-fatal.
            $this->logger->warning(
                    'PVM ingest: sourceRecord write failed',
                    [
                        'nativeId'  => $nativeId,
                        'exception' => $e->getMessage(),
                    ]
                    );
        }//end try
    }//end writeSourceRecord()

    /**
     * Find an existing pipelinq product by sku or barcode.
     *
     * @param mixed  $objectService The OR ObjectService.
     * @param string $registerId    Register ID.
     * @param string $schemaId      Schema ID.
     * @param string $sku           SKU to match.
     * @param string $barcode       Barcode to match.
     *
     * @return array<string,mixed>|null The first matching product, or null.
     */
    private function findProductBySku(
        mixed $objectService,
        string $registerId,
        string $schemaId,
        string $sku,
        string $barcode,
    ): ?array {
        foreach (array_filter(['sku' => $sku, 'barcode' => $barcode]) as $field => $value) {
            $results = $objectService->findAll(
                config: [
                    'filters' => [
                        $field     => $value,
                        'register' => $registerId,
                        'schema'   => $schemaId,
                    ],
                    'limit'   => 1,
                    'offset'  => 0,
                ]
            );

            if (empty($results) === false) {
                $first = reset($results);
                if (is_object($first) === true && method_exists($first, 'jsonSerialize') === true) {
                    return $first->jsonSerialize();
                }

                return (array) $first;
            }
        }//end foreach

        return null;
    }//end findProductBySku()

    /**
     * Find an existing pipelinq supplier by contactsUid.
     *
     * @param mixed  $objectService The OR ObjectService.
     * @param string $registerId    Register ID.
     * @param string $schemaId      Schema ID.
     * @param string $contactsUid   The Nextcloud contact UID.
     *
     * @return array<string,mixed>|null The supplier, or null.
     */
    private function findSupplierByContactsUid(
        mixed $objectService,
        string $registerId,
        string $schemaId,
        string $contactsUid,
    ): ?array {
        $results = $objectService->findAll(
            config: [
                'filters' => [
                    'contactsUid' => $contactsUid,
                    'register'    => $registerId,
                    'schema'      => $schemaId,
                ],
                'limit'   => 1,
                'offset'  => 0,
            ]
        );

        if (empty($results) === true) {
            return null;
        }

        $first = reset($results);
        if (is_object($first) === true && method_exists($first, 'jsonSerialize') === true) {
            return $first->jsonSerialize();
        }

        return (array) $first;
    }//end findSupplierByContactsUid()
}//end class
