<script setup>
// Grilles de taille et leurs valeurs (cahier des charges : vêtement S, M, L, XL… ; pointure 38 à 46 ; tour de tête 54 à 60).
// L'ordre des tailles est celui affiché partout (S avant M avant L), pas l'ordre alphabétique.
import { ref } from 'vue'
import api from '@/services/api'
import { confirmer } from '@/services/confirm'
import { referentiels, executer } from '@/services/referentiels'

const nouvelleGrille = ref('')
const nouvellesTailles = ref({})
const renommage = ref(null)

async function creerGrille() {
  try {
    await executer(() => api.post('/grilles-taille', { libelle: nouvelleGrille.value.trim() }), 'Grille ajoutée.')
    nouvelleGrille.value = ''
  } catch {
    /* message affiché */
  }
}

async function renommer() {
  try {
    await executer(() => api.put(`/grilles-taille/${renommage.value.id}`, { libelle: renommage.value.libelle.trim() }), 'Grille renommée.')
    renommage.value = null
  } catch {
    /* saisie conservée */
  }
}

async function supprimerGrille(g) {
  if (!(await confirmer({ titre: 'Supprimer la grille', message: `La grille « ${g.libelle} » et ses ${g.tailles.length} taille(s) seront supprimées.`, bouton: 'Supprimer' }))) return
  try {
    await executer(() => api.delete(`/grilles-taille/${g.id}`), 'Grille supprimée.')
  } catch {
    /* message affiché (articles qui l'utilisent) */
  }
}

// Plusieurs valeurs d'un coup : « 47, 48 » ajoute deux tailles, à la suite des existantes.
async function ajouterTailles(g) {
  const valeurs = (nouvellesTailles.value[g.id] || '').split(',').map((v) => v.trim().toUpperCase()).filter(Boolean)
  if (!valeurs.length) return
  try {
    await executer(async () => {
      for (const valeur of valeurs) await api.post(`/grilles-taille/${g.id}/tailles`, { valeur })
    }, valeurs.length > 1 ? `${valeurs.length} tailles ajoutées.` : 'Taille ajoutée.')
    nouvellesTailles.value[g.id] = ''
  } catch {
    /* message affiché ; les tailles précédant l'erreur sont enregistrées */
  }
}

async function supprimerTaille(g, t) {
  if (!(await confirmer({ titre: 'Supprimer la taille', message: `La taille ${t.valeur} sera retirée de la grille « ${g.libelle} ».`, bouton: 'Supprimer' }))) return
  try {
    await executer(() => api.delete(`/tailles/${t.id}`), 'Taille supprimée.')
  } catch {
    /* message affiché (taille déjà utilisée) */
  }
}

async function deplacer(g, i, sens) {
  const a = g.tailles[i]
  const b = g.tailles[i + sens]
  if (!a || !b) return
  const [ordreA, ordreB] = [a.ordre, b.ordre] // lus avant toute requête
  try {
    await executer(async () => {
      await api.put(`/tailles/${a.id}`, { ordre: ordreB })
      await api.put(`/tailles/${b.id}`, { ordre: ordreA })
    })
  } catch {
    /* message affiché */
  }
}
</script>

<template>
  <div class="space-y-4">
    <section v-for="g in referentiels.grilles_taille" :key="g.id" class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <header class="px-4 sm:px-5 py-3.5 border-b border-gray-100 flex flex-wrap items-center gap-3">
        <form v-if="renommage?.id === g.id" @submit.prevent="renommer" class="flex-1 flex flex-wrap gap-2">
          <input v-model="renommage.libelle" required maxlength="255" aria-label="Libellé de la grille" class="flex-1 min-w-[10rem] border border-gray-200 rounded-lg px-3 py-1.5 text-sm" />
          <button type="button" @click="renommage = null" class="px-3 py-1.5 rounded-lg bg-gray-100 text-gray-700 text-xs font-semibold">Annuler</button>
          <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-xs font-semibold">Enregistrer</button>
        </form>
        <template v-else>
          <h2 class="flex-1 text-base font-semibold text-gray-900">{{ g.libelle }} <span class="text-xs font-normal text-gray-400">{{ g.tailles.length }} taille(s) · {{ g.nb_articles ?? 0 }} article(s)</span></h2>
          <div class="flex gap-3 text-xs font-medium">
            <button type="button" @click="renommage = { id: g.id, libelle: g.libelle }" class="text-amber-700 hover:text-amber-900">Renommer</button>
            <button type="button" @click="supprimerGrille(g)" class="text-red-600 hover:text-red-800">Supprimer</button>
          </div>
        </template>
      </header>

      <div class="px-4 sm:px-5 py-4">
        <p v-if="!g.tailles.length" class="text-sm text-gray-400">Aucune taille.</p>
        <ul class="flex flex-wrap gap-2" :aria-label="'Tailles de la grille ' + g.libelle">
          <li v-for="(t, i) in g.tailles" :key="t.id" class="flex items-center rounded-lg border border-gray-200 bg-gray-50 text-sm">
            <button type="button" @click="deplacer(g, i, -1)" :disabled="i === 0" :aria-label="'Placer ' + t.valeur + ' avant'" class="px-1.5 py-1 text-gray-400 hover:text-gray-800 disabled:opacity-20">‹</button>
            <span class="px-1.5 font-semibold text-gray-800 tabular-nums">{{ t.valeur }}</span>
            <button type="button" @click="deplacer(g, i, 1)" :disabled="i === g.tailles.length - 1" :aria-label="'Placer ' + t.valeur + ' après'" class="px-1.5 py-1 text-gray-400 hover:text-gray-800 disabled:opacity-20">›</button>
            <button type="button" @click="supprimerTaille(g, t)" :aria-label="'Supprimer la taille ' + t.valeur" class="px-2 py-1 text-gray-400 hover:text-red-600 border-l border-gray-200">×</button>
          </li>
        </ul>
      </div>

      <form @submit.prevent="ajouterTailles(g)" class="px-4 sm:px-5 py-3 bg-gray-50/70 border-t border-gray-100 flex flex-wrap gap-2">
        <input v-model="nouvellesTailles[g.id]" required maxlength="120" :aria-label="'Nouvelles tailles pour ' + g.libelle" placeholder="Nouvelle(s) taille(s), séparées par des virgules" class="flex-1 min-w-[10rem] border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white" />
        <button type="submit" class="px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold">+ Ajouter</button>
      </form>
    </section>

    <form @submit.prevent="creerGrille" class="bg-white rounded-2xl shadow-sm border border-dashed border-gray-300 p-4 flex flex-wrap gap-2">
      <input v-model="nouvelleGrille" required maxlength="255" aria-label="Nouvelle grille de taille" placeholder="Nouvelle grille (ex. : Tour de taille)" class="flex-1 min-w-[10rem] border border-gray-200 rounded-lg px-3 py-2 text-sm" />
      <button type="submit" class="px-4 py-2 rounded-lg border border-brand-600 text-brand-700 hover:bg-brand-50 text-xs font-semibold">+ Ajouter la grille</button>
    </form>
  </div>
</template>
