<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - PosTenderTypeManager — the POS tender-types section of the admin page.
  -
  - A tender type is HOW a customer pays at the register (cash, pin, voucher, gift
  - card) and which GL account that posts to. It is not the same thing as a payment
  - PROVIDER (the PSP that processes the money) even though the old nav labelled
  - them "POS betaalmethoden" and "Betalingsmethoden" — two entries that read as
  - duplicates purely because of their names. Both are administrator configuration,
  - so both moved onto /settings/admin/pipelinq (nav-ia-cleanup), where they sit
  - next to each other under names that say which is which.
  -->
<template>
	<NcSettingsSection :name="t('pipelinq', 'POS tender types')"
		:description="t('pipelinq', 'How a customer can pay at the till — cash, pin, voucher — and the GL account each one posts to. Distinct from the payment providers above, which process the money.')">
		<PosTenderTypeList />
	</NcSettingsSection>
</template>

<script>
import { NcSettingsSection } from '@nextcloud/vue'
import PosTenderTypeList from '../pos/PosTenderTypeList.vue'

export default {
	name: 'PosTenderTypeManager',
	components: {
		NcSettingsSection,
		PosTenderTypeList,
	},
}
</script>
