import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import ClientSearchWidget from './views/widgets/ClientSearchWidget.vue'

Vue.use(PiniaVuePlugin)

OCA.Dashboard.register('pipelinq_client_search_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })
	const View = Vue.extend(ClientSearchWidget)
	new View({
		pinia,
		propsData: { title: widget.title },
	}).$mount(el)
})
