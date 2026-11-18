// Rôles du cahier des charges (§4) et ce que chacun peut faire.
// Doit rester aligné avec routes/api.php côté Laravel : le backend reste l'autorité,
// ici on ne fait que masquer ce qui renverrait un 403.

export const ROLES = {
  ADMIN: 'administrateur',
  MAGASINIER: 'magasinier',
  CHEF: 'chef_unite',
  MILITAIRE: 'militaire'
}

export const LIBELLES_ROLES = {
  administrateur: 'Administrateur',
  magasinier: 'Magasinier',
  chef_unite: "Chef d'unité",
  militaire: 'Militaire'
}

// Onglets visibles par rôle
const ONGLETS = {
  dashboard: [ROLES.ADMIN, ROLES.MAGASINIER, ROLES.CHEF],
  catalogue: [ROLES.ADMIN, ROLES.MAGASINIER, ROLES.CHEF, ROLES.MILITAIRE],
  mouvements: [ROLES.ADMIN, ROLES.MAGASINIER, ROLES.CHEF, ROLES.MILITAIRE],
  // Perte (RG9) et dérogation au barème (RG1) : le militaire signale, le chef d'unité valide, le magasin enregistre
  demandes: [ROLES.ADMIN, ROLES.MAGASINIER, ROLES.CHEF, ROLES.MILITAIRE],
  stocks: [ROLES.ADMIN, ROLES.MAGASINIER],
  effectifs: [ROLES.ADMIN, ROLES.MAGASINIER, ROLES.CHEF],
  monespace: [ROLES.MILITAIRE],
  previsions: [ROLES.ADMIN, ROLES.CHEF],
  utilisateurs: [ROLES.ADMIN],
  audit: [ROLES.ADMIN],
  securite: [ROLES.ADMIN, ROLES.MAGASINIER, ROLES.CHEF, ROLES.MILITAIRE]
}

export function peutVoir(onglet, role) {
  return (ONGLETS[onglet] || []).includes(role)
}

export function ongletParDefaut(role) {
  return role === ROLES.MILITAIRE ? 'monespace' : 'dashboard'
}

// Actions métier
export const estAdmin = (role) => role === ROLES.ADMIN
export const peutOperer = (role) => role === ROLES.ADMIN || role === ROLES.MAGASINIER // mouvements, stock, mensurations
export const peutGererCatalogue = (role) => role === ROLES.ADMIN
