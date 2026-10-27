import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { ApiError, download, loginRequest, request, uploadFile } from '../api'
import type { AppNotification, AppState, ContentType, DriveFile, Folder, MediaFile, MediaTask, Platform, Post, SocialAccount, User } from '../types'

const emptyUser: User = {
  id: '',
  name: '',
  email: '',
  role: 'Viewer',
  active: false,
  initials: '',
  passwordTemplate: false,
}

function emptyState(): AppState {
  return {
    version: 1,
    activeUserId: '',
    users: [],
    folders: [],
    media: [],
    mediaTasks: [],
    posts: [],
    accounts: [],
    bufferConnection: { configured: false, status: 'unconfigured', accountName: '', accountEmail: '', organizationName: '', channelCount: 0, maxChannels: 3, limitReached: false, manageUrl: 'https://publish.buffer.com/channels', lastSyncedAt: null, lastError: null },
    driveConnection: { provider: 'google-drive', accountEmail: '', connected: false, connectedAt: null },
    notifications: [],
    activities: [],
  }
}

function errorMessage(error: unknown): string {
  if (error instanceof ApiError) return error.message
  if (error instanceof Error) return error.message
  return 'Terjadi kesalahan yang tidak terduga.'
}

export const useAppStore = defineStore('app', () => {
  const state = ref<AppState>(emptyState())
  const toast = ref<{ type: AppNotification['type']; title: string; message: string } | null>(null)
  const authenticated = ref(false)
  const ready = ref(false)
  const refreshing = ref(false)
  const localMediaTasks = ref<MediaTask[]>([])
  const dismissedMediaTaskIds = ref(new Set<string>())
  let pollTimer: number | undefined

  const activeUser = computed(() => state.value.users.find((user) => user.id === state.value.activeUserId) ?? state.value.users[0] ?? emptyUser)
  const unreadCount = computed(() => state.value.notifications.filter((notification) => !notification.read).length)
  const isViewer = computed(() => activeUser.value.role === 'Viewer')
  const isAdmin = computed(() => activeUser.value.role === 'Admin')
  const hasPublishing = computed(() => state.value.posts.some((post) => post.status === 'publishing'))
  const mediaTasks = computed(() => {
    const serverIds = new Set(state.value.mediaTasks.map((task) => task.id))
    return [...localMediaTasks.value.filter((task) => !serverIds.has(task.id)), ...state.value.mediaTasks]
      .filter((task) => !dismissedMediaTaskIds.value.has(task.id))
      .sort((left, right) => right.createdAt.localeCompare(left.createdAt))
  })
  const hasActiveMediaTasks = computed(() => mediaTasks.value.some((task) => ['uploading', 'queued', 'processing'].includes(task.status)))

  function notify(type: AppNotification['type'], title: string, message: string, duration = 4200) {
    toast.value = { type, title, message }
    window.setTimeout(() => {
      if (toast.value?.title === title) toast.value = null
    }, duration)
  }

  function notifyError(error: unknown, title = 'Permintaan gagal') {
    if (error instanceof ApiError && error.status === 401) {
      authenticated.value = false
      stopPolling()
    }
    notify('error', title, errorMessage(error))
  }

  function applyState(next: AppState) {
    state.value = {
      ...next,
      mediaTasks: next.mediaTasks ?? [],
      bufferConnection: next.bufferConnection ?? emptyState().bufferConnection,
    }
    authenticated.value = true
  }

  async function bootstrap() {
    try {
      const data = await request<AppState>('/api/bootstrap')
      applyState(data)
      startPolling()
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        authenticated.value = false
      } else if (error instanceof ApiError && error.status !== 0) {
        notifyError(error, 'Backend tidak dapat dihubungi')
      }
    } finally {
      ready.value = true
    }
  }

  async function login(email: string, password: string) {
    const result = (await loginRequest(email, password)) as { state: AppState }
    applyState(result.state)
    startPolling()
    return result.state
  }

  async function logout() {
    try {
      await request('/api/auth/logout', { method: 'POST' })
    } catch {
      // The local session is cleared even when the network request fails.
    } finally {
      stopPolling()
      authenticated.value = false
      state.value = emptyState()
    }
  }

  async function refreshState(silent = true) {
    if (!authenticated.value || refreshing.value) return
    refreshing.value = true
    try {
      const data = await request<AppState>('/api/bootstrap')
      applyState(data)
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        authenticated.value = false
        stopPolling()
      } else if (!silent) {
        notifyError(error, 'Gagal memuat data')
      }
    } finally {
      refreshing.value = false
    }
  }

  function startPolling() {
    stopPolling()
    const poll = async () => {
      if (!authenticated.value) return
      if (document.visibilityState === 'visible') await refreshState(true)
      pollTimer = window.setTimeout(poll, hasPublishing.value || hasActiveMediaTasks.value ? 1200 : 15000)
    }
    pollTimer = window.setTimeout(poll, hasPublishing.value || hasActiveMediaTasks.value ? 1200 : 15000)
  }

  function stopPolling() {
    if (pollTimer) window.clearTimeout(pollTimer)
    pollTimer = undefined
  }

  function setActiveUser() {
    notify('info', 'Sesi nyata aktif', 'Peran mengikuti akun yang sedang login. Keluar untuk menggunakan akun lain.')
  }

  async function addFolder(name: string): Promise<Folder | null> {
    try {
      const folder = await request<Folder>('/api/folders', {
        method: 'POST',
        body: JSON.stringify({ name: name.trim() }),
      })
      state.value.folders.push(folder)
      notify('success', 'Folder dibuat', `${folder.name} siap digunakan.`)
      return folder
    } catch (error) {
      notifyError(error, 'Folder gagal dibuat')
      return null
    }
  }

  async function renameFolder(folderId: string, name: string) {
    try {
      const folder = await request<Folder>(`/api/folders/${folderId}`, {
        method: 'PATCH',
        body: JSON.stringify({ name: name.trim() }),
      })
      const index = state.value.folders.findIndex((item) => item.id === folderId)
      if (index >= 0) state.value.folders[index] = folder
    } catch (error) {
      notifyError(error, 'Folder gagal diubah')
    }
  }

  async function deleteFolder(folderId: string) {
    try {
      await request(`/api/folders/${folderId}`, { method: 'DELETE' })
      state.value.folders = state.value.folders.filter((folder) => folder.id !== folderId)
      state.value.media.forEach((media) => {
        if (media.folderId === folderId) media.folderId = null
      })
      notify('success', 'Folder dihapus', 'File di dalamnya dipindahkan ke Semua Media.')
    } catch (error) {
      notifyError(error, 'Folder gagal dihapus')
    }
  }

  function uploadMedia(
    file: File,
    name: string,
    folderId: string | null,
  ): string | null {
    if (file.size > 512 * 1024 * 1024) {
      notify('error', 'Upload gagal', 'Ukuran file melebihi batas 512 MB.')
      return null
    }

    const taskId = `upload-${crypto.randomUUID()}`
    const task: MediaTask = {
      id: taskId,
      source: 'upload',
      status: 'uploading',
      progress: 0,
      name: name.trim(),
      error: null,
      createdAt: new Date().toISOString(),
    }
    localMediaTasks.value.unshift(task)
    startPolling()

    void (async () => {
      try {
        const result = await uploadFile(file, task.name, folderId, (progress) => {
          task.progress = Math.min(Math.round(progress * 0.6), 60)
        }) as MediaFile | { task: MediaTask }

        localMediaTasks.value = localMediaTasks.value.filter((item) => item.id !== taskId)

        if (isMediaTaskResponse(result)) {
          const serverTask = normaliseMediaTask(result.task, task)
          upsertMediaTask(serverTask)
          notify('info', 'Media masuk antrean', `${serverTask.name} diproses di belakang layar.`)
        } else {
          state.value.media.unshift(result)
          notify('success', 'Media ditambahkan', `${result.name} tersimpan di Drive.`)
        }
      } catch (error) {
        task.status = 'failed'
        task.error = errorMessage(error)
        notifyError(error, 'Upload gagal')
      }
    })()

    return taskId
  }

  async function renameMedia(mediaId: string, name: string) {
    try {
      const media = await request<MediaFile>(`/api/media/${mediaId}`, {
        method: 'PATCH',
        body: JSON.stringify({ name: name.trim() }),
      })
      replaceMedia(media)
    } catch (error) {
      notifyError(error, 'Nama media gagal diubah')
    }
  }

  async function moveMedia(mediaId: string, folderId: string | null) {
    try {
      const media = await request<MediaFile>(`/api/media/${mediaId}`, {
        method: 'PATCH',
        body: JSON.stringify({ folderId }),
      })
      replaceMedia(media)
      notify('success', 'Media dipindahkan', `${media.name} berhasil dipindahkan.`)
    } catch (error) {
      notifyError(error, 'Media gagal dipindahkan')
    }
  }

  async function deleteMedia(mediaId: string): Promise<boolean> {
    try {
      await request(`/api/media/${mediaId}`, { method: 'DELETE' })
      state.value.media = state.value.media.filter((media) => media.id !== mediaId)
      notify('success', 'Media dihapus', 'File telah dihapus dari Drive.')
      return true
    } catch (error) {
      notifyError(error, 'Media tidak dapat dihapus')
      return false
    }
  }

  async function savePost(input: {
    id?: string
    title: string
    type: ContentType
    caption: string
    hashtags: string
    mediaIds: string[]
    platforms: Platform[]
    scheduledAt: string | null
    settings: Partial<Record<Platform, Record<string, string | boolean>>>
    asDraft?: boolean
  }): Promise<Post | null> {
    const payload = {
      title: input.title,
      type: input.type,
      caption: input.caption,
      hashtags: input.hashtags,
      mediaIds: input.mediaIds,
      platforms: input.platforms,
      scheduledAt: input.scheduledAt,
      settings: input.settings,
      asDraft: input.asDraft ?? false,
    }

    try {
      const post = input.id
        ? await request<Post>(`/api/posts/${input.id}`, { method: 'PUT', body: JSON.stringify(payload) })
        : await request<Post>('/api/posts', { method: 'POST', body: JSON.stringify(payload) })

      const index = state.value.posts.findIndex((item) => item.id === post.id)
      if (index >= 0) state.value.posts[index] = post
      else state.value.posts.unshift(post)

      if (post.status === 'scheduled') {
        notify('success', 'Post dijadwalkan', `${post.title} masuk ke kalender.`)
      } else if (post.status === 'publishing') {
        notify('info', 'Masuk antrean publish', `${post.title} sedang dikirim ke platform terpilih.`)
      }

      return post
    } catch (error) {
      notifyError(error, 'Post gagal disimpan')
      return null
    }
  }

  async function deleteDraft(postId: string) {
    try {
      await request(`/api/posts/${postId}`, { method: 'DELETE' })
      state.value.posts = state.value.posts.filter((post) => post.id !== postId)
      notify('success', 'Draft dihapus', 'Konten telah dihapus.')
    } catch (error) {
      notifyError(error, 'Draft gagal dihapus')
    }
  }

  async function cancelPost(postId: string) {
    try {
      const post = await request<Post>(`/api/posts/${postId}/cancel`, { method: 'POST' })
      const index = state.value.posts.findIndex((item) => item.id === postId)
      if (index >= 0) state.value.posts[index] = post
      notify('success', 'Jadwal dibatalkan', `${post.title} tidak akan dipublikasikan.`)
    } catch (error) {
      notifyError(error, 'Jadwal gagal dibatalkan')
    }
  }

  async function markNotification(notificationId: string) {
    const notification = state.value.notifications.find((item) => item.id === notificationId)
    if (notification) notification.read = true
    try {
      await request(`/api/notifications/${notificationId}`, { method: 'PATCH' })
    } catch (error) {
      notifyError(error, 'Notifikasi gagal diperbarui')
    }
  }

  async function markAllNotifications() {
    state.value.notifications.forEach((notification) => { notification.read = true })
    try {
      await request('/api/notifications/read-all', { method: 'POST' })
    } catch (error) {
      notifyError(error, 'Notifikasi gagal diperbarui')
    }
  }

  async function syncBufferChannels() {
    try {
      const result = await request<{ accounts: SocialAccount[]; bufferConnection: AppState['bufferConnection'] }>('/api/buffer/sync', { method: 'POST' })
      state.value.accounts = result.accounts
      state.value.bufferConnection = result.bufferConnection
      notify('success', 'Channel Buffer tersinkron', `${result.accounts.length} channel siap digunakan untuk publikasi.`)
    } catch (error) {
      notifyError(error, 'Sinkronisasi Buffer gagal')
    }
  }

  function openBufferManager() {
    if (state.value.bufferConnection.limitReached) return
    window.open(state.value.bufferConnection.manageUrl, '_blank', 'noopener,noreferrer')
  }

  function connectDrive() {
    window.location.href = '/api/drive/google/redirect'
  }

  async function disconnectDrive() {
    try {
      await request('/api/drive/disconnect', { method: 'POST' })
      state.value.driveConnection = {
        provider: 'google-drive',
        accountEmail: '',
        connected: false,
        connectedAt: null,
      }
      notify('success', 'Google Drive diputus', 'Token akses Google Drive telah dihapus dari server.')
    } catch (error) {
      notifyError(error, 'Google Drive gagal diputus')
    }
  }

  async function listDriveFiles(search = ''): Promise<DriveFile[]> {
    const result = await request<{ files: DriveFile[] }>(
      `/api/drive/files${search.trim() ? `?search=${encodeURIComponent(search.trim())}` : ''}`,
    )
    return result.files
  }

  function importDriveFile(file: DriveFile, folderId: string | null): string {
    const taskId = `drive-${crypto.randomUUID()}`
    const task: MediaTask = {
      id: taskId,
      source: 'google-drive',
      status: 'queued',
      progress: 0,
      name: file.name.replace(/\.[^/.]+$/, ''),
      error: null,
      createdAt: new Date().toISOString(),
    }
    localMediaTasks.value.unshift(task)
    startPolling()

    void (async () => {
      try {
        const result = await request<{ task: MediaTask }>('/api/drive/import', {
          method: 'POST',
          body: JSON.stringify({ fileId: file.id, name: task.name, folderId }),
        })
        if (!isMediaTaskResponse(result)) {
          throw new Error('Server tidak mengembalikan status task impor yang valid.')
        }

        const serverTask = normaliseMediaTask(result.task, task)
        localMediaTasks.value = localMediaTasks.value.filter((item) => item.id !== taskId)
        upsertMediaTask(serverTask)
        notify('info', 'Impor dimulai', `${serverTask.name} sedang disalin dari Google Drive.`)
      } catch (error) {
        task.status = 'failed'
        task.error = errorMessage(error)
        notifyError(error, 'Impor Google Drive gagal')
      }
    })()

    return taskId
  }

  function upsertMediaTask(task: MediaTask) {
    const index = state.value.mediaTasks.findIndex((item) => item.id === task.id)
    if (index >= 0) state.value.mediaTasks[index] = task
    else state.value.mediaTasks.unshift(task)
  }

  function isMediaTaskResponse(value: unknown): value is { task: MediaTask } {
    if (!value || typeof value !== 'object' || !('task' in value)) return false
    const task = (value as { task?: Partial<MediaTask> }).task
    return Boolean(task && typeof task.id === 'string' && typeof task.status === 'string')
  }

  function normaliseMediaTask(task: MediaTask, fallback: MediaTask): MediaTask {
    return {
      ...fallback,
      ...task,
      name: typeof task.name === 'string' && task.name.trim() ? task.name : fallback.name,
      progress: Number.isFinite(task.progress) ? Math.max(0, Math.min(100, task.progress)) : fallback.progress,
      error: task.error ?? null,
      createdAt: task.createdAt || fallback.createdAt,
    }
  }

  function dismissMediaTask(taskId: string) {
    dismissedMediaTaskIds.value = new Set([...dismissedMediaTaskIds.value, taskId])
    localMediaTasks.value = localMediaTasks.value.filter((task) => task.id !== taskId)
  }

  async function toggleUser(userId: string) {
    const user = state.value.users.find((item) => item.id === userId)
    if (!user) return
    try {
      const updated = await request<User>(`/api/users/${userId}`, {
        method: 'PATCH',
        body: JSON.stringify({ active: !user.active }),
      })
      const index = state.value.users.findIndex((item) => item.id === userId)
      if (index >= 0) state.value.users[index] = updated
    } catch (error) {
      notifyError(error, 'Status pengguna gagal diubah')
    }
  }

  async function reset() {
    try {
      const data = await request<AppState>('/api/settings/reset', { method: 'POST' })
      applyState(data)
      notify('success', 'Workspace direset', 'Data operasional dibersihkan dan folder contoh dibuat ulang.')
    } catch (error) {
      notifyError(error, 'Reset gagal')
    }
  }

  async function exportData() {
    try {
      await download('/api/settings/export', `socialsync-backup-${new Date().toISOString().slice(0, 10)}.json`)
    } catch (error) {
      notifyError(error, 'Ekspor gagal')
    }
  }

  async function importData() {
    notify('info', 'Impor belum tersedia', 'Gunakan ekspor sebagai arsip. Restore penuh akan ditambahkan pada rilis berikutnya.')
  }

  function replaceMedia(media: MediaFile) {
    const index = state.value.media.findIndex((item) => item.id === media.id)
    if (index >= 0) state.value.media[index] = media
  }

  return {
    state,
    toast,
    ready,
    authenticated,
    activeUser,
    unreadCount,
    isViewer,
    isAdmin,
    hasPublishing,
    mediaTasks,
    hasActiveMediaTasks,
    bootstrap,
    refreshState,
    login,
    logout,
    setActiveUser,
    addFolder,
    renameFolder,
    deleteFolder,
    uploadMedia,
    renameMedia,
    moveMedia,
    deleteMedia,
    savePost,
    deleteDraft,
    cancelPost,
    markNotification,
    markAllNotifications,
    syncBufferChannels,
    openBufferManager,
    connectDrive,
    disconnectDrive,
    listDriveFiles,
    importDriveFile,
    dismissMediaTask,
    toggleUser,
    reset,
    exportData,
    importData,
    notify,
  }
})
