<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CheckCircle2, Cloud, ExternalLink, HardDrive, Link2, RefreshCw, ShieldCheck, Terminal, Unplug, Upload } from 'lucide-vue-next'
import PlatformIcon from '../components/PlatformIcon.vue'
import StatusBadge from '../components/StatusBadge.vue'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import { useAppStore } from '../stores/app'
import { formatDate, formatNumber } from '../utils'

const store = useAppStore()
const route = useRoute()
const router = useRouter()
const driveDisconnectOpen = ref(false)
const syncing = ref(false)

onMounted(async () => {
  const connected = typeof route.query.connected === 'string' ? route.query.connected : ''
  const error = typeof route.query.error === 'string' ? route.query.error : ''

  if (connected) {
    await store.refreshState(false)
    store.notify('success', 'Integrasi terhubung', 'Otorisasi Google Drive berhasil disimpan di server.')
  } else if (error) {
    store.notify('error', 'Koneksi akun gagal', error)
  }

  if (connected || error) await router.replace({ path: '/accounts' })
})

async function syncBuffer() {
  if (syncing.value) return
  syncing.value = true
  await store.syncBufferChannels()
  syncing.value = false
}
</script>

<template>
  <div class="section-title account-section-title"><div><p>SUMBER MEDIA</p><h2>Drive pengguna</h2></div><RouterLink v-if="!store.isViewer" to="/drive" class="button"><Upload :size="18"/> Upload dari perangkat</RouterLink></div>
  <section class="card drive-connection-card">
    <span class="drive-provider-icon"><Cloud :size="26"/></span>
    <div><span class="demo-chip">{{ store.state.driveConnection.connected ? 'TERHUBUNG' : 'BELUM AKTIF' }}</span><h3>Google Drive</h3><p v-if="store.state.driveConnection.connected">{{ store.state.driveConnection.accountEmail }} · terhubung {{ store.state.driveConnection.connectedAt ? formatDate(store.state.driveConnection.connectedAt) : '' }}. File foto dan video dapat diimpor ke Media Library.</p><p v-else>Hubungkan Google Drive untuk mengimpor foto dan video langsung ke Media Library. Token OAuth disimpan terenkripsi di server dan hanya meminta akses baca.</p></div>
    <div class="drive-connection-actions" v-if="!store.isViewer"><button v-if="!store.state.driveConnection.connected && store.isAdmin" class="button button-primary" @click="store.connectDrive"><Link2 :size="18"/> Hubungkan Google Drive</button><button v-else-if="store.state.driveConnection.connected && store.isAdmin" class="button" @click="driveDisconnectOpen = true"><Unplug :size="17"/> Putuskan</button><RouterLink to="/drive" class="button"><HardDrive :size="17"/> Buka Media Library</RouterLink></div>
  </section>

  <div class="section-title"><div><p>SALURAN PUBLIKASI · BUFFER</p><h2>Akun media sosial</h2></div><div class="buffer-actions"><button class="button" :disabled="!store.state.bufferConnection.configured || syncing" @click="syncBuffer"><RefreshCw :size="17" :class="{ spin: syncing }"/> {{ syncing ? 'Menyinkronkan...' : 'Sinkronkan Channel' }}</button><button class="button button-primary buffer-manage-button" :class="{ 'buffer-limit-reached': store.state.bufferConnection.limitReached }" :disabled="!store.state.bufferConnection.configured || store.state.bufferConnection.limitReached" @click="store.openBufferManager"><ExternalLink :size="17"/> {{ store.state.bufferConnection.limitReached ? 'Batas 3 Channel Tercapai' : 'Tambah / Kelola Channel' }}</button></div></div>

  <section class="card buffer-connection-card">
    <div class="buffer-connection-copy"><span class="demo-chip">{{ store.state.bufferConnection.configured ? 'BUFFER AKTIF' : 'BELUM DIPROVISIKAN' }}</span><h3>{{ store.state.bufferConnection.accountName || 'Koneksi Buffer pribadi' }}</h3><p v-if="store.state.bufferConnection.configured">{{ store.state.bufferConnection.accountEmail }}<template v-if="store.state.bufferConnection.organizationName"> · {{ store.state.bufferConnection.organizationName }}</template>. Channel dikelola melalui Buffer, lalu disinkronkan ke SocialSync.</p><p v-else>Personal API key Buffer harus dipasang oleh developer melalui Artisan. API key tidak pernah dikirim ke browser.</p><small v-if="store.state.bufferConnection.lastSyncedAt">Sinkron terakhir {{ formatDate(store.state.bufferConnection.lastSyncedAt) }}</small><small v-else-if="store.state.bufferConnection.lastError" class="buffer-error">{{ store.state.bufferConnection.lastError }}</small></div>
    <div class="buffer-channel-meter"><strong>{{ store.state.bufferConnection.channelCount }} / {{ store.state.bufferConnection.maxChannels }}</strong><span>channel aktif</span><div><i :style="{ width: `${Math.min(100, (store.state.bufferConnection.channelCount / store.state.bufferConnection.maxChannels) * 100)}%` }"/></div></div>
  </section>

  <div v-if="!store.state.bufferConnection.configured" class="role-notice"><Terminal :size="19"/><p>Jalankan <code>php artisan socialsync:user-provision</code> di server untuk memasang API key Buffer terenkripsi bagi pengguna ini.</p></div>
  <section v-if="store.state.accounts.length" class="account-grid">
    <article v-for="account in store.state.accounts" :key="account.id" class="card account-card" :class="`account-${account.status}`"><header><PlatformIcon :platform="account.platform" size="lg"/><StatusBadge :status="account.status"/></header><h3>{{ account.accountName }}</h3><p>{{ account.handle }}</p><div class="account-stats"><div><span>Pengikut tersimpan</span><strong>{{ formatNumber(account.followers) }}</strong></div><div><span>Sumber koneksi</span><strong>Buffer</strong></div></div><div class="account-ok"><CheckCircle2 :size="17"/><span>Siap menerima post melalui Buffer</span></div></article>
  </section>
  <div v-else-if="store.state.bufferConnection.configured" class="role-notice"><ShieldCheck :size="19"/><p>Belum ada channel tersinkron. Hubungkan channel di Buffer lalu tekan <strong>Sinkronkan Channel</strong>.</p></div>
  <div v-if="!store.isAdmin" class="role-notice"><ShieldCheck :size="19"/><p>Koneksi Buffer melekat pada akun Anda. Batasnya {{ store.state.bufferConnection.maxChannels }} channel aktif.</p></div>
  <ConfirmDialog :open="driveDisconnectOpen" title="Putuskan Google Drive?" message="Token akses Google Drive akan dihapus dari server. File yang sudah diimpor ke Media Library tetap tersimpan." confirm-label="Ya, Putuskan" danger @close="driveDisconnectOpen = false" @confirm="store.disconnectDrive(); driveDisconnectOpen = false"/>
</template>
