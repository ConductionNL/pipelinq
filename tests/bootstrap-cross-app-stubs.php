<?php

/**
 * Cross-app stubs that every PHPUnit bootstrap loads.
 *
 * Pipelinq reaches these Integriq and OpenRegister classes by string class name
 * behind class_exists(), so it stays installable without those apps. Each stub
 * copies the real class and yields to it when the app is loaded. Required by
 * tests/bootstrap.php, tests/bootstrap-unit.php and tests/bootstrap-bare.php, so
 * a test sees the same classes whichever phpunit config runs it.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// Integriq's connection-registry report event (adopt-connection-registry), sent
// by ConnectionReportService.
if (class_exists('\\OCA\\Integriq\\Event\\ConnectionStatusReportedEvent') === false) {
	require_once __DIR__ . '/Stubs/Integriq/Event/ConnectionStatusReportedEvent.php';
}

// Integriq's opt-out authority (opt-out-before-send), sent by IntegriqConsentClient.
foreach (['OutboundSendDecisionRequestedEvent', 'OptOutChangeRequestedEvent'] as $pipelinqIntegriqEvent) {
	if (class_exists('\\OCA\\Integriq\\Event\\' . $pipelinqIntegriqEvent) === false) {
		require_once __DIR__ . '/Stubs/Integriq/Event/' . $pipelinqIntegriqEvent . '.php';
	}
}

// OpenRegister's shared List-Unsubscribe helper (REQ-ERO-005).
if (class_exists('\\OCA\\OpenRegister\\Notifications\\Service\\Notification\\UnsubscribeHeaders') === false) {
	require_once __DIR__ . '/Stubs/Notifications/Service/Notification/UnsubscribeHeaders.php';
}
