import { ref } from 'vue'

// Empêche le double envoi d'un formulaire : tant que l'action tourne, `enCours` vaut true
// (à brancher sur :disabled du bouton). Retourne le résultat de l'action.
export function utiliserEnCours() {
  const enCours = ref(false)
  async function executer(action) {
    if (enCours.value) return
    enCours.value = true
    try {
      return await action()
    } finally {
      enCours.value = false
    }
  }
  return { enCours, executer }
}
