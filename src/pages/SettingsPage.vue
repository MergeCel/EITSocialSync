<script setup lang="ts">
import { computed, ref } from 'vue'
import { Database, Download, Info, RotateCcw, ShieldAlert, Upload } from 'lucide-vue-next'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import { useAppStore } from '../stores/app'

const store = useAppStore()
const resetOpen = ref(false)
const importInput = ref<HTMLInputElement | null>(null)
const storageSize = computed(() => new Blob([JSON.stringify(store.state)]).size)
</script>

<template>
  <section class="settings-grid">
    <article class="card settings-card"><span class="settings-icon"><Database :size="24"/></span><div><h2>Data workspace</h2><p>Seluruh data operasional tersimpan di database server. Ekspor JSON berguna sebagai arsip audit sebelum perubahan besar.</p><div class="storage-detail"><span>Ukuran snapshot saat ini</span><strong>{{ (storageSize / 1024).toFixed(1) }} KB</strong></div><div class="settings-actions"><button class="button" @click="store.exportData"><Download :size="17"/> Ekspor JSON</button><button class="button" disabled title="Restore penuh belum tersedia"><Upload :size="17"/> Impor JSON</button><input ref="importInput" hidden type="file" accept="application/json" @change="store.importData()"/></div></div></article>
    <article class="card settings-card danger-settings"><span class="settings-icon"><RotateCcw :size="24"/></span><div><h2>Reset workspace</h2><p>Hapus media, post, akun sosial, notifikasi, dan audit trail dari server. Folder contoh akan dibuat ulang. Tindakan ini hanya tersedia untuk Admin pada lingkungan lokal.</p><button class="button button-danger" :disabled="!store.isAdmin" :title="store.isAdmin ? 'Reset seluruh data operasional' : 'Hanya Admin yang dapat mereset workspace'" @click="resetOpen = true"><RotateCcw :size="17"/> Reset Workspace</button></div></article>
  </section>
  <section class="prototype-notice"><ShieldAlert :size="24"/><div><h3>Operasional backend</h3><p>Publikasi, scheduler, dan pengambilan engagement berjalan melalui queue worker dan scheduler Laravel. Pastikan keduanya aktif di server.</p><ul><li>File media disimpan privat dan diakses melalui signed URL sementara.</li><li>Token akun sosial dienkripsi di database dan tidak pernah dikirim ke frontend.</li><li>Reset workspace hanya diizinkan pada lingkungan lokal.</li></ul></div></section>
  <section class="card activity-card"><div class="card-section-head"><div><span class="card-icon"><Info :size="20"/></span><div><h3>Aktivitas Terbaru</h3><p>Audit trail aksi workspace</p></div></div></div><div><p v-for="activity in store.state.activities" :key="activity.id"><strong>{{ activity.actor }}</strong> {{ activity.action }} <b>{{ activity.subject }}</b><span>{{ new Date(activity.createdAt).toLocaleString('id-ID') }}</span></p></div></section>
  <ConfirmDialog :open="resetOpen" title="Reset Seluruh Workspace?" message="Media, post, akun sosial, notifikasi, dan audit trail akan dihapus permanen. Ekspor JSON terlebih dahulu bila data masih dibutuhkan." confirm-label="Ya, Reset" danger @close="resetOpen = false" @confirm="store.reset(); resetOpen = false"/>
</template>
