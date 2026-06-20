<?php

/**
 * Pipelinq ZgwCoexistenceValidator.
 *
 * Enforces the REQ-ZGW-008 rule that exactly one of (StUF endpoint,
 * ZgwEndpoint) is allowed to be the active write path per gemeente at any
 * time. Called before any createZaak flow; raises DoubleWritePathException
 * when both backends are configured to write on the same gemeente so the
 * pipeline blocks before either external call is issued.
 *
 * The check is intentionally tolerant: a missing StUF schema (stuf-zkn-bg-adapter
 * not yet merged) reduces to "ZGW only", and a ZgwEndpoint with
 * `actief=false` or `readOnly=true` is excluded from the write-path count.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Zgw
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

use Psr\Log\LoggerInterface;

/**
 * Coexistence validator (write-path conflict detector).
 */
class ZgwCoexistenceValidator
{
    /**
     * StUF endpoint schema slug introduced by `stuf-zkn-bg-adapter`.
     */
    public const STUF_ENDPOINT_SCHEMA = 'stufEndpoint';

    /**
     * Constructor.
     *
     * @param ZgwRegisterAccess $registers Register facade.
     * @param LoggerInterface   $logger    PSR-3 logger.
     */
    public function __construct(
        private ZgwRegisterAccess $registers,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Validate that at most one write path is active for a gemeente.
     *
     * @param string $gemeenteCode CBS 4-digit gemeente code.
     *
     * @return void
     *
     * @throws DoubleWritePathException When both ZGW + StUF are write-enabled.
     */
    public function validateWritePath(string $gemeenteCode): void
    {
        if ($gemeenteCode === '') {
            return;
        }

        $zgwWriters  = $this->activeZgwWriters(gemeenteCode: $gemeenteCode);
        $stufWriters = $this->activeStufWriters(gemeenteCode: $gemeenteCode);

        if ($zgwWriters !== [] && $stufWriters !== []) {
            $conflicting = array_values(array_unique(array_merge($zgwWriters, $stufWriters)));
            $msg         = sprintf(
                'ZGW: dubbele schrijfpad-conflict voor gemeente "%s" — schakel een van de volgende endpoints uit: %s',
                $gemeenteCode,
                implode(', ', $conflicting)
            );
            $this->logger->warning(
                    'ZGW: double-write conflict detected',
                    [
                        'gemeente'    => $gemeenteCode,
                        'conflicting' => $conflicting,
                    ]
                    );
            throw new DoubleWritePathException($msg, $conflicting);
        }
    }//end validateWritePath()

    /**
     * IDs of all active ZGW write endpoints for a gemeente.
     *
     * @param string $gemeenteCode CBS code.
     *
     * @return array<int, string>
     */
    private function activeZgwWriters(string $gemeenteCode): array
    {
        $rows = $this->registers->findAll(
            ZgwRegisterAccess::SCHEMA_ENDPOINT,
            ['gemeenteCode' => $gemeenteCode]
        );
        $ids  = [];
        foreach ($rows as $row) {
            $actief   = (bool) ($row['actief'] ?? false);
            $readOnly = (bool) ($row['readOnly'] ?? false);
            if ($actief === false || $readOnly === true) {
                continue;
            }

            $id = (string) ($row['id'] ?? ($row['@self']['slug'] ?? ''));
            if ($id !== '') {
                $ids[] = 'zgw:'.$id;
            }
        }

        return $ids;
    }//end activeZgwWriters()

    /**
     * IDs of all active StUF write endpoints for a gemeente.
     *
     * @param string $gemeenteCode CBS code.
     *
     * @return array<int, string>
     */
    private function activeStufWriters(string $gemeenteCode): array
    {
        $rows = $this->registers->findAll(
            self::STUF_ENDPOINT_SCHEMA,
            ['gemeenteCode' => $gemeenteCode]
        );
        $ids  = [];
        foreach ($rows as $row) {
            $write = (string) ($row['write'] ?? ($row['richting'] ?? ''));
            if ($write !== 'on' && $write !== 'true' && $write !== '1') {
                continue;
            }

            $id = (string) ($row['id'] ?? ($row['@self']['slug'] ?? ''));
            if ($id !== '') {
                $ids[] = 'stuf:'.$id;
            }
        }

        return $ids;
    }//end activeStufWriters()
}//end class
