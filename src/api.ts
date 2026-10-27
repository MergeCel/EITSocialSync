const API_BASE = (import.meta.env.VITE_API_BASE_URL as string | undefined)?.replace(/\/$/, '') ?? ''

export class ApiError extends Error {
  status: number
  payload: unknown
  errors: Record<string, string[]>

  constructor(status: number, payload: unknown, message: string) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.payload = payload
    this.errors = (payload as { errors?: Record<string, string[]> })?.errors ?? {}
  }
}

function cookie(name: string): string | null {
  const match = document.cookie.split('; ').find((entry) => entry.startsWith(`${name}=`))
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null
}

export async function ensureCsrf(): Promise<void> {
  if (cookie('XSRF-TOKEN')) return
  await fetch(`${API_BASE}/sanctum/csrf-cookie`, {
    credentials: 'include',
    headers: { Accept: 'application/json' },
  })
}

function xsrfHeader(): Record<string, string> {
  const token = cookie('XSRF-TOKEN')
  return token ? { 'X-XSRF-TOKEN': token } : {}
}

function messageFromPayload(payload: unknown, status: number): string {
  const body = payload as { message?: string; errors?: Record<string, string[]> }
  const firstError = body?.errors ? Object.values(body.errors).flat()[0] : undefined
  return firstError ?? body?.message ?? `Permintaan gagal (${status}).`
}

async function parse(response: Response): Promise<unknown> {
  const text = await response.text()
  if (!text) return null
  try {
    return JSON.parse(text)
  } catch {
    return text
  }
}

export async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const method = (init.method ?? 'GET').toUpperCase()
  if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) await ensureCsrf()

  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  if (init.body && !(init.body instanceof FormData)) headers.set('Content-Type', 'application/json')
  Object.entries(xsrfHeader()).forEach(([key, value]) => headers.set(key, value))

  const response = await fetch(`${API_BASE}${path}`, {
    ...init,
    method,
    headers,
    credentials: 'include',
  })
  const payload = await parse(response)

  if (!response.ok) {
    throw new ApiError(response.status, payload, messageFromPayload(payload, response.status))
  }

  return payload as T
}

export async function loginRequest(email: string, password: string): Promise<unknown> {
  await ensureCsrf()
  return request('/api/auth/login', {
    method: 'POST',
    body: JSON.stringify({ email, password }),
  })
}

export function uploadFile(
  file: File,
  name: string,
  folderId: string | null,
  onProgress?: (percent: number) => void,
  signal?: AbortSignal,
): Promise<unknown> {
  return new Promise((resolve, reject) => {
    void (async () => {
      try {
        await ensureCsrf()
        const form = new FormData()
        form.append('file', file)
        form.append('name', name)
        if (folderId) form.append('folderId', folderId)

        const xhr = new XMLHttpRequest()
        xhr.open('POST', `${API_BASE}/api/media`)
        xhr.withCredentials = true
        xhr.setRequestHeader('Accept', 'application/json')
        Object.entries(xsrfHeader()).forEach(([key, value]) => xhr.setRequestHeader(key, value))

        xhr.upload.onprogress = (event) => {
          if (event.lengthComputable) onProgress?.(Math.round((event.loaded / event.total) * 100))
        }
        xhr.onerror = () => reject(new ApiError(0, null, 'Koneksi ke server terputus saat upload.'))
        xhr.onabort = () => reject(new DOMException('Upload dibatalkan.', 'AbortError'))
        xhr.onload = () => {
          let payload: unknown = null
          try {
            payload = xhr.responseText ? JSON.parse(xhr.responseText) : null
          } catch {
            payload = xhr.responseText
          }
          if (xhr.status >= 200 && xhr.status < 300) {
            resolve(payload)
            return
          }
          reject(new ApiError(xhr.status, payload, messageFromPayload(payload, xhr.status)))
        }

        signal?.addEventListener('abort', () => xhr.abort(), { once: true })
        xhr.send(form)
      } catch (error) {
        reject(error)
      }
    })()
  })
}

export async function download(path: string, filename: string): Promise<void> {
  const response = await fetch(`${API_BASE}${path}`, { credentials: 'include', headers: { Accept: 'application/json' } })
  if (!response.ok) throw new ApiError(response.status, await parse(response), 'Ekspor gagal.')
  const blob = await response.blob()
  const link = document.createElement('a')
  link.href = URL.createObjectURL(blob)
  link.download = filename
  link.click()
  URL.revokeObjectURL(link.href)
}
