// Libellés communs aux bons de dotation.
export const TYPES_BON = {
  initiale: { libelle: 'Initiale', classe: 'bg-brand-50 text-brand-700 border-brand-200/60' },
  renouvellement: { libelle: 'Renouvellement', classe: 'bg-sky-50 text-sky-800 border-sky-200' },
  remplacement: { libelle: 'Remplacement', classe: 'bg-amber-50 text-amber-800 border-amber-200' }
}

export const ETATS_LIGNE = {
  en_service: { libelle: 'En service', classe: 'text-brand-700' },
  reintegre: { libelle: 'Réintégré', classe: 'text-violet-700' },
  perdu: { libelle: 'Perdu', classe: 'text-red-700' },
  reforme: { libelle: 'Réformé', classe: 'text-gray-500' }
}

export const ECHEANCES = {
  echue: { libelle: 'Échue', classe: 'bg-red-50 text-red-700 border-red-200' },
  bientot: { libelle: 'Dans moins de 30 jours', classe: 'bg-amber-50 text-amber-800 border-amber-200' },
  a_jour: { libelle: 'À jour', classe: 'bg-brand-50 text-brand-700 border-brand-200/60' },
  sans: { libelle: 'À réintégrer', classe: 'bg-violet-50 text-violet-800 border-violet-200' }
}
