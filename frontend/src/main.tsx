import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { AuthProvider } from './auth/AuthContext'
import { GymBrandingProvider } from './gym/useGymBranding'
import { GymMemberIdSettingsProvider } from './gym/useGymMemberIdSettings'
import './index.css'
import App from './App.tsx'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <BrowserRouter>
      <AuthProvider>
        <GymBrandingProvider>
          <GymMemberIdSettingsProvider>
            <App />
          </GymMemberIdSettingsProvider>
        </GymBrandingProvider>
      </AuthProvider>
    </BrowserRouter>
  </StrictMode>,
)
