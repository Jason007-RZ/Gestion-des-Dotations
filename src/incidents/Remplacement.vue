<script setup>
// Bon de remplacement à partir d'un incident : même article, même quantité, taille au choix (stock affiché).
import { ref, computed, watch, onMounted } from 'vue'
import api, { messageErreur, enListe } from '@/services/api'
import { notify } from '@/services/notify'
import { ROLES } from '@/services/roles'
import { referentiels } from '@/services/referentiels'

const props = defineProps({ incident: { type: Object, required: true }, user: { type: Object, default: null } })
const emit = defineEmits(['fermer', 'cree'])

const magasins = computed(() => (props.user?.role === ROLES.ADMIN ? referentiels.magasins : props.user?.magasins || []))
const magasinId = ref(magasins.value[0]?.id ?? '')
const article = computed(() => props.incident.ligne?.article)
const quantite = computed(() => props.incident.ligne?.quantite)
const tailleId = ref(props.incident.ligne?.taille?.id ?? '')
const stock = ref([])
const aujourdhui = () => new Date().toISOString().slice(0, 10)
const date = ref(aujourdhui())

async function chargerStock() {
  if (!magasinId.value) return
  try {
    stock.value = enListe((await api.get(`/magasins/${magasinId.value}/stock`)).data).filter((l) => l.article.id === article.value?.id)
  } catch (e) {
    notify.erreur(messageErreur(e, 'Stock indisponible.'))
  }
}
watch(magasinId, chargerStock)
onMounted(chargerStock)
const disponible = computed(() => stock.value.find((l) => (l.taille?.id ?? '') === (article.value?.grille_taille ? tailleId.value : ''))?.quantite ?? 0)

const occupe = ref(false)
async function valider() {
  occupe.value = true
  try {
    const { data } = await api.post(`/incidents/${props.incident.id}/remplacement`, {
      magasin_id: magasinId.value,
      taille_id: article.value?.grille_taille ? tailleId.value : null,
      date: date.value
    })
    notify.succes(`Bon de remplacement ${data.numero_bon} enregistré.`)
    emit('cree', data.id)
  } catch (e) {
    notify.erreur(messageErreur(e, 'Remplacement non enregistré.'))
  } finally {
    occupe.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 bg-black/50 flex items-end sm:items-center justify-center z-50 p-0 sm:p-4 backdrop-blur-sm">
    <section class="bg-white rounded-t-2xl sm:rounded-2xl max-w-md w-full p-5 sm:p-6 shadow-2xl" role="dialog" aria-modal="true" aria-label="Bon de remplacement">
      <h3 class="text-lg font-semibold text-gray-900">Bon de remplacement</h3>
      <p class="text-sm text-gray-500 mb-4">PV {{ incident.pv_numero }} — {{ incident.militaire?.nom }} {{ incident.militaire?.prenoms }}</p>
      <form @submit.prevent="valider" class="space-y-4">
        <p class="rounded-xl bg-gray-50 border border-gray-100 px-3 py-2 text-sm"><strong>{{ quantite }} × {{ article?.designation }}</strong></p>
        <label class="block">
          <span class="block text-xs font-semibold text-gray-600 mb-1">Magasin</span>
          <select v-model="magasinId" required class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white">
            <option v-for="m in magasins" :key="m.id" :value="m.id">{{ m.libelle }}</option>
          </select>
        </label>
        <div class="grid grid-cols-2 gap-3">
          <label v-if="article?.grille_taille">
            <span class="block text-xs font-semibold text-gray-600 mb-1">Taille</span>
            <select v-model="tailleId" required class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white">
              <option v-for="l in stock" :key="l.taille?.id" :value="l.taille?.id">{{ l.taille?.valeur }} ({{ l.quantite }})</option>
            </select>
          </label>
          <label :class="article?.grille_taille ? '' : 'col-span-2'">
            <span class="block text-xs font-semibold text-gray-600 mb-1">Date</span>
            <input v-model="date" type="date" required :max="aujourdhui()" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm" />
          </label>
        </div>
        <p class="text-xs" :class="disponible < quantite ? 'text-red-700' : 'text-gray-500'">{{ disponible }} disponible(s) dans ce magasin{{ disponible < quantite ? ' : stock insuffisant.' : '.' }}</p>
        <div class="flex justify-end gap-2">
          <button type="button" @click="emit('fermer')" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2.5 rounded-xl text-xs font-semibold">Annuler</button>
          <button type="submit" :disabled="occupe || disponible < quantite" class="bg-brand-600 hover:bg-brand-700 text-white px-5 py-2.5 rounded-xl text-xs font-semibold disabled:opacity-40">Valider le remplacement</button>
        </div>
      </form>
    </section>
  </div>
</template>
