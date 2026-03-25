import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import FindClientWidget from './views/widgets/FindClientWidget.vue'

Vue.use(PiniaVuePlugin)

OCA.Dashboard.register('pipelinq_find_client_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })
	const View = Vue.extend(FindClientWidget)
	new View({
		pinia,
		propsData: { title: widget.title },
	}).$mount(el)
})
