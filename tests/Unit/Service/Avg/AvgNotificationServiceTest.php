<?php

/**
 * Unit tests for AvgNotificationService (4-eyes citizen email drafts).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Avg
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Avg;

use OCA\Pipelinq\Service\Avg\AvgNotificationService;
use OCP\Notification\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for AvgNotificationService draft builders.
 *
 * The draft builders are pure: they never send. These tests pin the
 * compliance-relevant content — the receipt carries the kenmerk + legal
 * deadline, the extension carries the justification, and the denial letter
 * ALWAYS embeds the mandatory AP (Autoriteit Persoonsgegevens) complaint URL
 * (a stated success criterion).
 */
class AvgNotificationServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var AvgNotificationService
     */
    private AvgNotificationService $service;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->service = new AvgNotificationService(
            notificationManager: $this->createMock(IManager::class),
            logger: new NullLogger()
        );
    }//end setUp()

    /**
     * The receipt draft is addressed to the citizen and names the kenmerk and
     * the legal deadline.
     *
     * @return void
     */
    public function testReceiptDraftCarriesKenmerkAndDeadline(): void
    {
        $draft = $this->service->buildReceiptDraft(
            [
                'kenmerk'                   => 'AVG-2026-0042',
                'verzoekerContact'          => 'burger@example.org',
                'wettelijkeTermijnVerloopt' => '2026-05-08T23:59:59+02:00',
            ]
        );

        $this->assertSame('burger@example.org', $draft['to']);
        $this->assertStringContainsString('AVG-2026-0042', $draft['subject']);
        $this->assertStringContainsString('AVG-2026-0042', $draft['body']);
        $this->assertStringContainsString('2026-05-08', $draft['body']);
    }//end testReceiptDraftCarriesKenmerkAndDeadline()

    /**
     * The extension draft includes the justification and the article 12(3) basis.
     *
     * @return void
     */
    public function testExtensionDraftCarriesJustification(): void
    {
        $draft = $this->service->buildExtensionDraft(
            [
                'kenmerk'                   => 'AVG-2026-0042',
                'verzoekerContact'          => 'burger@example.org',
                'wettelijkeTermijnVerloopt' => '2026-07-07T23:59:59+02:00',
            ],
            'Het verzoek raakt meerdere bronsystemen.'
        );

        $this->assertStringContainsString('artikel 12 lid 3 AVG', $draft['body']);
        $this->assertStringContainsString('Het verzoek raakt meerdere bronsystemen.', $draft['body']);
    }//end testExtensionDraftCarriesJustification()

    /**
     * The denial draft ALWAYS embeds the mandatory AP complaint URL and the
     * Art. 23 motivation.
     *
     * @return void
     */
    public function testDenialDraftEmbedsApReferenceAndMotivation(): void
    {
        $draft = $this->service->buildDenialDraft(
            [
                'kenmerk'          => 'AVG-2026-0042',
                'verzoekerContact' => 'burger@example.org',
            ],
            [
                'verwijzingAp'     => 'https://autoriteitpersoonsgegevens.nl/klacht',
                'toelichtingAvg23' => 'De gevraagde gegevens vallen onder een wettelijke geheimhoudingsplicht.',
            ]
        );

        $this->assertStringContainsString('Autoriteit Persoonsgegevens', $draft['body']);
        $this->assertStringContainsString('https://autoriteitpersoonsgegevens.nl/klacht', $draft['body']);
        $this->assertStringContainsString('wettelijke geheimhoudingsplicht', $draft['body']);
    }//end testDenialDraftEmbedsApReferenceAndMotivation()
}//end class
