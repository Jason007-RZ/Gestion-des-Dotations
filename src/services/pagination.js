import { ref, computed, watch } from 'vue'

// Pagination côté client pour une liste déjà chargée.
export function usePagination(liste, taille = 15) {
  const page = ref(1)
  const pages = computed(() => Math.max(1, Math.ceil(liste.value.length / taille)))
  const elements = computed(() => liste.value.slice((page.value - 1) * taille, page.value * taille))
  // Revenir à la première page quand la liste change (recherche, filtre)
  watch(
    () => liste.value.length,
    () => {
      if (page.value > pages.value) page.value = pages.value
    }
  )
  watch(liste, () => {
    page.value = 1
  })
  return { page, pages, elements, total: computed(() => liste.value.length), taille }
}
