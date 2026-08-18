/** Longest edge of the captured frame. Big enough for a retina card, small
 *  enough that it costs nothing to send. */
const MAX_EDGE = 1280

/** Where to grab from: far enough in to miss a black opening frame. */
const SEEK_FRACTION = 0.1
const SEEK_CEILING_SECONDS = 2

/**
 * Take a still from a video the person has just chosen, in their browser.
 *
 * Google generates its own thumbnail for a video, but only once it has
 * finished processing the upload — which can be a minute or more. Until then a
 * memory has nothing to show but a play button on an empty rectangle, which is
 * a poor way to meet a video you wanted to remember.
 *
 * The browser already has the file open to preview it, so the frame is free.
 *
 * Returns null whenever the browser cannot decode the video — some phone
 * codecs, HEVC especially — in which case Drive's own thumbnail is used later.
 */
export async function capturePosterFrame(file: File): Promise<string | null> {
  const url = URL.createObjectURL(file)

  try {
    return await new Promise<string | null>((resolve) => {
      const video = document.createElement('video')
      let settled = false

      const done = (value: string | null) => {
        if (settled) return
        settled = true
        video.removeAttribute('src')
        video.load()
        resolve(value)
      }

      // A video that never loads must not hold up the upload.
      const timeout = window.setTimeout(() => done(null), 8000)

      video.muted = true
      video.playsInline = true
      video.preload = 'metadata'
      video.src = url

      video.onerror = () => {
        window.clearTimeout(timeout)
        done(null)
      }

      video.onloadedmetadata = () => {
        const target = Math.min(video.duration * SEEK_FRACTION, SEEK_CEILING_SECONDS)

        // A zero-length or unseekable stream still gives us frame one.
        video.currentTime = Number.isFinite(target) && target > 0 ? target : 0
      }

      video.onseeked = () => {
        window.clearTimeout(timeout)

        try {
          const { videoWidth: w, videoHeight: h } = video

          if (!w || !h) return done(null)

          const scale = Math.min(1, MAX_EDGE / Math.max(w, h))
          const canvas = document.createElement('canvas')
          canvas.width = Math.round(w * scale)
          canvas.height = Math.round(h * scale)

          const context = canvas.getContext('2d')
          if (!context) return done(null)

          context.drawImage(video, 0, 0, canvas.width, canvas.height)

          done(canvas.toDataURL('image/jpeg', 0.82))
        } catch {
          // Tainted canvas, or a codec the browser will decode but not paint.
          done(null)
        }
      }
    })
  } finally {
    URL.revokeObjectURL(url)
  }
}
