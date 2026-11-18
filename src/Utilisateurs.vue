<script setup>
import { journalErreur } from '@/services/journal'
import { confirmer } from '@/services/confirm'
import { notify } from '@/services/notify'
import { usePagination } from '@/services/pagination'
import Pagination from './components/Pagination.vue'
import { ref, computed, onMounted } from 'vue'
import { chargerReferentiels } from '@/services/referentiels'
import api, { messageErreur, enListe } from '@/services/api'
import { LIBELLES_ROLES } from '@/services/roles'
import { MDP_MIN, genererMotDePasse } from '@/services/securite'

const props = defineProps({ user: { type: Object, default: null } })

const users = ref([])
const unites = ref([])
const magasins = ref([])
const loading = ref(true)
const showModal = ref(false)
const errorMessage = ref('')
const erreurModal = ref('')
const filtreRole = ref('')

const formVide = () => ({ name: '', email: '', password: '', role: 'magasinier', unite_id: '' })
const form = ref(formVide())

const usersAffiches = computed(() => (filtreRole.value ? users.value.filter((u) => u.role === filtreRole.value) : users.value))

const fetchUsers = async () => {
  try {
    loading.value = true
    users.value = enListe((await api.get('/users')).data)
  } catch (error) {
    errorMessage.value = messageErreur(error, 'Erreur de chargement des utilisateurs.')
  } finally {
    loading.value = false
  }
}

// Unités et magasins : référentiels partagés (GET /referentiels, contrat de la refonte)
const fetchReferentiels = async () => {
  try {
    const r = await chargerReferentiels(true)
    unites.value = r.unites
    magasins.value = r.magasins
  } catch (error) {
    journalErreur('Erreur chargement unités / magasins', error)
  }
}

const motDePasseVisible = ref(false)
const genererPourCompte = () => {
  form.value.password = genererMotDePasse(20)
  motDePasseVisible.value = true
  notify.info('Mot de passe généré : transmettez-le à l\'utilisateur de façon sûre ; il pourra le changer depuis « Sécurité du compte ».')
}

// Secours : l'utilisateur a perdu son téléphone et ses codes de secours
const retirerDoubleFacteur = async (u) => {
  if (!(await confirmer({ titre: 'Retirer la double authentification', message: `${u.name} se connectera avec son seul mot de passe jusqu'à ce qu'il la réactive. Ses sessions ouvertes seront fermées.`, bouton: 'Retirer' }))) return
  try {
    await api.delete(`/users/${u.id}/2fa`)
    notify.succes('Double authentification retirée.')
    fetchUsers()
  } catch (error) {
    errorMessage.value = messageErreur(error, 'Opération impossible.')
  }
}

const createUser = async () => {
  erreurModal.value = ''
  try {
    const payload = { ...form.value }
    // Seul le chef d'unité est rattaché à une unité (RG10)
    if (payload.role === 'chef_unite') payload.unite_id = Number(payload.unite_id)
    else delete payload.unite_id
    await api.post('/users', payload)
    showModal.value = false
    motDePasseVisible.value = false
    form.value = formVide()
    notify.succes('Compte créé.')
    fetchUsers()
  } catch (error) {
    erreurModal.value = messageErreur(error, 'Erreur lors de la création (vérifiez les champs).')
  }
}

const supprimerUser = async (u) => {
  if (u.id === props.user?.id) return
  if (!(await confirmer({ titre: 'Supprimer le compte', message: `${u.name} ne pourra plus se connecter.`, bouton: 'Supprimer' }))) return
  try {
    await api.delete(`/users/${u.id}`)
    notify.succes('Compte supprimé.')
    fetchUsers()
  } catch (error) {
    errorMessage.value = messageErreur(error, 'Impossible de supprimer ce compte.')
  }
}

// Rattachement d'un magasinier à un ou plusieurs magasins (RG8)
const showRattacher = ref(null)
const magasinChoisi = ref('')
const rattachesA = (u) => u?.magasins || []
const magasinsLibres = computed(() => magasins.value.filter((m) => !rattachesA(showRattacher.value).some((r) => r.id === m.id)))
const ouvrirRattacher = (u) => {
  showRattacher.value = u
  magasinChoisi.value = ''
}
const rafraichirCompte = async () => {
  await fetchUsers()
  if (showRattacher.value) showRattacher.value = users.value.find((x) => x.id === showRattacher.value.id) || null
}
const rattacher = async () => {
  try {
    await api.post(`/magasins/${magasinChoisi.value}/rattacher`, { user_id: showRattacher.value.id })
    notify.succes(`${showRattacher.value.name} rattaché au magasin.`)
    magasinChoisi.value = ''
    await rafraichirCompte()
  } catch (error) {
    errorMessage.value = messageErreur(error, 'Rattachement impossible.')
    showRattacher.value = null
  }
}
const detacher = async (m) => {
  const u = showRattacher.value
  if (!(await confirmer({ titre: 'Détacher du magasin', message: `${u.name} ne pourra plus opérer sur « ${m.libelle} ».`, bouton: 'Détacher' }))) return
  try {
    await api.delete(`/magasins/${m.id}/rattacher/${u.id}`)
    notify.succes(`${u.name} détaché de ${m.libelle}.`)
    await rafraichirCompte()
  } catch (error) {
    errorMessage.value = messageErreur(error, 'Détachement impossible.')
    showRattacher.value = null
  }
}

const classeRole = (r) =>
  ({
    administrateur: 'bg-red-50 text-red-700',
    magasinier: 'bg-amber-50 text-amber-700',
    chef_unite: 'bg-blue-50 text-blue-700',
    militaire: 'bg-brand-50 text-brand-700'
  })[r] || 'bg-gray-100 text-gray-600'

const { page: pageUsr, pages: pagesUsr, elements: usersPage, total: totalUsr } = usePagination(usersAffiches, 12)

onMounted(() => {
  fetchUsers()
  fetchReferentiels()
})
</script>

<template>
  <div>
    <div class="flex flex-wrap gap-3 justify-between items-center mb-6">
      <div class="flex items-center space-x-3">
        <div>
          <h1 class="text-xl sm:text-2xl font-semibold text-gray-900 tracking-tight">Gestion des comptes</h1>
          <p class="text-sm text-gray-500">Administrateurs, magasiniers, chefs d'unité et comptes militaires.</p>
        </div>
      </div>
      <div class="flex items-center space-x-3">
        <select name="filtrerole" v-model="filtreRole" class="px-3 py-2 bg-white border border-gray-200 rounded-xl text-sm focus:outline-none">
          <option value="">Tous les rôles</option>
          <option v-for="(lib, cle) in LIBELLES_ROLES" :key="cle" :value="cle">{{ lib }}</option>
        </select>
        <button @click="showModal = true" class="bg-brand-600 hover:bg-brand-700 text-white px-4 py-2.5 rounded-xl text-sm font-medium transition shadow-sm">+ Nouvel utilisateur</button>
      </div>
    </div>
    <div v-if="errorMessage" class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm flex justify-between">
      <span>{{ errorMessage }}</span><button @click="errorMessage = ''" class="font-bold">&times;</button>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <ul class="md:hidden divide-y divide-gray-100">
        <li v-if="loading" class="p-6 text-center text-sm text-gray-400">Chargement...</li>
        <li v-else-if="usersAffiches.length === 0" class="p-6 text-center text-sm text-gray-400">Aucun compte.</li>
        <li v-for="u in usersPage" :key="'c' + u.id" class="px-4 py-3">
          <div class="flex items-start justify-between gap-2">
            <p class="font-semibold text-gray-900 text-sm">{{ u.name }}</p>
            <span :class="classeRole(u.role)" class="text-[11px] px-2.5 py-0.5 rounded-full font-medium shrink-0">{{ LIBELLES_ROLES[u.role] || u.role }}</span>
          </div>
          <p v-if="u.two_factor_confirmed_at" class="text-[11px] text-brand-700 font-medium">Double authentification activée</p>
          <p class="text-xs text-gray-500 break-all">{{ u.email }}<span v-if="u.unite"> · {{ u.unite.libelle || u.unite.nom }}</span></p>
          <p v-if="u.role === 'magasinier'" class="text-xs mt-1" :class="rattachesA(u).length ? 'text-gray-600' : 'text-amber-700'">
            {{ rattachesA(u).length ? 'Magasins : ' + rattachesA(u).map((m) => m.libelle).join(', ') : 'Aucun magasin rattaché' }}
          </p>
          <div class="mt-2 space-x-4 text-xs font-medium">
            <button v-if="u.role === 'magasinier'" @click="ouvrirRattacher(u)" class="text-blue-600">Magasins</button>
            <button v-if="u.two_factor_confirmed_at && u.id !== user?.id" @click="retirerDoubleFacteur(u)" class="text-amber-700">Retirer la 2FA</button>
            <button v-if="u.id !== user?.id" @click="supprimerUser(u)" class="text-red-600">Supprimer</button>
          </div>
        </li>
      </ul>
      <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-gray-50/70 text-gray-400 text-xs font-semibold border-b border-gray-100">
              <th class="p-4 pl-6">Nom</th>
              <th class="p-4">Email</th>
              <th class="p-4">Rôle</th>
              <th class="p-4">Unité / magasins</th>
              <th class="p-4 pr-6 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 text-sm text-gray-700">
            <tr v-if="loading"><td colspan="5" class="p-6 text-center text-gray-400">Chargement...</td></tr>
            <tr v-else-if="usersAffiches.length === 0"><td colspan="5" class="p-6 text-center text-gray-400">Aucun compte.</td></tr>
            <tr v-for="u in usersPage" :key="u.id" class="hover:bg-gray-50/50 transition">
              <td class="p-4 pl-6 font-semibold text-gray-900">{{ u.name }}</td>
              <td class="p-4 text-gray-600">{{ u.email }}<span v-if="u.two_factor_confirmed_at" class="ml-2 text-[11px] bg-brand-50 text-brand-700 px-2 py-0.5 rounded-full font-medium">2FA</span></td>
              <td class="p-4"><span :class="classeRole(u.role)" class="text-xs px-2.5 py-1 rounded-full font-medium">{{ LIBELLES_ROLES[u.role] || u.role }}</span></td>
              <td class="p-4 text-gray-600">
                <template v-if="u.role === 'magasinier'">
                  <span v-if="!rattachesA(u).length" class="text-xs text-amber-700">Aucun magasin rattaché</span>
                  <span v-for="m in rattachesA(u)" :key="m.id" class="inline-block text-[11px] bg-gray-100 text-gray-700 px-2 py-0.5 rounded-full mr-1 mb-1">{{ m.libelle }}</span>
                </template>
                <template v-else>{{ u.unite?.libelle || u.unite?.nom || '—' }}</template>
              </td>
              <td class="p-4 pr-6 text-right space-x-3 whitespace-nowrap">
                <button v-if="u.role === 'magasinier'" @click="ouvrirRattacher(u)" class="text-blue-600 hover:text-blue-800 font-medium text-xs">Magasins</button>
                <button v-if="u.two_factor_confirmed_at && u.id !== user?.id" @click="retirerDoubleFacteur(u)" class="text-amber-700 hover:text-amber-900 font-medium text-xs">Retirer la 2FA</button>
                <button v-if="u.id !== user?.id" @click="supprimerUser(u)" class="text-red-600 hover:text-red-800 font-medium text-xs">Supprimer</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pagination v-model:page="pageUsr" :pages="pagesUsr" :total="totalUsr" />
    </div>

    <!-- Création -->
    <div v-if="showModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4 backdrop-blur-sm">
      <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-gray-900 mb-4">Créer un nouveau compte</h3>
        <div v-if="erreurModal" class="mb-3 p-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-xs">{{ erreurModal }}</div>
        <form @submit.prevent="createUser" class="space-y-4">
          <div>
            <label for="utilisateurs-champ-1" class="block text-xs font-semibold text-gray-500 mb-1">Nom complet</label>
            <input id="utilisateurs-champ-1" name="form_name" v-model="form.name" type="text" required class="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none" />
          </div>
          <div>
            <label for="utilisateurs-champ-2" class="block text-xs font-semibold text-gray-500 mb-1">Email</label>
            <input id="utilisateurs-champ-2" name="form_email" v-model="form.email" type="email" required class="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none" />
          </div>
          <div>
            <label for="utilisateurs-champ-3" class="block text-xs font-semibold text-gray-500 mb-1">Mot de passe</label>
            <input id="utilisateurs-champ-3" name="form_password" v-model="form.password" :type="motDePasseVisible ? 'text' : 'password'" required :minlength="MDP_MIN" autocomplete="new-password" class="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none" />
            <div class="flex items-center justify-between mt-1">
              <p class="text-[11px] text-gray-400">{{ MDP_MIN }} caractères minimum, sans nom ni email.</p>
              <button type="button" @click="genererPourCompte" class="text-[11px] font-semibold text-brand-700 hover:underline">Générer</button>
            </div>
          </div>
          <div>
            <label for="utilisateurs-champ-4" class="block text-xs font-semibold text-gray-500 mb-1">Rôle</label>
            <select id="utilisateurs-champ-4" name="form_role" v-model="form.role" class="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-white outline-none">
              <option value="magasinier">Magasinier</option>
              <option value="chef_unite">Chef d'unité</option>
              <option value="administrateur">Administrateur</option>
            </select>
            <p class="text-[11px] text-gray-400 mt-1">Les comptes militaires se créent par l'enrôlement (matricule + date d'incorporation).</p>
          </div>
          <div v-if="form.role === 'chef_unite'">
            <label for="utilisateurs-champ-5" class="block text-xs font-semibold text-gray-500 mb-1">Unité commandée</label>
            <select id="utilisateurs-champ-5" name="form_unite_id" v-model="form.unite_id" required class="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-white outline-none">
              <option value="" disabled>— Choisir —</option>
              <option v-for="un in unites" :key="un.id" :value="un.id">{{ un.code }} — {{ un.libelle || un.nom }}</option>
            </select>
          </div>
          <div class="flex justify-end space-x-2 pt-2">
            <button type="button" @click="showModal = false" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2.5 rounded-xl text-xs font-semibold">Annuler</button>
            <button type="submit" class="bg-brand-600 hover:bg-brand-700 text-white px-5 py-2.5 rounded-xl text-xs font-semibold shadow-sm">Enregistrer</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Rattachement magasinier -->
    <div v-if="showRattacher" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4 backdrop-blur-sm">
      <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl">
        <h3 class="text-lg font-bold text-gray-900 mb-1">Magasins de {{ showRattacher.name }}</h3>
        <p class="text-xs text-gray-500 mb-4">Un magasinier ne peut opérer (entrées, réformes, bons) que sur les magasins auxquels il est rattaché.</p>

        <p class="text-xs font-semibold text-gray-500 mb-2">Rattaché à</p>
        <ul class="mb-4 divide-y divide-gray-100 border border-gray-100 rounded-xl">
          <li v-if="!rattachesA(showRattacher).length" class="px-3 py-2.5 text-sm text-amber-700">Aucun magasin pour l'instant.</li>
          <li v-for="m in rattachesA(showRattacher)" :key="m.id" class="px-3 py-2.5 flex items-center justify-between text-sm">
            <span class="text-gray-800">{{ m.libelle }}</span>
            <button @click="detacher(m)" class="text-red-600 hover:text-red-800 text-xs font-medium">Détacher</button>
          </li>
        </ul>

        <label for="rattacher-magasin" class="block text-xs font-semibold text-gray-500 mb-1">Ajouter un magasin</label>
        <div class="flex gap-2 mb-5">
          <select id="rattacher-magasin" name="magasinchoisi" v-model="magasinChoisi" class="flex-1 min-w-0 px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm bg-white outline-none">
            <option value="" disabled>{{ magasinsLibres.length ? '— Choisir un magasin —' : 'Tous les magasins sont déjà rattachés' }}</option>
            <option v-for="m in magasinsLibres" :key="m.id" :value="m.id">{{ m.libelle || m.nom }}</option>
          </select>
          <button @click="rattacher" :disabled="!magasinChoisi" class="bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white px-4 py-2.5 rounded-xl text-xs font-semibold shrink-0">Rattacher</button>
        </div>
        <div class="flex justify-end">
          <button @click="showRattacher = null" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2.5 rounded-xl text-xs font-semibold">Fermer</button>
        </div>
      </div>
    </div>
  </div>
</template>
