<script setup>
import { journalErreur } from '@/services/journal'
import { notify } from '@/services/notify'
import { usePagination } from '@/services/pagination'
import Pagination from './components/Pagination.vue'
import Paquetage from './components/Paquetage.vue'
import { ref, computed, onMounted, watch } from 'vue'
import api, { messageErreur, enListe, toutesLesPages } from '@/services/api'
import { peutOperer, estAdmin } from '@/services/roles'
import { ouvrirPdf } from '@/services/pdf'

// `user` = utilisateur connecté (rôle, magasins). Les droits réels sont vérifiés par Laravel.
const props = defineProps({ user: { type: Object, default: null } })
// 'aller' : ouvre un autre écran (ex. 'demandes') ; 'demandes-maj' : une demande vient d'être déposée d'ici
const emit = defineEmits(['aller', 'demandes-maj'])

const role = computed(() => props.user?.role)
const operateur = computed(() => peutOperer(role.value)) // magasinier + administrateur

const mouvements = ref([])
const militaires = ref([])
const articles = ref([])
const magasins = ref([])
const stocks = ref([])

const loading = ref(false)
const errorMessage = ref('')

const TYPES = {
  perception: { libelle: 'Perception', classe: 'bg-brand-50 text-brand-700 border-brand-100', signe: '−' },
  reintegration: { libelle: 'Réintégration', classe: 'bg-sky-50 text-sky-700 border-sky-100', signe: '+' },
  perte: { libelle: 'Perte', classe: 'bg-red-50 text-red-700 border-red-100', signe: '' },
  entree: { libelle: 'Entrée de stock', classe: 'bg-slate-100 text-slate-700 border-slate-200', signe: '+' },
  echange: { libelle: 'Échange', classe: 'bg-amber-50 text-amber-700 border-amber-100', signe: '' },
  sortie: { libelle: 'Sortie (inventaire)', classe: 'bg-violet-50 text-violet-700 border-violet-100', signe: '−' },
  annulation: { libelle: 'Annulation', classe: 'bg-gray-100 text-gray-600 border-gray-200', signe: '' }
}
const MESSAGES_OK = {
  perception: 'Perception enregistrée.',
  reintegration: 'Réintégration enregistrée.',
  echange: 'Échange pour usure enregistré.',
  perte: 'Perte enregistrée.'
}
const libelleType = (t) => TYPES[t]?.libelle || t
const classeType = (t) => TYPES[t]?.classe || 'bg-gray-100 text-gray-600 border-gray-200'

// ----- Formulaire -----
const typeOperation = ref('perception')
const rechercheMilitaire = ref('')
const form = ref({
  militaire_id: '',
  article_id: '',
  declinaison_id: '',
  magasin_id: '',
  quantite: 1,
  motif: '',
  autoriser_derogation: false
})

const militairesFiltres = computed(() => {
  const q = rechercheMilitaire.value.trim().toLowerCase()
  const liste = q
    ? militaires.value.filter((m) => `${m.matricule} ${m.nom} ${m.prenom}`.toLowerCase().includes(q))
    : militaires.value
  return liste.slice(0, 200)
})

const articleChoisi = computed(() => articles.value.find((a) => a.id === Number(form.value.article_id)))
const declinaisons = computed(() => articleChoisi.value?.declinaisons || [])

// Stock disponible pour la déclinaison et le magasin choisis
const stockDisponible = computed(() => {
  if (!form.value.declinaison_id || !form.value.magasin_id) return null
  const ligne = stocks.value.find(
    (s) => s.declinaison_id === Number(form.value.declinaison_id) && s.magasin_id === Number(form.value.magasin_id)
  )
  return ligne ? ligne.quantite : 0
})

const memoriserMagasin = () => {
  try { localStorage.setItem('dernier_magasin', String(form.value.magasin_id)) } catch { /* ignoré */ }
}

const choisirArticle = () => {
  form.value.declinaison_id = declinaisons.value.length === 1 ? declinaisons.value[0].id : ''
}

// ----- Chargements -----
const fetchMouvements = async () => {
  try {
    loading.value = true
    const response = await api.get('/mouvements')
    mouvements.value = enListe(response.data)
  } catch (error) {
    journalErreur('Erreur lors du chargement des mouvements :', error)
    errorMessage.value = messageErreur(error, 'Impossible de charger les mouvements.')
  } finally {
    loading.value = false
  }
}

const fetchStocks = async () => {
  try {
    const response = await api.get('/stocks')
    stocks.value = enListe(response.data)
  } catch (error) {
    journalErreur('Erreur chargement stocks :', error)
  }
}

const rafraichir = () => Promise.all([fetchMouvements(), fetchStocks()])

// Données de référence nécessaires uniquement pour saisir un mouvement
const chargerReferentiels = async () => {
  try {
    const [m, a, s] = await Promise.all([
      toutesLesPages('/militaires'),
      api.get('/articles'),
      api.get('/stocks')
    ])
    militaires.value = m
    articles.value = enListe(a.data)
    stocks.value = enListe(s.data)

    // Magasins : l'administrateur voit tous les magasins, le magasinier ceux auxquels il est rattaché (RG8)
    if (estAdmin(role.value)) {
      magasins.value = enListe((await api.get('/magasins')).data)
    } else {
      const me = (await api.get('/me')).data
      magasins.value = me.magasins || []
    }
    if (magasins.value.length === 1) form.value.magasin_id = magasins.value[0].id
    else {
      try {
        const dernier = Number(localStorage.getItem('dernier_magasin'))
        if (magasins.value.some((mg) => mg.id === dernier)) form.value.magasin_id = dernier
      } catch { /* stockage indisponible */ }
    }
  } catch (error) {
    journalErreur('Erreur chargement des référentiels :', error)
    errorMessage.value = messageErreur(error, 'Impossible de charger les listes (militaires, articles, magasins).')
  }
}

// ----- Enregistrer un mouvement -----
const enregistrer = async () => {
  errorMessage.value = ''
  const entree = typeOperation.value === 'entree'
  const payload = {
    declinaison_id: Number(form.value.declinaison_id),
    magasin_id: Number(form.value.magasin_id),
    quantite: Number(form.value.quantite)
  }
  if (!entree) payload.militaire_id = Number(form.value.militaire_id)
  if (typeOperation.value === 'perte' || typeOperation.value === 'echange') payload.motif = form.value.motif
  if (typeOperation.value === 'perception' && form.value.autoriser_derogation) payload.autoriser_derogation = true

  try {
    await api.post(entree ? '/stocks/entree' : `/mouvements/${typeOperation.value}`, payload)
    notify.succes(entree ? `Entrée enregistrée : +${payload.quantite} en stock.` : MESSAGES_OK[typeOperation.value] || 'Opération enregistrée.')
    form.value.quantite = 1
    form.value.motif = ''
    form.value.autoriser_derogation = false
    await Promise.all([fetchMouvements(), fetchStocks()])
  } catch (error) {
    if (error.response?.data?.code === 'derogation_requise') {
      // Cas normal du métier (RG1), pas une anomalie : on propose la dérogation au lieu d'afficher une erreur.
      proposerDerogation(error.response.data.message)
    } else {
      journalErreur('Erreur enregistrement :', error)
      errorMessage.value = messageErreur(error, "Impossible d'enregistrer ce mouvement.")
    }
  }
}

// ----- Dépassement du barème (RG1) : on propose de demander une dérogation au chef d'unité -----
const derogation = ref(null) // { message, militaire_id, declinaison_id, quantite, resume, motif }
const envoiDerogation = ref(false)

const proposerDerogation = (message) => {
  const m = militaires.value.find((x) => x.id === Number(form.value.militaire_id))
  const taille = declinaisons.value.find((d) => d.id === Number(form.value.declinaison_id))?.taille
  const qui = m ? `${m.grade || ''} ${m.nom} ${m.prenom}`.trim() : 'militaire'
  derogation.value = {
    message,
    militaire_id: Number(form.value.militaire_id),
    declinaison_id: Number(form.value.declinaison_id),
    quantite: Number(form.value.quantite),
    resume: `${form.value.quantite} × ${articleChoisi.value?.designation || 'article'}${taille ? ' (' + taille + ')' : ''} — ${qui}`,
    motif: ''
  }
}

// Dès que la saisie change, l'avertissement ne correspond plus à ce qui est affiché : on le retire.
watch(
  () => [typeOperation.value, form.value.militaire_id, form.value.declinaison_id, form.value.quantite],
  () => {
    derogation.value = null
  }
)

const demanderDerogation = async () => {
  const d = derogation.value
  if (!d || envoiDerogation.value || d.motif.trim().length < 5) return
  envoiDerogation.value = true
  errorMessage.value = ''
  try {
    await api.post('/demandes', {
      type: 'derogation',
      militaire_id: d.militaire_id,
      declinaison_id: d.declinaison_id,
      quantite: d.quantite,
      motif: d.motif.trim()
    })
    notify.succes("Demande de dérogation envoyée au chef d'unité. Dès qu'elle est validée, enregistrez la perception depuis l'écran « Demandes ».")
    derogation.value = null
    emit('demandes-maj')
  } catch (error) {
    journalErreur('Erreur demande de dérogation :', error)
    errorMessage.value = messageErreur(error, "Impossible d'envoyer la demande de dérogation.")
  } finally {
    envoiDerogation.value = false
  }
}

// ----- Annulation (RG3 : jamais de suppression) -----
const annulationEn = ref(null)
const motifAnnulation = ref('')

const dejaAnnules = computed(
  () => new Set(mouvements.value.filter((m) => m.annule_le_mouvement_id).map((m) => m.annule_le_mouvement_id))
)
const annulable = (m) =>
  operateur.value && ['perception', 'reintegration'].includes(m.type) && !dejaAnnules.value.has(m.id)

const ouvrirAnnulation = (m) => {
  annulationEn.value = m
  motifAnnulation.value = ''
}

const confirmerAnnulation = async () => {
  errorMessage.value = ''
  try {
    await api.post(`/mouvements/${annulationEn.value.id}/annuler`, { motif: motifAnnulation.value })
    notify.succes(`Mouvement #${annulationEn.value.id} annulé (un mouvement inverse a été créé).`)
    annulationEn.value = null
    await Promise.all([fetchMouvements(), fetchStocks()])
  } catch (error) {
    errorMessage.value = messageErreur(error, "Impossible d'annuler ce mouvement.")
    annulationEn.value = null
  }
}

// ----- Documents PDF -----
const telechargerBon = (m) => ouvrirPdf(`/mouvements/${m.id}/bon-perception`, `bon-perception-${m.id}.pdf`)
const telechargerPv = (m) => ouvrirPdf(`/mouvements/${m.id}/pv-perte`, `pv-perte-${m.id}.pdf`)

// Le procès-verbal d'une perte est accessible à tous les rôles qui voient ce mouvement (le serveur contrôle l'unité / la fiche) ;
// le bon de perception et l'annulation restent réservés aux opérateurs.
const aDesActions = (m) => operateur.value || m.type === 'perte'
const afficherActions = computed(() => operateur.value || mouvements.value.some((m) => m.type === 'perte'))

// ----- Filtre d'affichage -----
const filtreType = ref('')
const mouvementsAffiches = computed(() =>
  filtreType.value ? mouvements.value.filter((m) => m.type === filtreType.value) : mouvements.value
)

const nomMilitaire = (m) => (m.militaire ? `${m.militaire.grade || ''} ${m.militaire.nom} ${m.militaire.prenom}`.trim() : '—')
const dateMvt = (m) => {
  const d = m.date_mvt || m.created_at
  return d ? new Date(d).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : '—'
}

const titre = computed(() => {
  if (role.value === 'militaire') return 'Mes mouvements'
  if (role.value === 'chef_unite') return "Mouvements de mon unité"
  return 'Mouvements & Perceptions'
})

const { page: pageMvt, pages: pagesMvt, elements: mouvementsPage, total: totalMvt } = usePagination(mouvementsAffiches, 15)

onMounted(async () => {
  await fetchMouvements()
  if (operateur.value) await chargerReferentiels()
})
</script>

<template>
  <div>
    <div class="flex justify-between items-center mb-6">
      <div class="flex items-center space-x-3">
        <div>
          <h1 class="text-xl sm:text-2xl font-semibold text-gray-900 tracking-tight">{{ titre }}</h1>
          <p class="text-sm text-gray-500">
            Journal des perceptions, réintégrations, pertes et entrées de stock. Un mouvement n'est jamais supprimé : il s'annule par un mouvement inverse.
          </p>
        </div>
      </div>
    </div>
    <div v-if="errorMessage" class="mb-5 p-4 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm">{{ errorMessage }}</div>

    <!-- Saisie : magasinier et administrateur uniquement -->
    <section v-if="operateur" class="bg-white rounded-2xl shadow-sm border border-gray-100 mb-6 overflow-hidden">
      <div class="px-4 sm:px-6 pt-5 pb-4 border-b border-gray-100">
        <h2 class="text-base font-bold text-gray-900 mb-3">Nouvelle opération</h2>
        <div class="grid grid-cols-3 sm:grid-cols-6 gap-1.5 p-1 bg-gray-100 rounded-xl sm:max-w-3xl" role="tablist" aria-label="Type d'opération">
          <button
            v-for="t in ['perception', 'paquetage', 'reintegration', 'echange', 'perte', 'entree']"
            :key="t"
            type="button"
            role="tab"
            :aria-selected="typeOperation === t"
            @click="typeOperation = t"
            :class="typeOperation === t ? 'bg-white text-brand-700 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
            class="px-2 py-2 rounded-lg text-sm font-semibold transition"
          >
            {{ t === 'entree' ? 'Entrée' : t === 'paquetage' ? 'Paquetage' : libelleType(t) }}
          </button>
        </div>
        <p v-if="typeOperation === 'paquetage'" class="mt-3 text-xs text-gray-500">
          Sert d'un coup tout le barème du grade d'un militaire (incorporation), dans la taille de ses mensurations. Un aperçu est affiché avant de valider.
        </p>
        <p v-else-if="typeOperation === 'echange'" class="mt-3 text-xs text-gray-500">
          Remplace un article usé par le même article neuf, dans la même taille. Le militaire doit le détenir ; l'article usé n'est pas remis en stock.
        </p>
        <p v-else-if="typeOperation === 'entree'" class="mt-3 text-xs text-gray-500">
          Ajoute du matériel reçu (achat, don) au stock d'un magasin. Pour un nouvel article ou une nouvelle taille, crée-le d'abord dans « Catalogue et barèmes ».
        </p>
        <p v-else-if="typeOperation === 'perte' && estAdmin(role)" class="mt-3 text-xs text-gray-500">
          Enregistrement direct, réservé à l'administrateur. Dans le circuit normal, le chef d'unité valide d'abord la perte (écran « Demandes ») puis le magasin l'enregistre.
        </p>
      </div>

      <Paquetage v-if="typeOperation === 'paquetage'" :militaires="militaires" :magasins="magasins" @fait="rafraichir" />

      <!-- Une perte doit être validée par le chef d'unité : le magasinier passe par une demande -->
      <div v-else-if="typeOperation === 'perte' && !estAdmin(role)" class="p-4 sm:p-6">
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
          <p class="font-semibold">Une perte doit d'abord être validée par le chef d'unité.</p>
          <p class="mt-1">Déposez une demande dans l'écran « Demandes » : une fois validée, vous l'enregistrez depuis la même page et le procès-verbal est édité.</p>
          <button type="button" @click="emit('aller', 'demandes')" class="mt-3 bg-amber-600 hover:bg-amber-700 text-white px-4 py-2 rounded-xl text-xs font-semibold">Ouvrir les demandes</button>
        </div>
      </div>

      <form v-else @submit.prevent="enregistrer" class="p-4 sm:p-6 grid grid-cols-1 lg:grid-cols-3 gap-x-6 gap-y-5">
        <!-- 1. Qui -->
        <fieldset v-if="typeOperation !== 'entree'" class="space-y-2 min-w-0">
          <legend class="text-xs font-semibold text-brand-700 mb-1"><span class="inline-flex w-5 h-5 mr-1.5 rounded-full bg-brand-600 text-white items-center justify-center text-[11px]">1</span>Militaire</legend>
          <input name="recherchemilitaire"
            v-model="rechercheMilitaire"
            type="search"
            placeholder="Rechercher : nom ou matricule"
            class="w-full px-3.5 py-2.5 bg-gray-50/70 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 focus:outline-none"
          />
          <select name="form_militaire_id" v-model="form.militaire_id" required class="w-full px-3.5 py-2.5 bg-white border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500/20 focus:outline-none">
            <option value="" disabled>{{ militairesFiltres.length }} résultat(s) : choisir</option>
            <option v-for="m in militairesFiltres" :key="m.id" :value="m.id">
              {{ m.matricule }} · {{ m.grade }} {{ m.nom }} {{ m.prenom }}
            </option>
          </select>
        </fieldset>

        <!-- 2. Quoi -->
        <fieldset class="space-y-2 min-w-0">
          <legend class="text-xs font-semibold text-brand-700 mb-1"><span class="inline-flex w-5 h-5 mr-1.5 rounded-full bg-brand-600 text-white items-center justify-center text-[11px]">{{ typeOperation === 'entree' ? 1 : 2 }}</span>Article</legend>
          <select name="form_article_id" v-model="form.article_id" @change="choisirArticle" required class="w-full px-3.5 py-2.5 bg-white border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500/20 focus:outline-none">
            <option value="" disabled>Choisir un article</option>
            <option v-for="a in articles" :key="a.id" :value="a.id">{{ a.designation }}</option>
          </select>
          <select name="form_declinaison_id" v-model="form.declinaison_id" required :disabled="!declinaisons.length" class="w-full px-3.5 py-2.5 bg-white border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500/20 focus:outline-none disabled:bg-gray-50 disabled:text-gray-400">
            <option value="" disabled>{{ declinaisons.length ? 'Choisir la taille' : 'Taille (après l’article)' }}</option>
            <option v-for="d in declinaisons" :key="d.id" :value="d.id">{{ d.taille }}</option>
          </select>
        </fieldset>

        <!-- 3. Où / combien -->
        <fieldset class="space-y-2 min-w-0">
          <legend class="text-xs font-semibold text-brand-700 mb-1"><span class="inline-flex w-5 h-5 mr-1.5 rounded-full bg-brand-600 text-white items-center justify-center text-[11px]">{{ typeOperation === 'entree' ? 2 : 3 }}</span>Magasin et quantité</legend>
          <select name="form_magasin_id" v-model="form.magasin_id" @change="memoriserMagasin" required class="w-full px-3.5 py-2.5 bg-white border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500/20 focus:outline-none">
            <option value="" disabled>Choisir un magasin</option>
            <option v-for="mg in magasins" :key="mg.id" :value="mg.id">{{ mg.nom }}</option>
          </select>
          <div class="flex items-stretch gap-2">
            <button type="button" aria-label="Diminuer la quantité" @click="form.quantite = Math.max(1, Number(form.quantite) - 1)" class="w-11 rounded-xl border border-gray-200 bg-white text-lg font-semibold text-gray-700 hover:bg-gray-50">−</button>
            <input name="form_quantite" type="number" inputmode="numeric" min="1" v-model="form.quantite" required aria-label="Quantité" class="flex-1 min-w-0 text-center px-3 py-2.5 bg-gray-50/70 border border-gray-200 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-brand-500/20 focus:outline-none" />
            <button type="button" aria-label="Augmenter la quantité" @click="form.quantite = Number(form.quantite) + 1" class="w-11 rounded-xl border border-gray-200 bg-white text-lg font-semibold text-gray-700 hover:bg-gray-50">+</button>
          </div>
          <p v-if="stockDisponible !== null" class="text-xs" :class="stockDisponible > 0 ? 'text-gray-500' : 'text-red-600 font-semibold'">
            <span v-if="stockDisponible === 0">⚠ </span>Stock dans ce magasin : {{ stockDisponible }}
          </p>
        </fieldset>

        <div v-if="typeOperation === 'perte' || typeOperation === 'echange'" class="lg:col-span-3">
          <label for="mouvements-champ-1" class="block text-xs font-semibold text-gray-600 mb-1.5">{{ typeOperation === 'echange' ? "Motif de l'usure (obligatoire, 5 caractères minimum)" : 'Motif de la perte (obligatoire, 5 caractères minimum)' }}</label>
          <input id="mouvements-champ-1" name="form_motif" v-model="form.motif" type="text" minlength="5" required :placeholder="typeOperation === 'echange' ? 'Ex : semelle décollée, déchirure au genou...' : 'Ex : perdu en exercice, vol déclaré...'" class="w-full px-3.5 py-2.5 bg-gray-50/70 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500/20 focus:outline-none" />
        </div>

        <label v-if="typeOperation === 'perception' && estAdmin(role)" class="lg:col-span-3 flex items-start gap-2 text-sm text-gray-600">
          <input name="form_autoriser_derogation" type="checkbox" v-model="form.autoriser_derogation" class="mt-0.5 rounded border-gray-300" />
          <span>Autoriser une dérogation au barème (dépassement de la quantité due)</span>
        </label>

        <!-- Dépassement du barème : demander une dérogation au chef d'unité -->
        <div v-if="derogation && typeOperation === 'perception'" class="lg:col-span-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" role="alert">
          <p class="font-semibold">Quantité au-dessus du barème</p>
          <p class="mt-0.5">{{ derogation.message }} Le chef d'unité du militaire doit autoriser une dérogation avant l'enregistrement.</p>
          <p class="mt-0.5 text-xs text-amber-800">Demande pour : {{ derogation.resume }}</p>
          <p v-if="estAdmin(role)" class="mt-1 text-xs text-amber-800">Administrateur : vous pouvez aussi cocher « Autoriser une dérogation » ci-dessus pour l'accorder vous-même.</p>
          <div class="mt-3 flex flex-col sm:flex-row gap-2">
            <input
              name="motif_derogation"
              v-model="derogation.motif"
              type="text"
              minlength="5"
              maxlength="500"
              aria-label="Raison de la dérogation"
              placeholder="Raison de la dérogation (5 caractères minimum)"
              @keydown.enter.prevent="demanderDerogation"
              class="flex-1 min-w-0 px-3.5 py-2.5 bg-white border border-amber-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-amber-500/30"
            />
            <button type="button" :disabled="envoiDerogation || derogation.motif.trim().length < 5" @click="demanderDerogation" class="bg-amber-600 hover:bg-amber-700 disabled:opacity-60 text-white px-4 py-2.5 rounded-xl text-xs font-semibold whitespace-nowrap">Demander une dérogation</button>
          </div>
        </div>

        <div class="lg:col-span-3 flex justify-end pt-1">
          <button type="submit" class="w-full sm:w-auto sm:min-w-48 bg-brand-600 hover:bg-brand-700 text-white font-semibold py-3 px-6 rounded-xl transition shadow-sm shadow-brand-600/20 text-sm">
            {{ typeOperation === 'entree' ? 'Ajouter au stock' : 'Enregistrer la ' + libelleType(typeOperation).toLowerCase() }}
          </button>
        </div>
      </form>
    </section>

    <!-- Journal -->
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="p-6 border-b border-gray-100 flex flex-wrap gap-3 justify-between items-center">
        <h3 class="font-bold text-gray-900 text-base">Journal des mouvements</h3>
        <div class="flex items-center space-x-3">
          <select name="filtretype" v-model="filtreType" class="px-3 py-1.5 bg-white border border-gray-200 rounded-xl text-xs focus:outline-none">
            <option value="">Tous les types</option>
            <option v-for="(v, k) in TYPES" :key="k" :value="k">{{ v.libelle }}</option>
          </select>
          <button @click="fetchMouvements" class="text-xs text-brand-600 hover:text-brand-800 font-semibold">Actualiser</button>
        </div>
      </div>

      <ul class="md:hidden divide-y divide-gray-100">
        <li v-if="loading" class="p-6 text-center text-sm text-gray-400">Chargement des mouvements...</li>
        <li v-else-if="mouvementsAffiches.length === 0" class="p-6 text-center text-sm text-gray-400">Aucun mouvement à afficher.</li>
        <li v-for="m in mouvementsPage" :key="'c' + m.id" class="px-4 py-3" :class="dejaAnnules.has(m.id) ? 'opacity-50' : ''">
          <div class="flex justify-between items-start gap-2">
            <span :class="classeType(m.type)" class="border text-xs px-2.5 py-1 rounded-full font-medium whitespace-nowrap">{{ libelleType(m.type) }}<span v-if="dejaAnnules.has(m.id)"> · annulé</span></span>
            <span class="text-[11px] text-gray-400">{{ dateMvt(m) }}</span>
          </div>
          <p class="text-sm font-semibold text-gray-900 mt-1.5">
            {{ m.declinaison?.article?.designation || '—' }}
            <span v-if="m.declinaison?.taille" class="ml-1 bg-gray-100 text-gray-700 text-xs px-2 py-0.5 rounded-full">{{ m.declinaison.taille }}</span>
            <span class="text-gray-500 font-normal"> × {{ m.quantite }}</span>
          </p>
          <p class="text-xs text-gray-500">{{ nomMilitaire(m) }} · {{ m.magasin?.nom || '—' }}</p>
          <p v-if="m.motif" class="text-[11px] text-gray-400 mt-0.5">Motif : {{ m.motif }}</p>
          <div v-if="aDesActions(m)" class="mt-2 space-x-4 text-xs font-medium">
            <button v-if="operateur && m.type === 'perception'" @click="telechargerBon(m)" class="text-blue-600">Bon PDF</button>
            <button v-if="m.type === 'perte'" @click="telechargerPv(m)" class="text-blue-600">PV de perte</button>
            <button v-if="annulable(m)" @click="ouvrirAnnulation(m)" class="text-red-600">Annuler</button>
          </div>
        </li>
      </ul>
      <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-gray-50/70 text-gray-400 text-xs font-semibold border-b border-gray-100">
              <th class="p-4 pl-6">N°</th>
              <th class="p-4">Date</th>
              <th class="p-4">Type</th>
              <th class="p-4">Militaire</th>
              <th class="p-4">Article</th>
              <th class="p-4">Magasin</th>
              <th class="p-4">Qté</th>
              <th class="p-4">Opérateur</th>
              <th v-if="afficherActions" class="p-4 pr-6 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 text-sm text-gray-700">
            <tr v-if="loading"><td colspan="9" class="p-8 text-center text-gray-400">Chargement des mouvements...</td></tr>
            <tr v-else-if="mouvementsAffiches.length === 0"><td colspan="9" class="p-8 text-center text-gray-400">Aucun mouvement à afficher.</td></tr>
            <tr v-for="m in mouvementsPage" :key="m.id" class="hover:bg-gray-50/50 transition" :class="dejaAnnules.has(m.id) ? 'opacity-50' : ''">
              <td class="p-4 pl-6 font-bold text-gray-900">#{{ m.id }}</td>
              <td class="p-4 text-xs text-gray-500 whitespace-nowrap">{{ dateMvt(m) }}</td>
              <td class="p-4">
                <span :class="classeType(m.type)" class="border text-xs px-3 py-1 rounded-full font-medium whitespace-nowrap inline-block">{{ libelleType(m.type) }}</span>
                <span v-if="dejaAnnules.has(m.id)" class="ml-1 text-[10px] text-gray-400">annulé</span>
              </td>
              <td class="p-4">{{ nomMilitaire(m) }}</td>
              <td class="p-4">
                {{ m.declinaison?.article?.designation || '—' }}
                <span v-if="m.declinaison?.taille" class="ml-1 bg-gray-100 text-gray-700 text-xs px-2 py-0.5 rounded-full">{{ m.declinaison.taille }}</span>
                <p v-if="m.motif" class="text-[11px] text-gray-400 mt-0.5">Motif : {{ m.motif }}</p>
              </td>
              <td class="p-4 text-gray-600">{{ m.magasin?.nom || '—' }}</td>
              <td class="p-4 font-semibold text-gray-900">{{ m.quantite }}</td>
              <td class="p-4 text-xs text-gray-500">{{ m.user?.name || m.utilisateur?.name || '—' }}</td>
              <td v-if="afficherActions" class="p-4 pr-6 text-right space-x-3 whitespace-nowrap">
                <button v-if="operateur && m.type === 'perception'" @click="telechargerBon(m)" class="text-blue-600 hover:text-blue-800 font-medium text-xs">Bon PDF</button>
                <button v-if="m.type === 'perte'" @click="telechargerPv(m)" class="text-blue-600 hover:text-blue-800 font-medium text-xs">PV de perte</button>
                <button v-if="annulable(m)" @click="ouvrirAnnulation(m)" class="text-red-600 hover:text-red-800 font-medium text-xs">Annuler</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pagination v-model:page="pageMvt" :pages="pagesMvt" :total="totalMvt" />
    </div>

    <!-- Modale d'annulation -->
    <div v-if="annulationEn" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4 backdrop-blur-sm">
      <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-gray-900 mb-1">Annuler le mouvement #{{ annulationEn.id }}</h3>
        <p class="text-sm text-gray-500 mb-4">Le mouvement reste dans le journal ; un mouvement inverse est créé et le stock est corrigé.</p>
        <form @submit.prevent="confirmerAnnulation" class="space-y-4">
          <div>
            <label for="mouvements-champ-2" class="block text-xs font-semibold text-gray-600 mb-1">Motif (obligatoire)</label>
            <input id="mouvements-champ-2" name="motifannulation" v-model="motifAnnulation" type="text" minlength="5" required class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-red-500/20" />
          </div>
          <div class="flex justify-end space-x-2">
            <button type="button" @click="annulationEn = null" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2.5 rounded-xl text-xs font-semibold">Retour</button>
            <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-5 py-2.5 rounded-xl text-xs font-semibold">Confirmer l'annulation</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
