<?php

/**
 * Public page behind the cancel link of an event mail.
 *
 * GET shows the event and a button; only the POST of that button cancels.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/** @var array<string, mixed> $_ */
/** @var \OCP\IL10N $l */
?>
<div class="guest-box">
<?php if ($_['state'] === 'confirm') { ?>
	<h2><?php p($l->t('Cancel your registration')); ?></h2>
	<p><?php p($l->t('You are registered for %1$s on %2$s.', [$_['title'], $_['startAt']])); ?></p>
	<form method="post" action="<?php p($_['action']); ?>">
		<input type="hidden" name="token" value="<?php p($_['token']); ?>">
		<button type="submit" class="primary"><?php p($l->t('Cancel my registration')); ?></button>
	</form>
<?php } elseif ($_['state'] === 'done') { ?>
	<h2><?php p($l->t('Your registration is cancelled')); ?></h2>
	<p><?php p($l->t('Your registration for %s is cancelled. Thank you for letting us know.', [$_['title']])); ?></p>
<?php } else { ?>
	<h2><?php p($l->t('This link does not work')); ?></h2>
	<p><?php p($l->t('The link is not valid or has expired.')); ?></p>
<?php } ?>
</div>
