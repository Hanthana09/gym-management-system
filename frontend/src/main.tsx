import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { AuthProvider } from './auth/AuthContext'
import { GymBrandingProvider } from './gym/useGymBranding'
import { GymMemberIdSettingsProvider } from './gym/useGymMemberIdSettings'
import { NotificationsProvider } from './notifications/useNotifications'
import './index.css'
import App from './App.tsx'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <BrowserRouter>
      <AuthProvider>
        <GymBrandingProvider>
          <GymMemberIdSettingsProvider>
            <NotificationsProvider>
              <App />
            </NotificationsProvider>
          </GymMemberIdSettingsProvider>
        </GymBrandingProvider>
      </AuthProvider>
    </BrowserRouter>
  </StrictMode>,
)
