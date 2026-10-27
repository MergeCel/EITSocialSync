import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import { router } from './router'
import { useAppStore } from './stores/app'
import './styles.css'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)

const store = useAppStore(pinia)

async function start() {
  await store.bootstrap()

  router.beforeEach((to) => {
    if (to.meta.public) {
      if (to.path === '/login' && store.authenticated) return '/dashboard'
      return true
    }

    if (!store.authenticated) {
      return { path: '/login', query: { redirect: to.fullPath } }
    }

    if (to.meta.requiresEdit && store.isViewer) {
      return '/dashboard'
    }

    return true
  })

  app.use(router)
  await router.isReady()
  app.mount('#app')
}

void start()
