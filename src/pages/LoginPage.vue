<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowRight, Cloud, LoaderCircle, LockKeyhole, Mail, ShieldCheck } from 'lucide-vue-next'
import { useAppStore } from '../stores/app'
import { ApiError } from '../api'

const store = useAppStore()
const router = useRouter()
const route = useRoute()
const email = ref('nadia@socialsync.id')
const password = ref('SocialSync#2026')
const loading = ref(false)
const error = ref(typeof route.query.error === 'string' ? route.query.error : '')

async function submit() {
  if (!email.value.trim() || !password.value) return
  loading.value = true
  error.value = ''
  try {
    await store.login(email.value.trim(), password.value)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/dashboard'
    await router.replace(redirect)
  } catch (exception) {
    error.value = exception instanceof ApiError ? exception.message : 'Tidak dapat masuk. Periksa koneksi backend.'
  } finally {
    loading.value = false
  }
}

function useDemo(userEmail: string) {
  email.value = userEmail
  password.value = 'SocialSync#2026'
}

</script>

<template>
  <main class="login-page">
    <section class="login-panel">
      <div class="login-brand"><div class="brand-mark"><Cloud :size="25"/><span/></div><div><strong>SocialSync</strong><small>Content workspace</small></div></div>
      <span class="login-kicker">WORKSPACE INTERNAL</span>
      <h1>Masuk ke ruang kerja konten Anda.</h1>
      <p class="login-lead">Jadwalkan, publikasikan, dan pantau performa Instagram, TikTok, dan Facebook dari satu tempat.</p>

      <form class="login-form" @submit.prevent="submit">
        <label>Email kerja<div class="login-input"><Mail :size="17"/><input v-model="email" type="email" autocomplete="username" placeholder="nama@perusahaan.com" required/></div></label>
        <label>Password<div class="login-input"><LockKeyhole :size="17"/><input v-model="password" type="password" autocomplete="current-password" placeholder="Password akun" required/></div></label>
        <div v-if="error" class="login-error">{{ error }}</div>
        <button class="button button-primary button-full login-submit" :disabled="loading"><LoaderCircle v-if="loading" :size="18" class="spin"/><template v-else>Masuk ke Workspace <ArrowRight :size="18"/></template></button>
      </form>

      <div class="login-demo">
        <div><strong>Akun demo</strong><span>Password semua akun: <code>SocialSync#2026</code></span></div>
        <div class="login-demo-users"><button type="button" @click="useDemo('nadia@socialsync.id')">Nadia · Admin</button><button type="button" @click="useDemo('raka@socialsync.id')">Raka · Editor</button><button type="button" @click="useDemo('maya@socialsync.id')">Maya · Viewer</button></div>
      </div>
    </section>

    <aside class="login-aside">
      <div class="login-orbit one"/><div class="login-orbit two"/>
      <div class="login-aside-copy"><span><ShieldCheck :size="18"/> SESI TERLINDUNGI</span><h2>Satu alur kerja untuk semua kanal.</h2><p>Token akun sosial disimpan terenkripsi di server. Frontend tidak pernah menerima access token platform.</p></div>
      <div class="login-facts"><div><strong>3</strong><span>Platform terhubung</span></div><div><strong>RBAC</strong><span>Admin · Editor · Viewer</span></div><div><strong>Queue</strong><span>Publikasi terjadwal</span></div></div>
    </aside>
  </main>
</template>

<style scoped>
.login-page { min-height: 100vh; padding: 28px; display: grid; grid-template-columns: minmax(0, 1.05fr) minmax(340px, .95fr); gap: 28px; background: radial-gradient(circle at 12% 10%, #eef4fb 0, #f7f8fa 38%, #f2f4f7 100%); }
.login-panel { width: 100%; max-width: 520px; margin: auto; padding: 30px 12px; }
.login-brand { display: flex; align-items: center; gap: 12px; margin-bottom: 56px; }
.login-brand .brand-mark { position: relative; width: 44px; height: 44px; display: grid; place-items: center; border-radius: 13px; color: #3f72af; background: #fff; box-shadow: 0 6px 18px rgba(17,45,78,.12); }
.login-brand .brand-mark span { position: absolute; right: 6px; bottom: 7px; width: 8px; height: 8px; border-radius: 50%; background: #6eb7da; border: 2px solid #fff; }
.login-brand strong { display: block; color: #112d4e; font: 800 18px 'Manrope', sans-serif; letter-spacing: -.4px; }
.login-brand small { color: #728096; font-size: 11px; }
.login-kicker { color: #3f72af; font: 800 10px 'Manrope', sans-serif; letter-spacing: 1.5px; }
.login-panel h1 { max-width: 460px; margin: 12px 0; color: #112d4e; font-size: clamp(30px, 4vw, 45px); line-height: 1.08; letter-spacing: -1.6px; }
.login-lead { max-width: 430px; color: #728096; font-size: 14px; line-height: 1.65; }
.login-form { margin-top: 29px; display: grid; gap: 15px; }
.login-form label { display: grid; gap: 7px; color: #3d4e62; font-size: 12px; font-weight: 700; }
.login-input { height: 48px; padding: 0 14px; display: flex; align-items: center; gap: 9px; border: 1px solid #dbe2ef; border-radius: 10px; background: #fff; color: #8b9aab; }
.login-input:focus-within { border-color: #3f72af; box-shadow: 0 0 0 3px rgba(63,114,175,.1); }
.login-input input { flex: 1; min-width: 0; border: 0; outline: 0; color: #112d4e; background: transparent; font-size: 14px; }
.login-error { padding: 10px 12px; border: 1px solid #f0caca; border-radius: 8px; background: #fff5f5; color: #b23b3b; font-size: 12px; line-height: 1.5; }
.login-submit { margin-top: 4px; min-height: 48px; }
.login-submit .spin { animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.login-demo { margin-top: 28px; padding: 15px; border: 1px solid #e1e6ed; border-radius: 11px; background: rgba(255,255,255,.6); }
.login-demo strong { display: block; color: #112d4e; font-size: 11px; }
.login-demo span { color: #728096; font-size: 11px; }
.login-demo code { color: #3f72af; font-size: 11px; }
.login-demo-users { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
.login-demo-users button { padding: 5px 8px; border: 1px solid #dbe2ef; border-radius: 6px; background: #fff; color: #3f72af; font-size: 10px; font-weight: 700; }
.login-aside { position: relative; min-height: 540px; padding: 52px 44px; border-radius: 24px; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between; color: #fff; background: linear-gradient(145deg, #112d4e 0%, #183b63 58%, #2c5687 100%); box-shadow: 0 30px 70px rgba(17,45,78,.18); }
.login-aside-copy { position: relative; z-index: 1; margin-top: auto; max-width: 430px; }
.login-aside-copy > span { display: inline-flex; align-items: center; gap: 7px; color: #9fc9ea; font: 800 10px 'Manrope', sans-serif; letter-spacing: 1.3px; }
.login-aside h2 { margin: 17px 0 12px; font-size: clamp(27px, 3vw, 42px); line-height: 1.08; letter-spacing: -1.4px; }
.login-aside p { max-width: 360px; color: #b7c9da; font-size: 13px; line-height: 1.7; }
.login-facts { position: relative; z-index: 1; display: flex; gap: 24px; margin-top: 40px; padding-top: 19px; border-top: 1px solid rgba(255,255,255,.14); }
.login-facts strong { display: block; font-size: 17px; }
.login-facts span { color: #9fb4cb; font-size: 10px; }
.login-orbit { position: absolute; border: 1px solid rgba(255,255,255,.12); border-radius: 50%; }
.login-orbit.one { width: 440px; height: 440px; right: -150px; top: -100px; }
.login-orbit.two { width: 280px; height: 280px; right: -40px; top: 10px; background: rgba(100,160,215,.12); }
@media (max-width: 900px) { .login-page { grid-template-columns: 1fr; padding: 14px; } .login-aside { min-height: 300px; padding: 30px; } .login-facts { display: none; } .login-panel { padding: 38px 10px; } .login-brand { margin-bottom: 36px; } }
</style>
