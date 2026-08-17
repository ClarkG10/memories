import { useCallback, useEffect, useRef, useState } from 'react'
import { ApiError } from '../api/client'
import { discardUpload, uploadFile } from '../api/upload'
import type { Archive } from '../api/types'

export interface PendingFile {
  id: string
  file: File
  previewUrl: string
  kind: 'image' | 'video'
  status: 'ready' | 'uploading' | 'uploaded' | 'failed'
  progress: number
  /** Set once the server holds the whole file; a retry then skips it. */
  sessionId?: string
  error?: string
}

const IMAGE_EXTENSIONS = /\.(jpe?g|png|webp|gif|heic|heif)$/i
const VIDEO_EXTENSIONS = /\.(mp4|mov|webm|mkv|m4v)$/i

/**
 * The files someone has chosen, and the business of getting them to the server.
 *
 * Two things this owes the person: never lose their selection when something
 * fails, and never send the same file twice. A file that uploaded successfully
 * keeps its session id, so pressing "Try again" after a failure resumes from
 * where it stopped rather than re-sending a video that already arrived.
 *
 * The list is held in a ref as well as in state. The upload loop is a long
 * async walk over that list and needs to read the current version at each
 * step, which a value captured from a render cannot give it.
 */
export function useUploadQueue(archive: Archive | undefined) {
  const [files, setFiles] = useState<PendingFile[]>([])
  const [rejections, setRejections] = useState<string[]>([])

  const filesRef = useRef<PendingFile[]>([])
  const abortRef = useRef<AbortController | null>(null)

  const commit = useCallback((next: PendingFile[]) => {
    filesRef.current = next
    setFiles(next)
  }, [])

  const patch = useCallback(
    (id: string, changes: Partial<PendingFile>) => {
      commit(filesRef.current.map((item) => (item.id === id ? { ...item, ...changes } : item)))
    },
    [commit],
  )

  // Object URLs hold on to the file until they are handed back.
  useEffect(() => {
    return () => {
      for (const item of filesRef.current) URL.revokeObjectURL(item.previewUrl)
    }
  }, [])

  const add = useCallback(
    (incoming: FileList | File[]) => {
      const max = archive?.upload.max_files ?? 40
      const current = filesRef.current
      const room = Math.max(0, max - current.length)

      // Choosing the same file twice is a slip, not an intention.
      const seen = new Set(current.map((item) => item.id))

      const fresh: PendingFile[] = []
      const refused: string[] = []

      for (const file of Array.from(incoming)) {
        const kind = classify(file)

        if (kind === null) {
          refused.push(`${file.name} isn't a photo or a video.`)
          continue
        }

        const limit =
          kind === 'video' ? archive?.upload.max_video_bytes : archive?.upload.max_image_bytes

        if (limit && file.size > limit) {
          refused.push(`${file.name} is larger than this archive accepts.`)
          continue
        }

        const id = `${file.name}-${file.size}-${file.lastModified}`

        if (seen.has(id)) continue

        if (fresh.length >= room) {
          refused.push(`A memory can hold up to ${max} photos and videos.`)
          break
        }

        seen.add(id)

        fresh.push({
          id,
          file,
          // Only created for files that are kept, so nothing is leaked for one
          // that was turned away.
          previewUrl: URL.createObjectURL(file),
          kind,
          status: 'ready',
          progress: 0,
        })
      }

      setRejections(refused)
      if (fresh.length > 0) commit([...current, ...fresh])
    },
    [archive, commit],
  )

  const remove = useCallback(
    (id: string) => {
      const target = filesRef.current.find((item) => item.id === id)

      if (target) {
        URL.revokeObjectURL(target.previewUrl)

        // If it already reached the server, let the server let it go too.
        if (target.sessionId) void discardUpload(target.sessionId)
      }

      commit(filesRef.current.filter((item) => item.id !== id))
    },
    [commit],
  )

  const reset = useCallback(() => {
    for (const item of filesRef.current) URL.revokeObjectURL(item.previewUrl)

    commit([])
    setRejections([])
  }, [commit])

  const cancel = useCallback(() => {
    abortRef.current?.abort()
  }, [])

  /**
   * Send everything that has not already arrived, in order, and return the
   * session ids in the order the files were chosen.
   */
  const uploadAll = useCallback(async (): Promise<string[]> => {
    const controller = new AbortController()
    abortRef.current = controller

    const sessionIds: string[] = []

    for (const item of filesRef.current) {
      /*
       | Skip only what genuinely finished. A session id alone is not enough
       | any more — one is recorded as soon as the upload starts, so that a
       | retry can resume it — and treating that as "done" would hand the
       | server a half-arrived file.
       */
      if (item.status === 'uploaded' && item.sessionId) {
        sessionIds.push(item.sessionId)
        continue
      }

      patch(item.id, { status: 'uploading', progress: 0, error: undefined })

      try {
        const sessionId = await uploadFile(item.file, {
          signal: controller.signal,
          onProgress: ({ fraction }) => patch(item.id, { progress: fraction }),

          /*
           | Remembered as soon as the server has it, not only once the file
           | has fully arrived — that is what lets a retry pick up where the
           | last attempt stopped instead of re-sending the whole video.
           */
          onSession: (id) => patch(item.id, { sessionId: id }),
          resume: item.sessionId,
        })

        patch(item.id, { status: 'uploaded', progress: 1, sessionId })
        sessionIds.push(sessionId)
      } catch (error) {
        const message =
          error instanceof ApiError ? error.message : 'That file could not be uploaded.'

        patch(item.id, { status: 'failed', error: message })

        throw error
      }
    }

    return sessionIds
  }, [patch])

  const isUploading = files.some((item) => item.status === 'uploading')

  const progress =
    files.length === 0
      ? 0
      : files.reduce((total, item) => total + (item.status === 'uploaded' ? 1 : item.progress), 0) /
        files.length

  return { files, rejections, add, remove, reset, cancel, uploadAll, isUploading, progress }
}

function classify(file: File): 'image' | 'video' | null {
  if (file.type.startsWith('image/')) return 'image'
  if (file.type.startsWith('video/')) return 'video'

  // Phones sometimes hand over a file with no type at all (HEIC, most often),
  // so fall back to the name. The server decides for certain from the bytes.
  if (IMAGE_EXTENSIONS.test(file.name)) return 'image'
  if (VIDEO_EXTENSIONS.test(file.name)) return 'video'

  return null
}
