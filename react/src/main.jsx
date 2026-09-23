import React from 'react'
import ReactDOM from 'react-dom/client'
import './styles/global.css'
import App from './App'
import './index.css'
import { expireBookingCart } from './utils/bookingCart'

expireBookingCart()

sessionStorage.removeItem('paymentAccessToken')
localStorage.removeItem('paymentAccessToken')
document.documentElement.dataset.build = 'homepage-about-footer-20260905'


ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>,
)
