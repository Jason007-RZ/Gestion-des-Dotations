import { reactive } from 'vue'

// Boîte de confirmation (remplace confirm()) : renvoie une promesse booléenne.
// Usage : if (!(await confirmer({ titre: 'Supprimer ?', message: '...', bouton: 'Supprimer' }))) return
// Affichée par <ConfirmDialog /> (monté une fois dans App.vue).
export const etatConfirmation = reactive({ ouverte: false, titre: '', message: '', bouton: 'Confirmer', danger: true })
let resoudre = null

export function confirmer({ titre = 'Confirmer', message = '', bouton = 'Confirmer', danger = true } = {}) {
  Object.assign(etatConfirmation, { ouverte: true, titre, message, bouton, danger })
  return new Promise((res) => {
    resoudre = res
  })
}

export function repondre(valeur) {
  etatConfirmation.ouverte = false
  if (resoudre) resoudre(valeur)
  resoudre = null
}
