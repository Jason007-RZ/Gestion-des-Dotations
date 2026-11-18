import { reactive } from 'vue'

// Notifications éphémères (« toasts ») partagées par toute l'application.
// Usage :  import { notify } from '@/services/notify'   puis   notify.succes('Enregistré')
export const toasts = reactive([])
let compteur = 0

function ajouter(type, message, duree) {
  const id = ++compteur
  toasts.push({ id, type, message })
  if (duree > 0) setTimeout(() => retirer(id), duree)
  return id
}

export function retirer(id) {
  const i = toasts.findIndex((t) => t.id === id)
  if (i !== -1) toasts.splice(i, 1)
}

export const notify = {
  succes: (message, duree = 4000) => ajouter('succes', message, duree),
  erreur: (message, duree = 7000) => ajouter('erreur', message, duree),
  info: (message, duree = 4000) => ajouter('info', message, duree)
}
