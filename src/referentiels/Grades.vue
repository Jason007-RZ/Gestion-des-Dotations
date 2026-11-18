<script setup>
// Catégories de personnel et grades (cahier des charges : chaque grade est rattaché à une catégorie,
// et c'est la catégorie qui détermine le tableau de dotation).
import { ref } from 'vue'
import api from '@/services/api'
import { confirmer } from '@/services/confirm'
import { referentiels, executer } from '@/services/referentiels'

const nouvelleCategorie = ref('')
const nouveauxGrades = ref({}) // { [categorieId]: libellé }
const renommage = ref(null) // { id, libelle } d'une catégorie
const editionGrade = ref(null) // { id, libelle, categorie_personnel_id, ordre }

async function creerCategorie() {
  try {
    await executer(() => api.post('/categories-personnel', { libelle: nouvelleCategorie.value.trim() }), 'Catégorie ajoutée.')
    nouvelleCategorie.value = ''
  } catch {
    /* message affiché */
  }
}

async function renommerCategorie() {
  try {
    await executer(() => api.put(`/categories-personnel/${renommage.value.id}`, { libelle: renommage.value.libelle.trim() }), 'Catégorie renommée.')
    renommage.value = null
  } catch {
    /* saisie conservée */
  }
}

async function supprimerCategorie(c) {
  if (!(await confirmer({ titre: 'Supprimer la catégorie', message: `« ${c.libelle} » sera supprimée.`, bouton: 'Supprimer' }))) return
  try {
    await executer(() => api.delete(`/categories-personnel/${c.id}`), 'Catégorie supprimée.')
  } catch {
    /* message affiché (grades ou tableau de dotation encore rattachés) */
  }
}

async function creerGrade(c) {
  const libelle = (nouveauxGrades.value[c.id] || '').trim()
  if (!libelle) return
  try {
    await executer(() => api.post('/grades', { libelle, categorie_personnel_id: c.id }), 'Grade ajouté.')
    nouveauxGrades.value[c.id] = ''
  } catch {
    /* message affiché */
  }
}

const editerGrade = (g) => (editionGrade.value = { id: g.id, libelle: g.libelle, categorie_personnel_id: g.categorie_personnel_id, ordre: g.ordre })
async function enregistrerGrade() {
  const g = editionGrade.value
  try {
    await executer(() => api.put(`/grades/${g.id}`, { libelle: g.libelle.trim(), categorie_personnel_id: g.categorie_personnel_id, ordre: g.ordre }), 'Grade modifié.')
    editionGrade.value = null
  } catch {
    /* saisie conservée */
  }
}

async function supprimerGrade(g) {
  if (!(await confirmer({ titre: 'Supprimer le grade', message: `Le grade « ${g.libelle} » sera supprimé.`, bouton: 'Supprimer' }))) return
  try {
    await executer(() => api.delete(`/grades/${g.id}`), 'Grade supprimé.')
  } catch {
    /* message affiché (militaires portant ce grade) */
  }
}

// Monter / descendre un grade dans sa catégorie : on échange les deux numéros d'ordre.
async function deplacer(grades, i, sens) {
  const a = grades[i]
  const b = grades[i + sens]
  if (!a || !b) return
  const [ordreA, ordreB] = [a.ordre, b.ordre] // lus avant toute requête
  try {
    await executer(async () => {
      await api.put(`/grades/${a.id}`, { libelle: a.libelle, categorie_personnel_id: a.categorie_personnel_id, ordre: ordreB })
      await api.put(`/grades/${b.id}`, { libelle: b.libelle, categorie_personnel_id: b.categorie_personnel_id, ordre: ordreA })
    })
  } catch {
    /* message affiché */
  }
}
</script>

<template>
  <div class="space-y-4">
    <p class="text-sm text-gray-600">Le grade d'un militaire détermine sa catégorie, et donc ce qu'il a droit de percevoir dans le tableau de dotation.</p>

    <section v-for="c in referentiels.categories_personnel" :key="c.id" class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <header class="px-4 sm:px-5 py-3.5 border-b border-gray-100 flex flex-wrap items-center gap-3">
        <form v-if="renommage?.id === c.id" @submit.prevent="renommerCategorie" class="flex-1 flex flex-wrap gap-2">
          <input v-model="renommage.libelle" required maxlength="255" aria-label="Libellé de la catégorie" class="flex-1 min-w-[10rem] border border-gray-200 rounded-lg px-3 py-1.5 text-sm" />
          <button type="button" @click="renommage = null" class="px-3 py-1.5 rounded-lg bg-gray-100 text-gray-700 text-xs font-semibold">Annuler</button>
          <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-xs font-semibold">Enregistrer</button>
        </form>
        <template v-else>
          <h2 class="flex-1 text-base font-semibold text-gray-900">{{ c.libelle }} <span class="text-xs font-normal text-gray-400">{{ (c.grades || []).length }} grade(s) · {{ c.nb_lignes_tableau ?? 0 }} article(s) au tableau</span></h2>
          <div class="flex gap-3 text-xs font-medium">
            <button type="button" @click="renommage = { id: c.id, libelle: c.libelle }" class="text-amber-700 hover:text-amber-900">Renommer</button>
            <button type="button" @click="supprimerCategorie(c)" class="text-red-600 hover:text-red-800">Supprimer</button>
          </div>
        </template>
      </header>

      <ul class="divide-y divide-gray-100">
        <li v-if="!(c.grades || []).length" class="px-5 py-3 text-sm text-gray-400">Aucun grade.</li>
        <li v-for="(g, i) in c.grades || []" :key="g.id" class="px-4 sm:px-5 py-2.5">
          <form v-if="editionGrade?.id === g.id" @submit.prevent="enregistrerGrade" class="flex flex-wrap items-end gap-2">
            <label class="flex-1 min-w-[10rem]">
              <span class="block text-[11px] font-semibold text-gray-500 mb-1">Libellé</span>
              <input v-model="editionGrade.libelle" required maxlength="255" class="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-sm" />
            </label>
            <label class="flex-1 min-w-[10rem]">
              <span class="block text-[11px] font-semibold text-gray-500 mb-1">Catégorie</span>
              <select v-model="editionGrade.categorie_personnel_id" class="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-sm bg-white">
                <option v-for="x in referentiels.categories_personnel" :key="x.id" :value="x.id">{{ x.libelle }}</option>
              </select>
            </label>
            <button type="button" @click="editionGrade = null" class="px-3 py-1.5 rounded-lg bg-gray-100 text-gray-700 text-xs font-semibold">Annuler</button>
            <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-xs font-semibold">Enregistrer</button>
          </form>
          <div v-else class="flex items-center gap-3">
            <div class="flex flex-col -my-1">
              <button type="button" @click="deplacer(c.grades, i, -1)" :disabled="i === 0" :aria-label="'Monter ' + g.libelle" class="text-gray-400 hover:text-gray-700 disabled:opacity-20 leading-none px-1">▲</button>
              <button type="button" @click="deplacer(c.grades, i, 1)" :disabled="i === c.grades.length - 1" :aria-label="'Descendre ' + g.libelle" class="text-gray-400 hover:text-gray-700 disabled:opacity-20 leading-none px-1">▼</button>
            </div>
            <span class="flex-1 text-sm text-gray-800">{{ g.libelle }}<span v-if="g.nb_militaires" class="text-xs text-gray-400"> · {{ g.nb_militaires }} militaire(s)</span></span>
            <div class="flex gap-3 text-xs font-medium">
              <button type="button" @click="editerGrade(g)" class="text-amber-700 hover:text-amber-900">Modifier</button>
              <button type="button" @click="supprimerGrade(g)" class="text-red-600 hover:text-red-800">Supprimer</button>
            </div>
          </div>
        </li>
      </ul>

      <form @submit.prevent="creerGrade(c)" class="px-4 sm:px-5 py-3 bg-gray-50/70 border-t border-gray-100 flex flex-wrap gap-2">
        <input v-model="nouveauxGrades[c.id]" required maxlength="255" :aria-label="'Nouveau grade dans ' + c.libelle" placeholder="Nouveau grade" class="flex-1 min-w-[10rem] border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white" />
        <button type="submit" class="px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold">+ Ajouter</button>
      </form>
    </section>

    <form @submit.prevent="creerCategorie" class="bg-white rounded-2xl shadow-sm border border-dashed border-gray-300 p-4 flex flex-wrap gap-2">
      <input v-model="nouvelleCategorie" required maxlength="255" aria-label="Nouvelle catégorie de personnel" placeholder="Nouvelle catégorie de personnel" class="flex-1 min-w-[10rem] border border-gray-200 rounded-lg px-3 py-2 text-sm" />
      <button type="submit" class="px-4 py-2 rounded-lg border border-brand-600 text-brand-700 hover:bg-brand-50 text-xs font-semibold">+ Ajouter la catégorie</button>
    </form>
  </div>
</template>
