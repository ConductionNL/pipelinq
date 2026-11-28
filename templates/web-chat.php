<?php

/**
 * The website chat window, framed on the channel's own websites.
 *
 * The window script mounts here and reads its routes from the initial state
 * `web-chat`; it keeps the visitor's chat secret in the frame's sessionStorage only.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/** @var array<string, mixed> $_ */
/** @var \OCP\IL10N $l */

use OCP\Util;

Util::addStyle('pipelinq', 'web-chat');
Util::addScript('pipelinq', 'pipelinq-web-chat-window');
?>
<main id="pipelinq-web-chat" class="pipelinq-web-chat">
<?php if ($_['available'] === false) { // phpcs:ignore Squiz.NamingConventions.ValidVariableName.NotCamelCaps
	?>
	<p><?php p($l->t('This chat is not available.')); ?></p>
<?php } ?>
</main>
