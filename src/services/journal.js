// Journal d'erreurs de l'interface : n'écrit jamais l'objet d'erreur complet dans la console.
// Une erreur axios contient la requête (adresse, en-têtes, donc le jeton d'accès, et le corps : mot de passe).
export function journalErreur(contexte, error) {
  const statut = error?.response?.status
  console.error(contexte, statut ? `HTTP ${statut}` : error?.message || 'erreur')
}
