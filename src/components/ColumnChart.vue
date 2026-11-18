<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue'

// Histogramme à une série (une seule couleur => pas de légende ; le titre dit ce qui est tracé).
// points : [{ cle, label, valeur }]
const props = defineProps({
  titre: { type: String, required: true },
  sousTitre: { type: String, default: '' },
  points: { type: Array, default: () => [] },
  unite: { type: String, default: '' },
  chargement: { type: Boolean, default: false }
})

// Largeur réelle du conteneur : le texte garde sa taille sur téléphone (pas de réduction du SVG entier)
const conteneur = ref(null)
const W = ref(560)
const H = 210
const M = { g: 34, d: 8, h: 14, b: 28 }
const largeurUtile = computed(() => W.value - M.g - M.d)
const hauteurUtile = H - M.h - M.b
let observateur = null
// Le conteneur n'existe que lorsque le graphique est affiché (pas en mode tableau ni sans donnée) : on observe dès qu'il apparaît
watch(
  conteneur,
  (el) => {
    observateur?.disconnect()
    if (!el) return
    const maj = () => {
      if (el.clientWidth) W.value = el.clientWidth
    }
    maj()
    if (typeof ResizeObserver !== 'undefined') {
      observateur = new ResizeObserver(maj)
      observateur.observe(el)
    }
  },
  { flush: 'post' }
)
onBeforeUnmount(() => observateur?.disconnect())

const max = computed(() => Math.max(0, ...props.points.map((p) => p.valeur)))
// Plafond « rond » de l'axe : 1, 2, 5, 10, 20, 50...
const plafond = computed(() => {
  const m = Math.max(1, max.value)
  const p = 10 ** Math.floor(Math.log10(m))
  for (const k of [1, 2, 5, 10]) if (k * p >= m) return k * p
  return m
})
const graduations = computed(() => [0, plafond.value / 2, plafond.value])
const yDe = (v) => M.h + hauteurUtile - (v / plafond.value) * hauteurUtile

const pas = computed(() => largeurUtile.value / Math.max(1, props.points.length))
const largeurBarre = computed(() => Math.min(24, pas.value * 0.6)) // épaisseur plafonnée à 24 px
const xCentre = (i) => M.g + pas.value * i + pas.value / 2

// Barre arrondie (4 px) sur l'extrémité de la donnée, carrée sur la ligne de base
const chemin = (i, valeur) => {
  const h = (valeur / plafond.value) * hauteurUtile
  if (h <= 0) return ''
  const x = xCentre(i) - largeurBarre.value / 2
  const y = M.h + hauteurUtile - h
  const w = largeurBarre.value
  const r = Math.min(4, h, w / 2)
  return `M${x},${y + h} L${x},${y + r} Q${x},${y} ${x + r},${y} L${x + w - r},${y} Q${x + w},${y} ${x + w},${y + r} L${x + w},${y + h} Z`
}

// Une étiquette sur deux ou trois selon le nombre de points (jamais de chevauchement)
const cadence = computed(() => Math.max(1, Math.ceil(props.points.length / 7)))
const iMax = computed(() => (max.value > 0 ? props.points.findIndex((p) => p.valeur === max.value) : -1))

const actif = ref(-1)
const modeTableau = ref(false)
const vide = computed(() => max.value === 0)

// Position de l'infobulle en pourcentage du conteneur (reste dans le cadre)
const posInfobulle = computed(() => {
  if (actif.value < 0) return null
  const gauche = (xCentre(actif.value) / W.value) * 100
  const haut = (yDe(props.points[actif.value].valeur) / H) * 100
  return { left: Math.min(88, Math.max(12, gauche)) + '%', top: Math.max(4, haut - 4) + '%' }
})
</script>

<template>
  <section class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
    <div class="flex items-start justify-between gap-3 mb-3">
      <div>
        <h3 class="font-bold text-gray-900 text-sm">{{ titre }}</h3>
        <p v-if="sousTitre" class="text-xs text-gray-400 mt-0.5">{{ sousTitre }}</p>
      </div>
      <button @click="modeTableau = !modeTableau" class="text-xs text-blue-600 hover:text-blue-800 font-semibold whitespace-nowrap">
        {{ modeTableau ? 'Voir le graphique' : 'Voir en tableau' }}
      </button>
    </div>

    <div :class="chargement ? 'opacity-60' : ''" class="transition-opacity">
      <div v-if="modeTableau" class="max-h-56 overflow-y-auto">
        <table class="w-full text-xs text-left">
          <thead class="text-gray-400 uppercase"><tr><th class="py-1">Jour</th><th class="py-1 text-right">{{ unite }}</th></tr></thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="p in points" :key="p.cle"><td class="py-1.5 text-gray-600">{{ p.label }}</td><td class="py-1.5 text-right font-semibold text-gray-900">{{ p.valeur }}</td></tr>
          </tbody>
        </table>
      </div>

      <div v-else-if="vide" class="h-40 flex items-center justify-center text-sm text-gray-400">Aucune donnée sur la période.</div>

      <div v-else ref="conteneur" class="relative">
        <svg :viewBox="`0 0 ${W} ${H}`" :width="W" :height="H" class="block select-none" role="img" :aria-label="titre">
          <!-- grille : filets fins, pleins, discrets -->
          <g>
            <line v-for="g in graduations" :key="g" :x1="M.g" :x2="W - M.d" :y1="yDe(g)" :y2="yDe(g)" stroke="#e7e5e4" stroke-width="1" />
            <text v-for="g in graduations" :key="'t' + g" :x="M.g - 6" :y="yDe(g) + 4" text-anchor="end" font-size="11" fill="#78716c">{{ g }}</text>
          </g>
          <!-- barres -->
          <g v-for="(p, i) in points" :key="p.cle">
            <path :d="chemin(i, p.valeur)" :fill="actif === i ? '#5598e7' : '#2a78d6'" />
            <text v-if="(points.length - 1 - i) % cadence === 0" :x="xCentre(i)" :y="H - 8" text-anchor="middle" font-size="11" fill="#78716c">{{ p.label }}</text>
          </g>
          <!-- étiquette directe : le maximum seulement -->
          <text v-if="iMax >= 0" :x="xCentre(iMax)" :y="yDe(points[iMax].valeur) - 5" text-anchor="middle" font-size="12" font-weight="600" fill="#1c1917">{{ points[iMax].valeur }}</text>
          <!-- zones de survol : plus larges que les barres, une par jour -->
          <rect
            v-for="(p, i) in points"
            :key="'h' + p.cle"
            :x="M.g + pas * i"
            :y="M.h"
            :width="pas"
            :height="hauteurUtile"
            fill="transparent"
            tabindex="0"
            :aria-label="`${p.label} : ${p.valeur} ${unite}`"
            @pointerenter="actif = i"
            @pointermove="actif = i"
            @pointerleave="actif = -1"
            @focus="actif = i"
            @blur="actif = -1"
          />
        </svg>
        <div v-if="posInfobulle" :style="posInfobulle" class="pointer-events-none absolute -translate-x-1/2 -translate-y-full bg-gray-900 text-white rounded-lg px-3 py-2 text-xs shadow-lg whitespace-nowrap">
          <p class="font-bold text-sm leading-tight">{{ points[actif].valeur }} <span class="font-normal text-gray-300">{{ unite }}</span></p>
          <p class="text-gray-300">{{ points[actif].label }}</p>
        </div>
      </div>
    </div>
  </section>
</template>
