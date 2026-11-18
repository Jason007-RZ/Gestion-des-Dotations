<script setup>
import { ref } from 'vue'
import api, { messageErreur } from '@/services/api'

// Événements émis vers App.vue
const emit = defineEmits(['login-success', 'aller-enrolement', 'aller-mdp']) // <--- Ajout de 'aller-mdp'

const email = ref('')
const password = ref('')
const loading = ref(false)
const errorMessage = ref('')
const passwordVisible = ref(false)

// Double authentification : le serveur renvoie un « défi » au lieu d'un jeton, puis attend le code.
const defi = ref('')
const code = ref('')
const codeSecours = ref('')
const utiliserSecours = ref(false)

const ouvrirSession = (data) => {
  const token = data.token || data.access_token
  const user = data.user

  if (!token) {
    errorMessage.value = 'Réponse du serveur invalide (aucun jeton reçu).'
    return
  }
  localStorage.setItem('auth_token', token)
  if (user) localStorage.setItem('user_info', JSON.stringify(user))
  api.defaults.headers.common['Authorization'] = `Bearer ${token}`
  password.value = '' // le mot de passe ne reste pas en mémoire
  emit('login-success', user)
}

const retourConnexion = () => {
  defi.value = ''
  code.value = ''
  codeSecours.value = ''
  utiliserSecours.value = false
  errorMessage.value = ''
}

const handleLogin = async () => {
  loading.value = true
  errorMessage.value = ''

  try {
    const response = await api.post('/login', {
      email: email.value,
      password: password.value
    })

    if (response.data.two_factor) {
      defi.value = response.data.defi
      password.value = ''
      return
    }
    ouvrirSession(response.data)
  } catch (error) {
    // On n'écrit jamais l'objet d'erreur dans la console : il contient la requête, donc le mot de passe.
    const statut = error.response?.status
    if (statut === 422) {
      errorMessage.value = error.response.data?.errors?.email?.[0] || 'Identifiants invalides ou champs manquants.'
    } else if (statut === 401) {
      errorMessage.value = 'Email ou mot de passe incorrect.'
    } else if (statut === 429) {
      errorMessage.value = messageErreur(error)
    } else {
      errorMessage.value = 'Impossible de joindre le serveur Laravel (vérifiez votre connexion).'
    }
  } finally {
    loading.value = false
  }
}

const handleCode = async () => {
  loading.value = true
  errorMessage.value = ''

  try {
    const corps = { defi: defi.value, ...(utiliserSecours.value ? { code_secours: codeSecours.value } : { code: code.value }) }
    const response = await api.post('/login/2fa', corps)
    ouvrirSession(response.data)
  } catch (error) {
    errorMessage.value = messageErreur(error, 'Code incorrect.')
    // Défi expiré ou épuisé : retour à la saisie de l'email et du mot de passe
    if (error.response?.status === 422 && /expir|reconnectez/i.test(errorMessage.value)) defi.value = ''
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen bg-slate-900 flex flex-col justify-center py-8 px-4 sm:px-6 lg:px-8 font-sans antialiased">
    <div class="sm:mx-auto sm:w-full sm:max-w-5xl">
      <div class="bg-white shadow-2xl rounded-3xl overflow-hidden grid grid-cols-1 lg:grid-cols-12 min-h-[550px] border border-slate-800">
        
        <!-- COLONNE GAUCHE : Formulaire de Connexion -->
        <div class="lg:col-span-7 p-8 sm:p-12 flex flex-col justify-center">
          
          <div class="mb-6">
            <h2 class="text-2xl font-black text-slate-900 tracking-tight">Gestion des Dotations</h2>
            <p class="text-xs font-semibold text-blue-600 tracking-wider uppercase mt-1">
              Ministère des Forces Armées
            </p>

            <div class="inline-flex items-center space-x-1.5 mt-3 px-3.5 py-1 rounded-full border border-brand-500/40 bg-brand-50 text-brand-700 text-[10px] font-bold tracking-wider uppercase">
              <span>🛡️</span>
              <span>Système sécurisé</span>
            </div>
          </div>

          <div v-if="errorMessage" class="mb-4 p-3 bg-red-50 border-l-4 border-red-500 text-red-700 text-xs rounded-lg">
            {{ errorMessage }}
          </div>

          <!-- Étape 2 : code de la double authentification -->
          <form v-if="defi" @submit.prevent="handleCode" class="space-y-4">
            <p class="text-sm text-slate-700">
              Ouvrez votre application d'authentification et saisissez le code à 6 chiffres.
            </p>
            <div>
              <label for="code2fa" class="block text-[11px] font-black text-slate-900 uppercase tracking-wider mb-1.5">
                {{ utiliserSecours ? 'Code de secours' : 'Code de vérification' }}
              </label>
              <input
                v-if="!utiliserSecours"
                id="code2fa"
                v-model="code"
                name="one_time_code"
                type="text"
                inputmode="numeric"
                maxlength="7"
                required
                autofocus
                autocomplete="one-time-code"
                placeholder="123 456"
                class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50/50 text-lg font-mono tracking-[0.3em] text-center text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:bg-white transition"
              />
              <input
                v-else
                id="code2fa"
                v-model="codeSecours"
                name="recovery_code"
                type="text"
                maxlength="12"
                required
                autofocus
                autocomplete="off"
                placeholder="XXXXX-XXXXX"
                class="w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50/50 text-lg font-mono tracking-widest uppercase text-center text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:bg-white transition"
              />
              <button type="button" @click="utiliserSecours = !utiliserSecours; code = ''; codeSecours = ''" class="text-xs text-blue-600 hover:underline font-semibold mt-2">
                {{ utiliserSecours ? "Utiliser l'application d'authentification" : 'Utiliser un code de secours' }}
              </button>
            </div>
            <button type="submit" :disabled="loading" class="w-full bg-[#2f5d3a] hover:bg-[#264c2f] text-white font-bold py-3.5 rounded-xl text-sm transition shadow-lg shadow-brand-600/30 disabled:opacity-50">
              {{ loading ? 'Vérification...' : 'Valider' }}
            </button>
            <button type="button" @click="retourConnexion" class="w-full text-xs text-slate-500 hover:underline">Retour à la connexion</button>
          </form>

          <form v-else @submit.prevent="handleLogin" class="space-y-4">
            
            <!-- Adresse Email -->
            <div>
              <label for="email" class="block text-[11px] font-black text-slate-900 uppercase tracking-wider mb-1.5">Adresse email</label>
              <div class="relative">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 text-sm">✉</span>
                <input 
                  id="email"
                  v-model="email" 
                  type="email" 
                  name="email"
                  autocomplete="username"
                  required 
                  placeholder="magasinier@armees.mg"
                  class="w-full pl-10 pr-4 py-3 rounded-xl border border-slate-200 bg-slate-50/50 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:bg-white transition"
                />
              </div>
            </div>

            <!-- Mot de passe -->
            <div>
              <div class="flex justify-between items-center mb-1.5">
                <label for="password" class="block text-[11px] font-black text-slate-900 uppercase tracking-wider">Mot de passe</label>
                <!-- Lien Mot de passe oublié -->
                <button 
                  type="button" 
                  @click="emit('aller-mdp')" 
                  class="text-xs text-blue-600 hover:underline font-semibold transition"
                >
                  Mot de passe oublié ?
                </button>
              </div>
              <div class="relative">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 text-sm">🔒</span>
                <input 
                  id="password"
                  v-model="password" 
                  :type="passwordVisible ? 'text' : 'password'" 
                  name="password"
                  autocomplete="current-password"
                  required 
                  placeholder="••••••••"
                  class="w-full pl-10 pr-11 py-3 rounded-xl border border-slate-200 bg-slate-50/50 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:bg-white transition"
                />
                <button
                  type="button"
                  @click="passwordVisible = !passwordVisible"
                  :aria-label="passwordVisible ? 'Masquer le mot de passe' : 'Afficher le mot de passe'"
                  class="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 transition"
                >
                  <svg v-if="passwordVisible" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" />
                    <circle cx="12" cy="12" r="3" />
                  </svg>
                  <svg v-else xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24" />
                    <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c6.5 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68" />
                  </svg>
                </button>
              </div>
            </div>

            <!-- Bouton de Connexion -->
            <div class="pt-2">
              <button 
                type="submit" 
                :disabled="loading"
                class="w-full bg-[#2f5d3a] hover:bg-[#264c2f] active:bg-[#1f3d27] text-white font-bold py-3.5 rounded-xl text-sm transition shadow-lg shadow-brand-600/30 disabled:opacity-50 flex items-center justify-center space-x-2"
              >
                <svg v-if="loading" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>{{ loading ? 'Connexion en cours...' : 'Se connecter' }}</span>
              </button>
            </div>
          </form>

          <div v-if="!defi" class="relative my-6 text-center">
            <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-slate-200"></div></div>
            <span class="relative px-3 bg-white text-xs text-slate-400 font-medium">ou</span>
          </div>

          <div v-if="!defi" class="text-center">
            <p class="text-xs text-slate-600">
              Pas encore de compte ? 
              <button @click="emit('aller-enrolement')" class="text-[#2f5d3a] font-bold hover:underline transition ml-1">
                S'enrôler ici
              </button>
            </p>
          </div>

        </div>

        <!-- COLONNE DROITE : Bloc institutionnel bleu -->
        <div class="lg:col-span-5 bg-gradient-to-br from-blue-700 via-blue-800 to-blue-950 text-white p-10 flex flex-col items-center justify-center text-center relative overflow-hidden">
          <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(255,255,255,0.1),transparent)] pointer-events-none"></div>

          <div class="relative z-10 space-y-4 flex flex-col items-center">
            <div class="w-24 h-24 bg-amber-400 rounded-full p-1 border-4 border-white/20 shadow-2xl flex items-center justify-center overflow-hidden">
              <img src="@/assets/logo.jpeg" alt="Logo Forces Armées" class="w-full h-full object-cover rounded-full" />
            </div>

            <div class="space-y-1">
              <h3 class="text-xl font-black tracking-tight">Ministère des Forces Armées</h3>
              <p class="text-xs text-blue-200 font-medium uppercase tracking-wider">République de Madagascar</p>
            </div>

            <div class="w-12 h-1 bg-amber-500 rounded-full my-1"></div>

            <p class="text-xs text-blue-100 max-w-xs leading-relaxed">
              Système sécurisé · Réservé au personnel habilité.
            </p>
          </div>
        </div>

      </div>
    </div>
  </div>
</template>