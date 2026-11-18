// Libellés et formats communs aux écrans du personnel.
export const STATUTS = {
  actif: { libelle: 'Actif', classe: 'bg-brand-50 text-brand-700 border-brand-200/60' },
  mute: { libelle: 'Muté', classe: 'bg-sky-50 text-sky-800 border-sky-200' },
  radie: { libelle: 'Radié', classe: 'bg-gray-100 text-gray-600 border-gray-200' }
}

// « 2026-09-01 » → « 01/09/2026 » (sans décalage de fuseau : la date est lue telle quelle)
export function dateCourte(iso) {
  if (!iso) return '—'
  const [a, m, j] = String(iso).slice(0, 10).split('-')
  return j && m && a ? `${j}/${m}/${a}` : String(iso)
}

// Ancienneté lisible : « 3 mois », « 2 ans et 4 mois »
export function anciennete(iso) {
  if (!iso) return ''
  const debut = new Date(String(iso).slice(0, 10) + 'T00:00:00')
  const maintenant = new Date()
  let mois = (maintenant.getFullYear() - debut.getFullYear()) * 12 + (maintenant.getMonth() - debut.getMonth())
  if (maintenant.getDate() < debut.getDate()) mois--
  if (mois < 1) return "moins d'un mois"
  const ans = Math.floor(mois / 12)
  const reste = mois % 12
  if (!ans) return `${reste} mois`
  return `${ans} an${ans > 1 ? 's' : ''}${reste ? ` et ${reste} mois` : ''}`
}
