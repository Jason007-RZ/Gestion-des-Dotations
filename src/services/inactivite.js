import { ref, onMounted, onBeforeUnmount } from 'vue'

/**
 * Déconnexion automatique après une période sans activité (poste partagé, données sensibles).
 * - `delaiMs` : inactivité avant l'avertissement
 * - `avertissementMs` : durée de l'avertissement avant la déconnexion
 * `secondesRestantes` vaut null hors avertissement, sinon le compte à rebours.
 */
export function utiliserInactivite({ delaiMs = 15 * 60 * 1000, avertissementMs = 60 * 1000, onExpire }) {
  const secondesRestantes = ref(null)
  let minuteurAvertissement = null
  let minuteurExpiration = null
  let intervalle = null
  let dernierSignal = 0

  const arreter = () => {
    clearTimeout(minuteurAvertissement)
    clearTimeout(minuteurExpiration)
    clearInterval(intervalle)
    secondesRestantes.value = null
  }

  const armer = () => {
    arreter()
    minuteurAvertissement = setTimeout(() => {
      secondesRestantes.value = Math.round(avertissementMs / 1000)
      intervalle = setInterval(() => {
        if (secondesRestantes.value > 0) secondesRestantes.value--
      }, 1000)
      minuteurExpiration = setTimeout(() => {
        arreter()
        onExpire?.()
      }, avertissementMs)
    }, delaiMs)
  }

  // Une activité repousse l'échéance (au plus une fois par seconde), sauf pendant l'avertissement :
  // là, il faut cliquer explicitement sur « Rester connecté ».
  const surActivite = () => {
    const maintenant = Date.now()
    if (secondesRestantes.value !== null || maintenant - dernierSignal < 1000) return
    dernierSignal = maintenant
    armer()
  }

  const evenements = ['mousedown', 'keydown', 'touchstart', 'scroll']
  const demarrer = () => {
    evenements.forEach((e) => window.addEventListener(e, surActivite, { passive: true }))
    armer()
  }
  const nettoyer = () => {
    evenements.forEach((e) => window.removeEventListener(e, surActivite))
    arreter()
  }

  onMounted(demarrer)
  onBeforeUnmount(nettoyer)

  return { secondesRestantes, prolonger: armer }
}
