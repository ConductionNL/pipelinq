<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - CtiPage is the single CTI (telephony) settings page. It composes the CTI
  - integration configuration (CtiSettings) and the webhook event log
  - (CtiEventLog) into one settings-section page so administrators manage the
  - screen-pop / click-to-dial adapter and inspect its inbound webhooks in one
  - place. Both child views render their own NcSettingsSection, so stacking them
  - keeps the native Nextcloud settings look while merging the two former
  - Administration menu entries into one.
  -
  - @spec openspec/changes/pipelinq-cti-and-catalog-ia/tasks.md
  -->
<template>
	<div class="cti-page">
		<CtiSettings />
		<CtiEventLog />
	</div>
</template>

<script>
import CtiEventLog from './CtiEventLog.vue'
import CtiSettings from './CtiSettings.vue'

export default {
	name: 'CtiPage',
	components: { CtiSettings, CtiEventLog },
}
</script>

<style scoped>
.cti-page {
	display: flex;
	flex-direction: column;
}
</style>
