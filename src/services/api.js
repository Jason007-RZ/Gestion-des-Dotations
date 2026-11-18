import axios from 'axios'

// URL du backend Laravel. Pour la changer sans toucher au code, créer un fichier
// .env.local à la racine du frontend avec :  VITE_API_URL=http://192.168.1.140:8000/api
// Par défaut : le PC qui héberge le backend (php artisan serve --host=0.0.0.0 --port=8000).
export const API_URL = import.meta.env.VITE_API_URL || 'http://192.168.1.119:8000/api'

const api = axios.create({
  baseURL: API_URL,
  withCredentials: false,
  timeout: 60000, // une requête qui ne répond pas ne bloque pas l'écran indéfiniment
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json'
  }
})

// Injecte le jeton Sanctum à chaque requête
api.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('auth_token')
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }
    return config
  },
  (error) => Promise.reject(error)
)

// Jeton expiré ou invalide (401) : on vide la session et on prévient l'application.
// (Le login et l'enrôlement renvoient aussi des 401/422 : on ne les traite pas ici.)
// Serveur injoignable : on prévient l'application (bandeau), et on la prévient du retour à la normale.
let serveurInjoignable = false
const signaler = (injoignable) => {
  if (serveurInjoignable === injoignable) return
  serveurInjoignable = injoignable
  window.dispatchEvent(new CustomEvent(injoignable ? 'serveur-injoignable' : 'serveur-ok'))
}

api.interceptors.response.use(
  (response) => {
    signaler(false)
    return response
  },
  (error) => {
    // Pas de réponse HTTP = réseau coupé, backend arrêté ou mauvaise adresse d'API
    if (!error.response && error.code !== 'ERR_CANCELED') signaler(true)
    else if (error.response) signaler(false)

    const url = error.config?.url || ''
    const estAuth = ['/login', '/register', '/forgot-password', '/reset-password'].some((p) => url.includes(p))
    if (error.response?.status === 401 && !estAuth) {
      localStorage.removeItem('auth_token')
      localStorage.removeItem('user_info')
      window.dispatchEvent(new CustomEvent('session-expiree'))
    }
    return Promise.reject(error)
  }
)

/**
 * Extrait un message lisible d'une erreur axios Laravel :
 * - 422 de validation : premier message de `errors`
 * - 403 : message du backend (RG8, RG10...) ou texte générique
 * - sinon `message` ou texte de secours
 */
export function messageErreur(error, secours = 'Une erreur est survenue.') {
  const r = error?.response
  if (!r) return "Impossible de joindre le serveur. Vérifiez que le backend est démarré et l'adresse de l'API."
  const premiere = r.data?.errors ? Object.values(r.data.errors)[0]?.[0] : null
  if (premiere) return premiere
  if (r.status === 429) {
    const secondes = Number(r.headers?.['retry-after'])
    if (secondes > 0) return `Trop de tentatives. Réessayez dans ${Math.max(1, Math.ceil(secondes / 60))} minute(s).`
    return 'Trop de tentatives. Patientez une minute avant de réessayer.'
  }
  if (r.status >= 500) return 'Erreur interne du serveur. Réessayez ; si elle persiste, prévenez l\'administrateur.'
  if (r.status === 403) return r.data?.message || "Accès refusé : votre rôle ne permet pas cette action."
  return r.data?.message || secours
}

// Les listes Laravel peuvent être un tableau simple ou une pagination { data: [...] }
export function enListe(data) {
  if (Array.isArray(data)) return data
  if (data && Array.isArray(data.data)) return data.data
  return []
}

/**
 * Récupère toutes les pages d'une route paginée Laravel (/militaires renvoie 20 lignes par page).
 * Retourne un tableau simple, quelle que soit la forme de la réponse.
 */
export async function toutesLesPages(url, params = {}) {
  const premiere = await api.get(url, { params: { ...params, page: 1 } })
  const donnees = premiere.data
  if (Array.isArray(donnees) || !Array.isArray(donnees?.data)) return enListe(donnees)

  let resultat = [...donnees.data]
  const derniere = Math.min(donnees.last_page || 1, 100)
  for (let page = 2; page <= derniere; page++) {
    const suite = await api.get(url, { params: { ...params, page } })
    resultat = resultat.concat(enListe(suite.data))
  }
  return resultat
}

export default api
