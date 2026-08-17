export type MediaType = 'image' | 'video'

export interface MediaUrls {
  thumb?: string
  display?: string
  full?: string
  poster?: string
  stream?: string
}

export interface Media {
  id: string
  type: MediaType
  width: number | null
  height: number | null
  aspect_ratio: number | null
  duration_ms: number | null
  /** A tiny inline image, shown until the real one has decoded. */
  placeholder: string | null
  urls: MediaUrls
}

/** A memory as it appears while scrolling: no description, a few media. */
export interface TimelineMemory {
  id: string
  title: string
  memory_date: string
  year: number
  month: number
  location: string | null
  media_count: number
  preview: Media[]
}

/** A memory as it appears when opened. */
export interface Memory {
  id: string
  title: string
  description: string | null
  memory_date: string
  year: number
  location: string | null
  media_count: number
  media: Media[]
  created_at: string | null
}

export interface TimelinePage {
  data: TimelineMemory[]
  meta: {
    next_cursor: string | null
    has_more: boolean
  }
}

export interface YearCount {
  year: number
  count: number
}

export interface Archive {
  title: string
  quote: string
  public: boolean
  can_manage: boolean
  storage_connected: boolean
  upload: {
    chunk_bytes: number
    max_files: number
    max_image_bytes: number
    max_video_bytes: number
    accepts: string[]
  }
}

export interface UploadSession {
  id: string
  status: 'pending' | 'ready' | 'consumed' | 'failed' | 'expired'
  type: MediaType | null
  chunk_size: number
  total_chunks: number
  received_chunks: number
  missing_chunks: number[]
  expires_at: string
}

export interface MemoryInput {
  title: string
  description?: string | null
  memory_date: string
  location?: string | null
}
