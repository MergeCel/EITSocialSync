<script setup lang="ts">
import { computed, ref } from 'vue'
import { Cloud as CloudIcon, FileVideo, Folder, FolderOpen, Grid2X2, Image, List, LoaderCircle as LoaderIcon, MoreHorizontal, Plus, Search, SlidersHorizontal, Upload, X } from 'lucide-vue-next'
import { useAppStore } from '../stores/app'
import { formatBytes, formatDate } from '../utils'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import EmptyState from '../components/EmptyState.vue'
import { ApiError } from '../api'
import type { DriveFile } from '../types'

const store = useAppStore()
const query = ref('')
const kind = ref('all')
const folderId = ref<string | null>(null)
const view = ref<'grid' | 'list'>('grid')
const createFolderOpen = ref(false)
const uploadOpen = ref(false)
const newFolderName = ref('')
const selected = ref<string | null>(null)
const deleteTarget = ref<string | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const customName = ref('')
const pendingFile = ref<File | null>(null)
const uploadError = ref('')
const driveOpen = ref(false)
const driveFiles = ref<DriveFile[]>([])
const driveLoading = ref(false)
const driveQuery = ref('')
const driveError = ref('')
const driveReconnect = ref(false)

const media = computed(() => store.state.media.filter((item) => {
  const matchesQuery = item.name.toLowerCase().includes(query.value.toLowerCase())
  const matchesKind = kind.value === 'all' || item.kind === kind.value
  const matchesFolder = folderId.value === null || item.folderId === folderId.value
  return matchesQuery && matchesKind && matchesFolder
}))
const storageBytes = computed(() => store.state.media.reduce((total, item) => total + item.size, 0))
const storagePercent = computed(() => Math.min(100, (storageBytes.value / (5 * 1024 * 1024 * 1024)) * 100))

async function createFolder() {
  if (!newFolderName.value.trim()) return
  const folder = await store.addFolder(newFolderName.value)
  if (!folder) return
  newFolderName.value = ''
  createFolderOpen.value = false
}

function chooseFile(event: Event) {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0] ?? null
  uploadError.value = ''
  if (!file) {
    pendingFile.value = null
    return
  }
  if (file.size > 512 * 1024 * 1024) {
    uploadError.value = 'Ukuran file melebihi batas 512 MB.'
    pendingFile.value = null
    return
  }
  pendingFile.value = file
  customName.value = file.name.replace(/\.[^/.]+$/, '')
}

function upload() {
  if (!pendingFile.value || !customName.value.trim()) return
  uploadError.value = ''
  const taskId = store.uploadMedia(pendingFile.value, customName.value, folderId.value)
  if (!taskId) return

  pendingFile.value = null
  customName.value = ''
  uploadOpen.value = false
}

function closeUpload() {
  uploadOpen.value = false
  uploadError.value = ''
}

async function openDrive() {
  driveOpen.value = true
  driveQuery.value = ''
  await loadDriveFiles()
}

async function loadDriveFiles() {
  driveLoading.value = true
  driveError.value = ''
  driveReconnect.value = false
  try {
    driveFiles.value = await store.listDriveFiles(driveQuery.value)
  } catch (error) {
    driveFiles.value = []
    driveError.value = error instanceof Error ? error.message : 'Google Drive tidak dapat dibaca.'
    driveReconnect.value = error instanceof ApiError && Boolean((error.payload as { reconnect?: boolean })?.reconnect)
  } finally {
    driveLoading.value = false
  }
}

function importDrive(file: DriveFile) {
  store.importDriveFile(file, folderId.value)
  driveOpen.value = false
}

function openDriveSource() {
  if (!store.state.driveConnection.connected) {
    store.connectDrive()
    return
  }
  void openDrive()
}
</script>

<template>
  <div class="page-toolbar">
    <div class="search-box"><Search :size="18"/><input v-model="query" placeholder="Cari nama media..." /></div>
    <select v-model="kind" class="select-control"><option value="all">Semua tipe</option><option value="image">Foto</option><option value="video">Video</option></select>
    <div class="view-switch"><button :class="{ active: view === 'grid' }" @click="view = 'grid'"><Grid2X2 :size="18"/></button><button :class="{ active: view === 'list' }" @click="view = 'list'"><List :size="19"/></button></div>
    <button v-if="!store.isViewer" class="button" @click="createFolderOpen = true"><Folder :size="18"/> Folder Baru</button>
    <button v-if="!store.isViewer" class="button button-primary" @click="uploadOpen = true"><Upload :size="18"/> Upload Media</button>
  </div>

  <section class="storage-summary">
    <div><span class="storage-icon"><HardDriveIcon /></span><div><strong>Penyimpanan media</strong><p>{{ store.state.media.length }} file · {{ formatBytes(storageBytes) }} terpakai</p></div></div>
    <div class="storage-bar"><span :style="{ width: `${Math.max(storagePercent, store.state.media.length ? 1 : 0)}%` }" /></div>
    <small>{{ formatBytes(storageBytes) }} dari 5 GB</small>
  </section>
  <section class="drive-source-strip"><span class="demo-chip">{{ store.state.driveConnection.connected ? 'GOOGLE DRIVE' : 'BELUM AKTIF' }}</span><div><strong>{{ store.state.driveConnection.connected ? `Terhubung sebagai ${store.state.driveConnection.accountEmail}` : 'Google Drive belum dihubungkan' }}</strong><p>{{ store.state.driveConnection.connected ? 'Impor foto dan video dari Drive ke penyimpanan privat SocialSync. File asli tetap aman di Google Drive.' : 'Hubungkan Google Drive untuk mengimpor media langsung. Akses hanya baca dan token disimpan terenkripsi di backend.' }}</p></div><button v-if="store.state.driveConnection.connected && !store.isViewer" class="button" @click="openDrive"><CloudIcon :size="17"/> Impor dari Drive</button><button v-else-if="!store.state.driveConnection.connected && store.isAdmin" class="button" @click="openDriveSource"><CloudIcon :size="17"/> Hubungkan Google Drive</button><RouterLink v-else to="/accounts" class="button">Lihat integrasi akun</RouterLink></section>

  <section class="folder-section">
    <div class="mini-section-title"><div><h2>Folder</h2><span>{{ store.state.folders.length }} folder</span></div><button @click="folderId = null">Lihat semua media</button></div>
    <div class="folder-grid">
      <button v-for="folder in store.state.folders" :key="folder.id" class="folder-card" :class="{ active: folderId === folder.id }" @click="folderId = folder.id"><span><FolderOpen :size="23" /></span><div><strong>{{ folder.name }}</strong><small>{{ store.state.media.filter((media) => media.folderId === folder.id).length }} file</small></div><MoreHorizontal :size="18" /></button>
    </div>
  </section>

  <section class="media-section">
    <div class="mini-section-title"><div><h2>{{ folderId ? store.state.folders.find((folder) => folder.id === folderId)?.name : 'Semua Media' }}</h2><span>{{ media.length }} file</span></div><button><SlidersHorizontal :size="16"/> Filter lanjutan</button></div>
    <div v-if="media.length" class="media-grid" :class="{ 'media-list-view': view === 'list' }">
      <article v-for="item in media" :key="item.id" class="media-card" :class="{ selected: selected === item.id }" @click="selected = selected === item.id ? null : item.id">
        <div class="media-thumb"><img :src="item.thumbnail" :alt="item.name"/><span class="media-kind"><component :is="item.kind === 'video' ? FileVideo : Image" :size="15"/>{{ item.kind === 'video' ? `${item.duration ?? 0} dtk` : 'Foto' }}</span><button @click.stop="selected = item.id"><MoreHorizontal :size="18" /></button></div>
        <div class="media-info"><strong>{{ item.name }}</strong><p>{{ item.dimensions }} · {{ formatBytes(item.size) }}</p><span>{{ formatDate(item.createdAt) }}</span></div>
        <div v-if="selected === item.id && !store.isViewer" class="media-actions" @click.stop>
          <select :value="item.folderId ?? ''" @change="store.moveMedia(item.id, ($event.target as HTMLSelectElement).value || null)"><option value="">Semua Media</option><option v-for="folder in store.state.folders" :key="folder.id" :value="folder.id">{{ folder.name }}</option></select>
          <button @click="deleteTarget = item.id">Hapus</button>
        </div>
      </article>
    </div>
    <EmptyState v-else title="Media tidak ditemukan" message="Coba ubah kata kunci, tipe file, atau pilih folder lain." />
  </section>

  <div v-if="createFolderOpen" class="modal-backdrop" @click.self="createFolderOpen = false"><form class="modal-card compact-modal" @submit.prevent="createFolder"><button type="button" class="icon-button close-button" @click="createFolderOpen = false"><X :size="20"/></button><span class="modal-kicker">ORGANISASI DRIVE</span><h2>Buat folder baru</h2><p>Kelompokkan aset agar mudah ditemukan oleh seluruh tim.</p><label>Nama folder<input v-model="newFolderName" autofocus maxlength="60" placeholder="Contoh: Kampanye Oktober" /></label><button class="button button-primary button-full" :disabled="!newFolderName.trim()"><Plus :size="18"/> Buat Folder</button></form></div>
  <div v-if="uploadOpen" class="modal-backdrop" @click.self="closeUpload"><form class="modal-card upload-modal" @submit.prevent="upload"><button type="button" class="icon-button close-button" @click="closeUpload"><X :size="20"/></button><span class="modal-kicker">MEDIA LIBRARY · UPLOAD LATAR BELAKANG</span><h2>Upload media</h2><p>Setelah upload dimulai, jendela ini akan ditutup dan progres tetap terlihat di seluruh aplikasi. Anda bebas berpindah halaman sementara video diproses. Maksimal 512 MB per file.</p><button type="button" class="drop-zone" @click="fileInput?.click()"><Upload :size="28"/><strong>{{ pendingFile?.name ?? 'Pilih foto atau video' }}</strong><span>JPG, PNG, WEBP, MP4, atau MOV · maks. 512 MB</span></button><input ref="fileInput" hidden type="file" accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime" @change="chooseFile"/><label>Nama file di SocialSync<input v-model="customName" placeholder="Nama yang mudah dicari" /></label><div v-if="uploadError" class="login-error">{{ uploadError }}</div><button class="button button-primary button-full" :disabled="!pendingFile || !customName.trim()">Mulai upload di latar belakang</button></form></div>
  <div v-if="driveOpen" class="modal-backdrop" @click.self="driveOpen = false"><section class="modal-card media-picker-modal drive-picker-modal"><button class="icon-button close-button" @click="driveOpen = false"><X :size="20"/></button><span class="modal-kicker">GOOGLE DRIVE · AKSES BACA</span><h2>Impor media</h2><p>Pilih foto atau video dari {{ store.state.driveConnection.accountEmail || 'Google Drive' }}. Proses berjalan di latar belakang dan file akan disalin ke folder {{ folderId ? store.state.folders.find((folder) => folder.id === folderId)?.name : 'Semua Media' }}.</p><form class="drive-search" @submit.prevent="loadDriveFiles"><div class="search-box"><Search :size="17"/><input v-model="driveQuery" placeholder="Cari nama file di Drive..."/></div><button class="button" :disabled="driveLoading">Cari</button></form><div v-if="driveError" class="drive-error"><span>{{ driveError }}</span><button v-if="driveReconnect && store.isAdmin" class="button button-primary" @click="store.connectDrive">Hubungkan ulang</button></div><div v-if="driveLoading" class="drive-loading"><LoaderIcon :size="22"/> Memuat file Google Drive...</div><div v-else-if="driveFiles.length" class="drive-file-list"><button v-for="file in driveFiles" :key="file.id" class="drive-file-row" @click="importDrive(file)"><span class="drive-file-icon"><component :is="file.mimeType.startsWith('video/') ? FileVideo : Image" :size="20"/></span><div><strong>{{ file.name }}</strong><span>{{ file.mimeType.startsWith('video/') ? 'Video' : 'Foto' }} · {{ formatBytes(file.size) }} · {{ file.modifiedTime ? formatDate(file.modifiedTime) : 'Tanggal tidak tersedia' }}</span></div><b>Impor</b></button></div><EmptyState v-else-if="!driveError" title="Tidak ada media" message="Tidak ada foto atau video yang cocok di Google Drive." /></section></div>
  <ConfirmDialog :open="Boolean(deleteTarget)" title="Hapus File Ini?" :message="`File ${store.state.media.find((item) => item.id === deleteTarget)?.name ?? ''} akan dihapus permanen dari penyimpanan server.`" confirm-label="Ya, Hapus" danger @close="deleteTarget = null" @confirm="deleteTarget && store.deleteMedia(deleteTarget); deleteTarget = null" />
</template>

<script lang="ts">
import { HardDrive as HardDriveIcon } from 'lucide-vue-next'
</script>

<style scoped>
.login-error { padding: 9px 11px; border: 1px solid #f0caca; border-radius: 8px; background: #fff5f5; color: #b23b3b; font-size: 12px; }
.drive-search { display: flex; gap: 8px; margin: 16px 0 12px; }
.drive-search .search-box { flex: 1; }
.drive-error { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 12px; border: 1px solid #f0caca; border-radius: 8px; background: #fff5f5; color: #b23b3b; font-size: 12px; }
.drive-loading { min-height: 120px; display: flex; align-items: center; justify-content: center; gap: 9px; color: #728096; font-size: 12px; }
.drive-loading svg { animation: drive-spin 1s linear infinite; }
@keyframes drive-spin { to { transform: rotate(360deg); } }
.drive-file-list { max-height: 380px; overflow-y: auto; display: grid; gap: 6px; }
.drive-file-row { width: 100%; padding: 10px; display: flex; align-items: center; gap: 10px; border: 1px solid #e4e9f0; border-radius: 9px; background: #fff; text-align: left; }
.drive-file-row:hover { border-color: #b9cbe0; background: #f7fafd; }
.drive-file-row:disabled { opacity: .65; cursor: wait; }
.drive-file-icon { width: 36px; height: 36px; flex: 0 0 auto; display: grid; place-items: center; border-radius: 8px; background: #eaf1f9; color: #3f72af; }
.drive-file-row > div { min-width: 0; flex: 1; display: grid; gap: 2px; }
.drive-file-row strong { color: #112d4e; font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.drive-file-row div span { color: #728096; font-size: 10px; }
.drive-file-row b { color: #3f72af; font: 800 10px 'Manrope', sans-serif; white-space: nowrap; }
</style>
