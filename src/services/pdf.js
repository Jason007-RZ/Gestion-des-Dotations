import api, { messageErreur } from '@/services/api'
import { notify } from '@/services/notify'

// Ouvre un PDF généré par le backend dans un nouvel onglet.
// L'onglet est ouvert tout de suite (clic de l'utilisateur) pour ne pas être bloqué par le navigateur,
// puis alimenté quand le PDF est prêt. Si l'onglet est refusé, le fichier est téléchargé.
export async function ouvrirPdf(chemin, nomFichier = 'document.pdf') {
  const fenetre = window.open('', '_blank')
  if (fenetre) fenetre.document.title = 'Génération du document...'
  try {
    const reponse = await api.get(chemin, { responseType: 'blob' })
    const url = URL.createObjectURL(new Blob([reponse.data], { type: 'application/pdf' }))
    if (fenetre) {
      fenetre.location.href = url
    } else {
      const lien = document.createElement('a')
      lien.href = url
      lien.download = nomFichier
      document.body.appendChild(lien)
      lien.click()
      lien.remove()
    }
    setTimeout(() => URL.revokeObjectURL(url), 60000)
  } catch (error) {
    if (fenetre) fenetre.close()
    let message = messageErreur(error, 'Impossible de générer le document.')
    // Avec responseType « blob », le message JSON du serveur arrive sous forme de Blob
    const donnees = error?.response?.data
    if (donnees instanceof Blob) {
      try {
        const json = JSON.parse(await donnees.text())
        if (json?.message) message = json.message
      } catch {
        /* réponse non JSON : on garde le message par défaut */
      }
    }
    notify.erreur(message)
  }
}
