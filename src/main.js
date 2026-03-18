import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import router from './router/index.js'
import App from './App.vue'
import { initializeStores } from './store/store.js'

// Library CSS — must be explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'
import './assets/app.css'

Vue.mixin({ methods: { t, n } })
Vue.use(PiniaVuePlugin)

// Create and mount Vue instance immediately so the App renders.
const app = new Vue({
	pinia,
	router,
	render: h => h(App),
})

app.$mount('#content')

// Initialize stores in parallel — the useListView retry logic will wait
// for registerObjectType to complete.
initializeStores()
