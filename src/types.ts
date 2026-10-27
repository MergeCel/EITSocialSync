export type Platform = 'instagram' | 'tiktok' | 'facebook'
export type ContentType = 'story' | 'video' | 'photo' | 'carousel'
export type PostStatus = 'draft' | 'scheduled' | 'publishing' | 'published' | 'failed' | 'partial' | 'cancelled'
export type Role = 'Admin' | 'Editor' | 'Viewer'

export interface User {
  id: string
  name: string
  email: string
  role: Role
  active: boolean
  initials: string
  passwordTemplate: boolean
}

export interface DriveConnection {
  provider: 'google-drive'
  accountEmail: string
  connected: boolean
  connectedAt: string | null
}

export interface DriveFile {
  id: string
  name: string
  mimeType: string
  size: number
  modifiedTime: string | null
  iconLink: string | null
}

export interface Folder {
  id: string
  name: string
  parentId: string | null
  createdAt: string
}

export interface MediaFile {
  id: string
  folderId: string | null
  name: string
  originalName: string
  kind: 'image' | 'video'
  mimeType: string
  size: number
  duration?: number
  dimensions: string
  thumbnail: string
  uploadedBy: string
  createdAt: string
}

export interface BufferConnectionState {
  configured: boolean
  status: 'active' | 'disabled' | 'error' | 'unconfigured'
  accountName: string
  accountEmail: string
  organizationName: string
  channelCount: number
  maxChannels: number
  limitReached: boolean
  manageUrl: string
  lastSyncedAt: string | null
  lastError: string | null
}

export interface MediaTask {
  id: string
  source: 'upload' | 'google-drive'
  status: 'uploading' | 'queued' | 'processing' | 'completed' | 'failed'
  progress: number
  name: string
  error: string | null
  createdAt: string
}

export interface PlatformResult {
  platform: Platform
  status: 'pending' | 'processing' | 'success' | 'failed' | 'cancelled'
  platformPostId?: string
  publishedAt?: string
  error?: string
  settings: Record<string, string | boolean>
}

export interface Metrics {
  views: number
  likes: number
  comments: number
  shares: number
  saves: number
  fetchedAt: string
}

export interface Post {
  id: string
  title: string
  type: ContentType
  caption: string
  hashtags: string
  mediaIds: string[]
  status: PostStatus
  scheduledAt: string | null
  createdAt: string
  createdBy: string
  platforms: PlatformResult[]
  metrics: Partial<Record<Platform, Metrics>>
}

export interface SocialAccount {
  id: string
  platform: Platform
  accountName: string
  handle: string
  status: 'connected' | 'expired' | 'error' | 'disconnected'
  expiresAt: string | null
  followers: number
}

export interface AppNotification {
  id: string
  type: 'success' | 'error' | 'warning' | 'info'
  title: string
  message: string
  read: boolean
  createdAt: string
}

export interface Activity {
  id: string
  actor: string
  action: string
  subject: string
  createdAt: string
}

export interface AppState {
  version: 1
  activeUserId: string
  users: User[]
  folders: Folder[]
  media: MediaFile[]
  mediaTasks: MediaTask[]
  posts: Post[]
  accounts: SocialAccount[]
  bufferConnection: BufferConnectionState
  driveConnection: DriveConnection
  notifications: AppNotification[]
  activities: Activity[]
}

export const platformLabels: Record<Platform, string> = {
  instagram: 'Instagram',
  tiktok: 'TikTok',
  facebook: 'Facebook',
}

export const contentLabels: Record<ContentType, string> = {
  story: 'Story',
  video: 'Video',
  photo: 'Foto',
  carousel: 'Carousel',
}
