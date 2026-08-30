<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  The flow controls, in Nextcloud's app sidebar. Shares `useFlowStore` with the
  canvas, which is why neither needs props from the other. Save/Run live on
  CnFlowDetail's toolbar; the shared `flow-detail` page type handles them.

  Without this, `#/flows/:id` (and `#/flows/new`, which is the same route with
  the literal id `new`) renders a bare canvas: the toolbar can save and run a
  flow but nothing can name it, describe it, pick its trigger or edit a step,
  because every one of those controls lives in CnFlowSidebar. Mirrors
  procest/src/views/flows/FlowDetailSidebar.vue.
-->
<template>
	<CnFlowSidebar />
</template>

<script>
import { CnFlowSidebar } from '@conduction/nextcloud-vue'

export default {
	name: 'FlowDetailSidebar',

	components: { CnFlowSidebar },
}
</script>
