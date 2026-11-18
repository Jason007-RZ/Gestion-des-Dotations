<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import api from '@/services/api'
import { LIBELLES_ROLES, peutVoir, ongletParDefaut } from '@/services/roles'
import Login from './Login.vue'
import Enrolement from './Enrolement.vue'
import MDP from './MDP.vue'
import ReinitialiserMdp from './ReinitialiserMdp.vue'
import Catalogue from './Catalogue.vue'
import Mouvements from './Mouvements.vue'
import Demandes from './Demandes.vue'
import Stocks from './Stocks.vue'
import Audit from './Audit.vue'
import Securite from './Securite.vue'
import Effectifs from './Effectifs.vue'
import Previsions from './Previsions.vue'
import Utilisateurs from './Utilisateurs.vue'
import MonEspace from './MonEspace.vue'
import Dashboard from './Dashboard.vue'
import AppIcon from './components/AppIcon.vue'
import ToastHost from './components/ToastHost.vue'
import ConfirmDialog from './components/ConfirmDialog.vue'
import { notify } from '@/services/notify'
import { utiliserInactivite } from '@/services/inactivite'

// ----- Session -----
// Lecture défensive : un stockage indisponible ou une valeur corrompue ne doit pas faire planter l'application
// (elle se resynchronise ensuite avec le serveur via /me).
const lireStockage = (cle) => {
  try {
    return localStorage.getItem(cle)
  } catch {
    return null
  }
}
const lireUtilisateur = () => {
  try {
    return JSON.parse(lireStockage('user_info') || 'null')
  } catch {
    return null
  }
}
const isAuthenticated = ref(!!lireStockage('auth_token'))
const currentUser = ref(lireUtilisateur())
const authView = ref('login') // 'login' | 'enrolement' | 'mdp'

// Lien de réinitialisation reçu par e-mail : <adresse>/#reinitialiser?token=...&email=...
// Le jeton est dans le fragment (#) : un fragment n'est jamais envoyé au serveur ni dans l'en-tête Referer.
// On le lit une fois, puis on le retire aussitôt de la barre d'adresse et de l'historique.
const lireLienReinitialisation = () => {
  const brut = window.location.hash.match(/^#reinitialiser\?(.+)$/)
  if (!brut) return null
  window.history.replaceState(null, '', window.location.pathname + window.location.search)
  const params = new URLSearchParams(brut[1])
  const token = params.get('token')
  const email = params.get('email')
  return token && email ? { token, email } : null
}
const lienReinitialisation = ref(lireLienReinitialisation())
const terminerReinitialisation = () => {
  lienReinitialisation.value = null
  viderSession() // le serveur a de toute façon révoqué les anciennes sessions de ce compte
}
const role = computed(() => currentUser.value?.role)

// ----- Navigation filtrée par rôle (cahier des charges §4) -----
const MENU = [
  { id: 'dashboard', section: 'Principal', label: 'Tableau de bord', court: 'Accueil', icone: 'accueil' },
  { id: 'monespace', section: 'Principal', label: 'Mon espace', court: 'Mon espace', icone: 'moi' },
  { id: 'mouvements', section: 'Principal', label: 'Mouvements', court: 'Mouvements', icone: 'mouvements' },
  { id: 'demandes', section: 'Principal', label: 'Demandes', court: 'Demandes', icone: 'demandes' },
  { id: 'stocks', section: 'Principal', label: 'Stock des magasins', court: 'Stock', icone: 'stocks' },
  { id: 'catalogue', section: 'Principal', label: 'Catalogue et barèmes', court: 'Catalogue', icone: 'catalogue' },
  { id: 'effectifs', section: 'Personnel', label: 'Effectifs et mensurations', court: 'Effectifs', icone: 'effectifs' },
  { id: 'previsions', section: 'Personnel', label: 'Prévisions des besoins', court: 'Prévisions', icone: 'previsions' },
  { id: 'utilisateurs', section: 'Administration', label: 'Gestion des comptes', court: 'Comptes', icone: 'comptes' },
  { id: 'audit', section: 'Administration', label: "Journal d'audit", court: 'Audit', icone: 'audit' }
]

const menuVisible = computed(() => MENU.filter((m) => peutVoir(m.id, role.value)))
const sections = computed(() => {
  const ordre = []
  for (const m of menuVisible.value) {
    let s = ordre.find((x) => x.nom === m.section)
    if (!s) ordre.push((s = { nom: m.section, items: [] }))
    s.items.push(m)
  }
  return ordre
})

const currentTab = ref(ongletParDefaut(role.value))
const libelleOnglet = (id) => MENU.find((m) => m.id === id)?.label || (id === 'securite' ? 'Sécurité du compte' : undefined)
const initiales = computed(() => (currentUser.value?.name || currentUser.value?.email || '??').substring(0, 2).toUpperCase())

// ----- Tableau de bord : chargé par <Dashboard />, qui nous renvoie le nombre d'alertes de stock -----
const nbAlertes = ref(0)

// ----- Demandes à traiter (pastille du menu) -----
// Chef d'unité et administrateur : demandes à valider ; magasinier : demandes validées à enregistrer.
// Pas de rafraîchissement automatique en arrière-plan : une requête périodique compterait comme de l'activité
// côté serveur (délai d'inactivité du jeton). On recompte à la connexion, à chaque changement d'écran et après chaque action.
const nbDemandes = ref(0)
const chargerCompteurDemandes = async () => {
  if (!isAuthenticated.value || !peutVoir('demandes', role.value)) return
  try {
    const { data } = await api.get('/demandes/compteurs')
    nbDemandes.value = Number(data?.a_traiter) || 0
  } catch (e) {
    /* sans gravité : la pastille n'est qu'une aide */
  }
}
watch(currentTab, chargerCompteurDemandes)

// ----- Authentification -----
const logout = async () => {
  try {
    await api.post('/logout') // révoque le jeton côté Laravel
  } catch (e) {
    /* jeton déjà invalide : on déconnecte quand même */
  }
  viderSession()
}

const viderSession = () => {
  localStorage.removeItem('auth_token')
  localStorage.removeItem('user_info')
  isAuthenticated.value = false
  currentUser.value = null
  authView.value = 'login'
  nbAlertes.value = 0
  nbDemandes.value = 0
}

const demarrerSession = (user) => {
  isAuthenticated.value = true
  currentUser.value = user
  currentTab.value = ongletParDefaut(user?.role)
  prolonger()
  chargerCompteurDemandes()
}

const handleLoginSuccess = (user) => demarrerSession(user)
const handleEnrolementSuccess = (user) => demarrerSession(user)

// Le jeton peut être expiré (ex. après migrate:fresh côté serveur) : on revalide avec /me
const revaliderSession = async () => {
  try {
    const { data } = await api.get('/me')
    currentUser.value = { ...currentUser.value, ...data }
    localStorage.setItem('user_info', JSON.stringify(currentUser.value))
    if (!peutVoir(currentTab.value, data.role)) currentTab.value = ongletParDefaut(data.role)
  } catch (e) {
    /* un 401 déclenche l'événement session-expiree via l'intercepteur */
  }
}

const surSessionExpiree = () => {
  viderSession()
  notify.info('Votre session a expiré. Reconnectez-vous.')
}

// ----- Serveur injoignable (réseau coupé, backend arrêté, mauvaise adresse d'API) -----
const serveurInjoignable = ref(false)
const surServeurInjoignable = () => (serveurInjoignable.value = true)
const surServeurOk = () => {
  if (serveurInjoignable.value) notify.succes('Connexion au serveur rétablie.')
  serveurInjoignable.value = false
}

// ----- Déconnexion automatique après 15 minutes d'inactivité (avertissement 60 s avant) -----
const { secondesRestantes, prolonger } = utiliserInactivite({
  delaiMs: 15 * 60 * 1000,
  avertissementMs: 60 * 1000,
  onExpire: () => {
    if (!isAuthenticated.value) return
    viderSession()
    notify.info('Session fermée automatiquement après une période d\'inactivité.')
  }
})

onMounted(() => {
  window.addEventListener('session-expiree', surSessionExpiree)
  window.addEventListener('serveur-injoignable', surServeurInjoignable)
  window.addEventListener('serveur-ok', surServeurOk)
  if (isAuthenticated.value) {
    revaliderSession()
    chargerCompteurDemandes()
  }
})
onBeforeUnmount(() => {
  window.removeEventListener('session-expiree', surSessionExpiree)
  window.removeEventListener('serveur-injoignable', surServeurInjoignable)
  window.removeEventListener('serveur-ok', surServeurOk)
})
</script>

<template>
  <!-- Lien de réinitialisation reçu par e-mail : prioritaire sur tout le reste -->
  <ReinitialiserMdp v-if="lienReinitialisation" :lien="lienReinitialisation" @retour-login="terminerReinitialisation" />

  <!-- Non authentifié : Login / Enrôlement / Mot de passe oublié -->
  <template v-else-if="!isAuthenticated">
    <Login
      v-if="authView === 'login'"
      @login-success="handleLoginSuccess"
      @aller-enrolement="authView = 'enrolement'"
      @aller-mdp="authView = 'mdp'"
    />
    <Enrolement v-else-if="authView === 'enrolement'" @enrolement-success="handleEnrolementSuccess" @retour-login="authView = 'login'" />
    <MDP v-else-if="authView === 'mdp'" @retour-login="authView = 'login'" />
  </template>

  <!-- Authentifié -->
  <div v-else class="min-h-screen flex flex-col md:flex-row bg-page font-sans">
    <!-- Menu latéral (ordinateur) -->
    <aside class="hidden md:flex md:w-64 shrink-0 bg-side text-stone-200 flex-col justify-between p-4 sticky top-0 h-screen select-none">
      <div>
        <div class="flex items-center gap-3 px-2 py-3 mb-5">
          <img src="@/assets/logo.jpeg" alt="" class="h-10 w-10 rounded-lg object-cover bg-amber-300 shrink-0" />
          <div class="leading-tight">
            <p class="text-sm font-semibold text-white">Dotations militaires</p>
            <p class="text-[11px] text-stone-400">Ministère des Forces Armées</p>
          </div>
        </div>

        <nav v-for="section in sections" :key="section.nom" class="mb-4" :aria-label="section.nom">
          <p class="px-3 mb-1 text-xs text-stone-500">{{ section.nom }}</p>
          <button
            v-for="m in section.items"
            :key="m.id"
            @click="currentTab = m.id"
            :aria-current="currentTab === m.id ? 'page' : undefined"
            :class="currentTab === m.id ? 'bg-white/10 text-white' : 'text-stone-300 hover:bg-white/5 hover:text-white'"
            class="relative w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors"
          >
            <span v-if="currentTab === m.id" class="absolute left-0 top-2 bottom-2 w-1 rounded-r bg-brand-300"></span>
            <AppIcon :nom="m.icone" :taille="18" />
            <span class="flex-1 text-left">{{ m.label }}</span>
            <span v-if="m.id === 'dashboard' && nbAlertes > 0" class="bg-red-500 text-white text-[11px] font-semibold px-1.5 min-w-5 text-center rounded-full">{{ nbAlertes }}</span>
            <span v-if="m.id === 'demandes' && nbDemandes > 0" class="bg-amber-500 text-white text-[11px] font-semibold px-1.5 min-w-5 text-center rounded-full" :title="nbDemandes + ' demande(s) à traiter'">{{ nbDemandes }}</span>
          </button>
        </nav>
      </div>

      <div class="border-t border-white/10 pt-3 flex items-center gap-3 px-1">
        <button
          @click="currentTab = 'securite'"
          title="Sécurité du compte"
          aria-label="Sécurité du compte"
          :aria-current="currentTab === 'securite' ? 'page' : undefined"
          class="flex items-center gap-3 min-w-0 flex-1 text-left rounded-lg px-1 py-1 -mx-1 hover:bg-white/5 transition-colors"
          :class="currentTab === 'securite' ? 'bg-white/10' : ''"
        >
          <span class="h-9 w-9 rounded-full bg-brand-600 text-white text-xs font-semibold flex items-center justify-center shrink-0">{{ initiales }}</span>
          <span class="min-w-0 flex-1 leading-tight">
            <span class="block text-sm font-medium text-white truncate">{{ currentUser?.name || currentUser?.email }}</span>
            <span class="block text-xs text-stone-400 truncate">{{ LIBELLES_ROLES[role] || role }}<span v-if="currentUser?.unite"> · {{ currentUser.unite.nom }}</span></span>
          </span>
        </button>
        <button @click="logout" title="Se déconnecter" aria-label="Se déconnecter" class="p-2 rounded-lg text-stone-400 hover:text-white hover:bg-white/10 transition-colors">
          <AppIcon nom="sortie" :taille="18" />
        </button>
      </div>
    </aside>

    <div class="flex-1 min-w-0 flex flex-col">
      <div v-if="serveurInjoignable" class="bg-red-600 text-white text-xs sm:text-sm text-center px-4 py-2" role="alert">
        Serveur injoignable. Vérifiez que le backend est démarré et que vous êtes sur le même réseau. Nouvelle tentative à chaque action.
      </div>

      <!-- Barre du haut (téléphone) -->
      <header class="md:hidden sticky top-0 z-20 bg-side text-white px-4 h-14 flex items-center justify-between">
        <div class="flex items-center gap-3 min-w-0">
          <img src="@/assets/logo.jpeg" alt="" class="h-8 w-8 rounded-md object-cover bg-amber-300 shrink-0" />
          <h1 class="text-base font-semibold truncate">{{ libelleOnglet(currentTab) }}</h1>
        </div>
        <div class="flex items-center gap-1">
          <button @click="currentTab = 'securite'" aria-label="Sécurité du compte" class="p-1 rounded-full active:bg-white/10">
            <span class="h-8 w-8 rounded-full bg-brand-600 text-white text-xs font-semibold flex items-center justify-center">{{ initiales }}</span>
          </button>
          <button @click="logout" aria-label="Se déconnecter" class="text-stone-300 p-2 rounded-lg active:bg-white/10">
            <AppIcon nom="sortie" :taille="18" />
          </button>
        </div>
      </header>

      <main class="flex-1 p-3 sm:p-5 lg:p-8 pb-24 md:pb-8">
        <div class="max-w-6xl mx-auto">
          <Dashboard v-if="currentTab === 'dashboard' && peutVoir('dashboard', role)" :user="currentUser" @alertes="nbAlertes = $event" @aller="currentTab = $event" />
          <MonEspace v-else-if="currentTab === 'monespace' && peutVoir('monespace', role)" :user="currentUser" @aller="currentTab = $event" />
          <Stocks v-else-if="currentTab === 'stocks' && peutVoir('stocks', role)" :user="currentUser" />
          <Catalogue v-else-if="currentTab === 'catalogue'" :user="currentUser" />
          <Mouvements v-else-if="currentTab === 'mouvements'" :user="currentUser" @aller="currentTab = $event" @demandes-maj="chargerCompteurDemandes" />
          <Demandes v-else-if="currentTab === 'demandes' && peutVoir('demandes', role)" :user="currentUser" @maj="chargerCompteurDemandes" />
          <Effectifs v-else-if="currentTab === 'effectifs' && peutVoir('effectifs', role)" :user="currentUser" />
          <Previsions v-else-if="currentTab === 'previsions' && peutVoir('previsions', role)" />
          <Utilisateurs v-else-if="currentTab === 'utilisateurs' && peutVoir('utilisateurs', role)" :user="currentUser" />
          <Audit v-else-if="currentTab === 'audit' && peutVoir('audit', role)" />
          <Securite v-else-if="currentTab === 'securite'" :user="currentUser" />
          <div v-else class="p-6 text-sm text-gray-500">{{ libelleOnglet(currentTab) || 'Cette page' }} n'est pas accessible avec votre rôle.</div>
        </div>
      </main>
    </div>

    <!-- Barre du bas (téléphone) : à portée de pouce -->
    <!-- Défile horizontalement quand il y a plus d'entrées que la largeur n'en contient (l'administrateur en a neuf) -->
    <nav class="md:hidden fixed bottom-0 inset-x-0 z-30 bg-white border-t border-gray-200 flex overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden pb-[env(safe-area-inset-bottom)]" aria-label="Navigation principale">
      <button
        v-for="m in menuVisible"
        :key="m.id"
        @click="currentTab = m.id"
        :aria-current="currentTab === m.id ? 'page' : undefined"
        :class="currentTab === m.id ? 'text-brand-700' : 'text-gray-500'"
        class="relative flex-1 min-w-[3.75rem] shrink-0 flex flex-col items-center justify-center gap-0.5 py-2 min-h-14 text-[10px] sm:text-[11px] leading-tight font-medium"
      >
        <span v-if="currentTab === m.id" class="absolute top-0 h-0.5 w-8 rounded-b bg-brand-600"></span>
        <span class="relative">
          <AppIcon :nom="m.icone" :taille="22" />
          <span v-if="m.id === 'dashboard' && nbAlertes > 0" class="absolute -top-1 -right-2 bg-red-500 text-white text-[10px] font-semibold px-1 min-w-4 text-center rounded-full">{{ nbAlertes }}</span>
          <span v-if="m.id === 'demandes' && nbDemandes > 0" class="absolute -top-1 -right-2 bg-amber-500 text-white text-[10px] font-semibold px-1 min-w-4 text-center rounded-full">{{ nbDemandes }}</span>
        </span>
        <span class="max-w-full truncate px-0.5">{{ m.court }}</span>
      </button>
    </nav>
  </div>

  <ToastHost />
  <ConfirmDialog />

  <!-- Avertissement d'inactivité -->
  <div v-if="isAuthenticated && secondesRestantes !== null" class="fixed inset-0 z-[95] bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-sm w-full p-6 shadow-2xl text-center" role="alertdialog" aria-modal="true">
      <div class="text-3xl mb-2">⏳</div>
      <h3 class="text-lg font-bold text-gray-900 mb-1">Toujours là ?</h3>
      <p class="text-sm text-gray-500 mb-5">
        Pour protéger les données, la session va se fermer dans
        <strong class="text-gray-900">{{ secondesRestantes }} s</strong> faute d'activité.
      </p>
      <div class="flex justify-center space-x-2">
        <button @click="logout" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2.5 rounded-xl text-xs font-semibold">Me déconnecter</button>
        <button @click="prolonger" class="bg-brand-600 hover:bg-brand-700 text-white px-5 py-2.5 rounded-xl text-xs font-semibold">Rester connecté</button>
      </div>
    </div>
  </div>
</template>
