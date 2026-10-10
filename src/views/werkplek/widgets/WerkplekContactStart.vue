<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<section class="werkplek-contact-start" data-testid="werkplek-contact-start">
		<div class="werkplek-contact-start__text">
			<h2 class="werkplek-contact-start__title">
				{{ t('pipelinq', 'Start a contact') }}
			</h2>
			<p class="werkplek-contact-start__lead">
				{{ t('pipelinq', 'Find the client on the line or at the desk. Open requests, tasks and earlier contact below, with a form to log this contact.') }}
			</p>
		</div>
		<WerkplekClientSelect class="werkplek-contact-start__select" />
	</section>
</template>

<script>
import WerkplekClientSelect from './WerkplekClientSelect.vue'

/**
 * WerkplekContactStart: the "Contact starten" card of the contact centre
 * dashboard (board PqDashboard). A title, one line of guidance and the client
 * picker that puts a client in focus for the widgets below.
 *
 * @spec openspec/specs/kcc-werkplek/spec.md
 */
export default {
	name: 'WerkplekContactStart',

	components: { WerkplekClientSelect },
}
</script>

<style scoped>
.werkplek-contact-start {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 24px;
	padding: 20px 24px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
}

.werkplek-contact-start__title {
	margin: 0 0 4px;
	font-size: 18px;
	font-weight: 700;
}

.werkplek-contact-start__lead {
	margin: 0;
	max-width: 420px;
	color: var(--color-text-maxcontrast);
}

.werkplek-contact-start__select {
	flex: 0 1 460px;
}
</style>
