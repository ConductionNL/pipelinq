<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<CnStatsBlock
		:title="t('pipelinq', 'Contact Moment Volume')"
		:count="overview.contactMomentVolume || 0"
		:count-label="trendLabel('contactMomentVolume')"
		:icon="MessageText"
		show-zero-count
		horizontal />
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import MessageText from 'vue-material-design-icons/MessageText.vue'
import analyticsKpiMixin from './analyticsKpiMixin.js'

/**
 * KPI card: count of contactmoment objects in the selected period.
 *
 * @spec openspec/changes/decompose-unified-analytics/specs/dashboard/spec.md#REQ-DASH-010
 */
export default {
	name: 'ContactVolumeKpiWidget',
	components: {
		CnStatsBlock,
	},
	mixins: [analyticsKpiMixin],
	data() {
		return {
			MessageText,
		}
	},
}
</script>
