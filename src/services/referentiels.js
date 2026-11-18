import { reactive } from 'vue'
import api, { messageErreur } from '@/services/api'
import { notify } from '@/services/notify'

// Référentiels partagés par tous les écrans (listes déroulantes, libellés) : chargés une fois par
// GET /referentiels, puis rechargés après chaque modification faite par l'administrateur.
export const referentiels = reactive({
  charge: false,
  erreur: null,
  categories_article: [],
  grilles_taille: [],
  categories_personnel: [],
  unites: [],
  magasins: [],
  grilles_mensuration: {} // { taille_vetement, pointure, tour_tete } : id de la grille de chaque mesure
})

let enCours = null

export function chargerReferentiels(forcer = false) {
  if (referentiels.charge && !forcer) return Promise.resolve(referentiels)
  if (enCours && !forcer) return enCours
  enCours = api
    .get('/referentiels')
    .then(({ data }) => {
      Object.assign(referentiels, {
        categories_article: data.categories_article || [],
        grilles_taille: data.grilles_taille || [],
        categories_personnel: data.categories_personnel || [],
        unites: data.unites || [],
        magasins: data.magasins || [],
        grilles_mensuration: data.grilles_mensuration || {},
        charge: true,
        erreur: null
      })
      return referentiels
    })
    .catch((e) => {
      referentiels.erreur = e
      throw e
    })
    .finally(() => {
      enCours = null
    })
  return enCours
}

export const tousLesGrades = () =>
  referentiels.categories_personnel.flatMap((c) => (c.grades || []).map((g) => ({ ...g, categorie: c })))

// « S → XXL (5 tailles) », ou « Taille unique »
export function resumeGrille(grille) {
  const t = grille?.tailles || []
  if (!grille) return 'Taille unique'
  if (t.length === 0) return `${grille.libelle} (aucune taille)`
  return `${grille.libelle} : ${t[0].valeur} → ${t[t.length - 1].valeur}`
}

export const libelleRenouvellement = (mois) => (mois ? `tous les ${mois} mois` : 'réintégrable')

// Exécute une modification de l'administrateur : message de succès, rechargement des référentiels,
// ou message d'erreur du serveur (puis l'erreur est relancée pour que le formulaire garde la saisie).
export async function executer(action, succes) {
  let resultat
  try {
    resultat = await action()
  } catch (e) {
    notify.erreur(messageErreur(e))
    throw e
  }
  // Le message de succès n'apparaît qu'une fois les listes à jour.
  try {
    await chargerReferentiels(true)
    if (succes) notify.succes(succes)
  } catch {
    notify.info(`${succes || 'Enregistré'} (les listes n'ont pas pu être actualisées : rechargez la page).`)
  }
  return resultat
}
