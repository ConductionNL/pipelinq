import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import DealsOverviewWidget from './views/widgets/DealsOverviewWidget.vue'

Vue.use(PiniaVuePlugin)

OCA.Dashboard.register('pipelinq_deals_overview_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })
	const View = Vue.extend(DealsOverviewWidget)
	new View({
		pinia,
		propsData: { title: widget.title },
	}).$mount(el)
})
