<?php

/**
 * Pipelinq LoyaltyGdprService.
 *
 * AVG/GDPR data subject access (export) + deletion (soft / anonymisation).
 * Deletion ANONYMISES the klantId on every PointsLedgerEntry / Redemption / Account,
 * keeps gift cards blocked (not deleted), and NEVER removes ledger entries —
 * those are retained for financial audit (REQ-LOY-009 / RJ 270).
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
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * GDPR access + deletion for loyalty entities.
 *
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-010
 */
class LoyaltyGdprService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface    $container       The DI container.
     * @param IAppConfig            $appConfig       The app configuration.
     * @param LoyaltyAccountService $accountService  The account service.
     * @param PointsLedgerService   $ledgerService   The ledger service.
     * @param GiftCardService       $giftCardService The gift card service.
     * @param LoggerInterface       $logger          The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoyaltyAccountService $accountService,
        private PointsLedgerService $ledgerService,
        private GiftCardService $giftCardService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Export every loyalty object linked to a klantId.
     *
     * @param string $klantId The Nextcloud contact UID.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-010-02
     */
    public function getCustomerLoyaltyData(string $klantId): array
    {
        if ($klantId === '') {
            return ['accounts' => [], 'ledger' => [], 'redemptions' => [], 'giftCards' => []];
        }

        $accounts    = $this->findAllByFilter(schemaKey: 'klantLoyaltyAccount_schema', filters: ['klantId' => $klantId]);
        $redemptions = $this->findAllByFilter(schemaKey: 'redemption_schema', filters: ['klantId' => $klantId]);
        $giftCards   = $this->giftCardService->listForKlant(klantId: $klantId);

        $ledger = [];
        foreach ($accounts as $a) {
            $accountId = (string) ($a['accountId'] ?? $a['@self']['id'] ?? $a['uuid'] ?? '');
            if ($accountId === '') {
                continue;
            }

            $ledger = array_merge($ledger, $this->ledgerService->getLedgerHistory(accountId: $accountId));
        }

        return [
            'klantId'     => $klantId,
            'accounts'    => $accounts,
            'redemptions' => $redemptions,
            'giftCards'   => $giftCards,
            'ledger'      => $ledger,
        ];
    }//end getCustomerLoyaltyData()

    /**
     * GDPR soft-delete: anonymise klantId on all loyalty objects + block gift cards.
     *
     * Never removes ledger entries (RJ 270 audit retention).
     *
     * @param string $klantId The contact UID.
     *
     * @return array<string, int> Summary counts.
     *
     * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-010-03
     */
    public function deleteLoyaltyData(string $klantId): array
    {
        if ($klantId === '') {
            return ['accounts' => 0, 'redemptions' => 0, 'ledger' => 0, 'giftCards' => 0];
        }

        $summary = [
            'accounts'    => $this->anonymizeAccounts(klantId: $klantId),
            'ledger'      => $this->anonymizeLedgerEntries(klantId: $klantId),
            'redemptions' => $this->anonymizeRedemptions(klantId: $klantId),
            'giftCards'   => $this->blockGiftCardsForKlant(klantId: $klantId),
        ];

        $this->logger->info(
            'Pipelinq: GDPR loyalty deletion completed',
            ['klantId' => $klantId, 'summary' => $summary]
        );

        return $summary;
    }//end deleteLoyaltyData()

    /**
     * Anonymise (deactivate) every KlantLoyaltyAccount for a customer.
     *
     * @param string $klantId The contact UID.
     *
     * @return int Number of accounts touched.
     */
    private function anonymizeAccounts(string $klantId): int
    {
        $count    = 0;
        $accounts = $this->findAllByFilter(schemaKey: 'klantLoyaltyAccount_schema', filters: ['klantId' => $klantId]);
        foreach ($accounts as $account) {
            $uuid = (string) ($account['accountId'] ?? $account['@self']['id'] ?? $account['uuid'] ?? '');
            if ($uuid !== '') {
                $this->accountService->deleteAccount(accountId: $uuid);
                $count++;
            }
        }

        return $count;
    }//end anonymizeAccounts()

    /**
     * Anonymise the klantId field on every PointsLedgerEntry for a customer.
     *
     * Only klantId is cleared — aantal/balansNa/timestamp/etc. are retained
     * (RJ 270 audit retention).
     *
     * @param string $klantId The contact UID.
     *
     * @return int Number of ledger entries touched.
     */
    private function anonymizeLedgerEntries(string $klantId): int
    {
        $count  = 0;
        $ledger = $this->findAllByFilter(schemaKey: 'pointsLedgerEntry_schema', filters: ['klantId' => $klantId]);
        foreach ($ledger as $entry) {
            $entry['klantId'] = null;
            $uuid = (string) ($entry['entryId'] ?? $entry['@self']['id'] ?? $entry['uuid'] ?? '');
            if ($uuid !== '') {
                $this->persist(schemaKey: 'pointsLedgerEntry_schema', payload: $entry, uuid: $uuid);
                $count++;
            }
        }

        return $count;
    }//end anonymizeLedgerEntries()

    /**
     * Anonymise the klantId field on every Redemption for a customer.
     *
     * @param string $klantId The contact UID.
     *
     * @return int Number of redemptions touched.
     */
    private function anonymizeRedemptions(string $klantId): int
    {
        $count       = 0;
        $redemptions = $this->findAllByFilter(schemaKey: 'redemption_schema', filters: ['klantId' => $klantId]);
        foreach ($redemptions as $redemption) {
            $redemption['klantId'] = null;
            $uuid = (string) ($redemption['redemptionId'] ?? $redemption['@self']['id'] ?? $redemption['uuid'] ?? '');
            if ($uuid !== '') {
                $this->persist(schemaKey: 'redemption_schema', payload: $redemption, uuid: $uuid);
                $count++;
            }
        }

        return $count;
    }//end anonymizeRedemptions()

    /**
     * Block every gift card owned by a customer (GDPR deletion — never removed).
     *
     * @param string $klantId The contact UID.
     *
     * @return int Number of gift cards blocked.
     */
    private function blockGiftCardsForKlant(string $klantId): int
    {
        $count = 0;
        foreach ($this->giftCardService->listForKlant(klantId: $klantId) as $card) {
            $uuid = (string) ($card['giftCardId'] ?? $card['@self']['id'] ?? $card['uuid'] ?? '');
            if ($uuid !== '') {
                $this->giftCardService->blockGiftCard(giftCardId: $uuid, reason: 'GDPR deletion');
                $count++;
            }
        }

        return $count;
    }//end blockGiftCardsForKlant()

    /**
     * FindAll helper.
     *
     * @param string               $schemaKey The schema config key.
     * @param array<string, mixed> $filters   The filters.
     *
     * @return array<int, array<string, mixed>>
     */
    private function findAllByFilter(string $schemaKey, array $filters): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
        if ($register === '' || $schema === '') {
            return [];
        }

        try {
            $rows = $this->getObjectService()->findAll(
                filters: $filters,
                register: $register,
                schema: $schema,
                limit: 10000
            );
        } catch (\Throwable $e) {
            return [];
        }

        $list = [];
        if (is_array($rows) === true) {
            $list = array_values($rows);
        }

        return array_map([$this, 'toArray'], $list);
    }//end findAllByFilter()

    /**
     * Persist helper.
     *
     * @param string               $schemaKey The schema config key.
     * @param array<string, mixed> $payload   The payload.
     * @param string               $uuid      The UUID.
     *
     * @return void
     */
    private function persist(string $schemaKey, array $payload, string $uuid): void
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
        if ($register === '' || $schema === '') {
            return;
        }

        try {
            $this->getObjectService()->saveObject(
                object: $payload,
                extend: [],
                register: $register,
                schema: $schema,
                uuid: $uuid
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: GDPR persist failed',
                ['schemaKey' => $schemaKey, 'uuid' => $uuid, 'exception' => $e->getMessage()]
            );
        }
    }//end persist()

    /**
     * Normalise to array.
     *
     * @param mixed $object The OR entity or array.
     *
     * @return array<string, mixed>
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) invoked via the
     *  [$this, 'toArray'] array-callable in findAllByFilter(), which phpmd's
     *  static analysis does not detect as a call site.
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialized = $object->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            $data = $object->getObject();
            if (is_array($data) === true) {
                return $data;
            }
        }

        return [];
    }//end toArray()

    /**
     * Get the ObjectService.
     *
     * @return object
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRegister ObjectService is unavailable.', 0, $e);
        }
    }//end getObjectService()
}//end class
