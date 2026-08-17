import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'

/*
 | Fonts are bundled rather than fetched from a font service: one less origin
 | to depend on, no request that can hang, and the archive keeps working with
 | no network beyond its own API.
 */
import '@fontsource/cormorant-garamond/400.css'
import '@fontsource/cormorant-garamond/400-italic.css'
import '@fontsource/cormorant-garamond/500.css'
import '@fontsource-variable/inter'

import './styles/base.css'
import './styles/timeline.css'
import './styles/chrome.css'
import './styles/compose.css'
import './styles/viewer.css'

import { App } from './App'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
