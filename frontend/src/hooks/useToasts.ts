import { useContext } from 'react'
import { ToastContext, type ToastApi } from '../components/toast-context'

export function useToasts(): ToastApi {
  const context = useContext(ToastContext)

  if (!context) throw new Error('useToasts must be used inside a ToastProvider.')

  return context
}
