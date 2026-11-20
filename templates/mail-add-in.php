<?php

/**
 * The Outlook add-in's task pane (contact-moments-mail-add-in).
 *
 * Loads Office.js and the pane bundle only, never the Nextcloud app shell.
 * The page is public; the pane signs in through Nextcloud's login flow and
 * sends the app password with every call.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

use OCP\Util;

/** @var \OCP\IL10N $l */

$appId = OCA\Pipelinq\AppInfo\Application::APP_ID;
Util::addTranslations($appId);
Util::addScript($appId, $appId . '-mailAddIn');
?>
<script src="https://appsforoffice.microsoft.com/lib/1/hosted/office.js"></script>
<main id="pipelinq-mail-add-in" class="pq-mail-pane" aria-live="polite">
	<p><?php p($l->t('Loading…')); ?></p>
</main>
