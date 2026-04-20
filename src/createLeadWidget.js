import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import CreateLeadWidget from './views/widgets/CreateLeadWidget.vue'

Vue.use(PiniaVuePlugin)

OCA.Dashboard.register('pipelinq_create_lead_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })
	const View = Vue.extend(CreateLeadWidget)
	new View({
		pinia,
		propsData: { title: widget.title },
	}).$mount(el)
})
