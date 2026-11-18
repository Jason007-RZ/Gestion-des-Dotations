<?php

namespace Database\Seeders;

use App\Exceptions\RegleMetier;
use App\Models\Article;
use App\Models\CategoriePersonnel;
use App\Models\Dotation;
use App\Models\DotationLigne;
use App\Models\Grade;
use App\Models\GrilleTaille;
use App\Models\Magasin;
use App\Models\Mensuration;
use App\Models\Militaire;
use App\Models\MouvementStock;
use App\Models\TableauDotation;
use App\Models\Unite;
use App\Models\User;
use App\Services\DotationService;
use App\Services\LignesBon;
use App\Services\StockService;
use App\Support\Personnel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Données FICTIVES de démonstration (développement uniquement). Noms, matricules et numéros sont inventés.
 *
 * Tout passe par les services de l'application (StockService, DotationService) : stock jamais négatif, échéances,
 * numérotation des bons et des réintégrations, règles du cahier, exactement comme en vrai.
 *
 * Contenu : 4 unités et leurs magasins, un compte par rôle, ~70 militaires mesurés (dont quelques-uns à mesurer),
 * livraisons de stock, dotations initiales, renouvellements, articles échus ou à échéance proche, pertes et
 * détériorations avec PV (une à remplacer), une réintégration suivie d'une mutation, des alertes de stock.
 *
 * Lancer : php artisan db:seed --class=DemoSeeder   (ou php artisan migrate:fresh --seed)
 * Relançable : s'arrête sans rien faire si la démonstration est déjà en place.
 */
class DemoSeeder extends Seeder
{
    private const PREFIXE = 'DEMO-';

    private int $graine = 20261104;

    private User $admin;

    /** @var array<string, User> magasinier par code d'unité */
    private array $magasiniers = [];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new \RuntimeException('Données de démonstration interdites en production.');
        }
        if (Militaire::where('matricule', 'like', self::PREFIXE.'%')->exists()) {
            $this->command?->info('Démonstration déjà en place : rien à faire. (Pour repartir de zéro : php artisan migrate:fresh --seed)');

            return;
        }

        $this->call(ReferentielsSeeder::class);
        $this->tableauxDesAutresCategories();
        [$unites, $magasins] = $this->unitesEtMagasins();
        $this->comptes($unites, $magasins);
        $militaires = $this->personnel($unites);
        $this->livraisons($magasins);
        $this->dotations($militaires, $magasins);
        $this->compteMilitaire($militaires);

        $this->command?->warn('Données FICTIVES de démonstration créées. Comptes (mot de passe « demo2026 ») :');
        $this->command?->table(['Rôle', 'Adresse'], [
            ['Administrateur', 'admin@demo.mg'],
            ['Magasinier (CIR)', 'magasinier.cir@demo.mg'],
            ['Magasinier (1RFI)', 'magasinier.1rfi@demo.mg'],
            ["Chef d'unité (CIR)", 'chef.cir@demo.mg'],
            ["Chef d'unité (1RFI)", 'chef.1rfi@demo.mg'],
            ['Militaire', 'militaire@demo.mg'],
        ]);
    }

    // ------------------------------------------------------------------ hasard reproductible

    private function hasard(): float
    {
        $this->graine = ($this->graine * 1103515245 + 12345) & 0x7FFFFFFF;

        return $this->graine / 0x7FFFFFFF;
    }

    private function pris(array $liste): mixed
    {
        return $liste[(int) floor($this->hasard() * count($liste)) % count($liste)];
    }

    private function entre(int $min, int $max): int
    {
        return $min + (int) floor($this->hasard() * ($max - $min + 1));
    }

    // ------------------------------------------------------------------ référentiels complémentaires

    /** Les autres catégories reçoivent le tableau de la recrue (exemple du cahier, à remplacer par l'officiel). */
    private function tableauxDesAutresCategories(): void
    {
        $recrue = CategoriePersonnel::where('libelle', 'Recrue')->first();
        foreach (CategoriePersonnel::where('id', '!=', $recrue->id)->get() as $cat) {
            foreach (TableauDotation::where('categorie_personnel_id', $recrue->id)->get() as $l) {
                TableauDotation::firstOrCreate(
                    ['categorie_personnel_id' => $cat->id, 'article_id' => $l->article_id],
                    ['quantite_reglementaire' => $l->quantite_reglementaire, 'periodicite_mois' => $l->periodicite_mois]
                );
            }
        }
    }

    private function unitesEtMagasins(): array
    {
        $unites = [];
        $magasins = [];
        foreach ([
            'CIR' => "Centre d'instruction des recrues",
            '1RFI' => "1er Régiment des Forces d'Intervention",
            'BLOG' => 'Bataillon Logistique',
            '2RG' => '2e Régiment du Génie',
        ] as $code => $libelle) {
            $unites[$code] = Unite::firstOrCreate(['code' => $code], ['libelle' => $libelle]);
            $magasins[$code] = Magasin::firstOrCreate(['libelle' => "Magasin d'habillement {$code}"], ['unite_id' => $unites[$code]->id]);
        }

        return [$unites, $magasins];
    }

    private function comptes(array $unites, array $magasins): void
    {
        $mdp = Hash::make('demo2026');
        $compte = fn (string $email, array $attributs) => User::firstOrCreate(['email' => $email], $attributs + ['password' => $mdp]);

        $this->admin = $compte('admin@demo.mg', ['name' => 'Administrateur (démo)', 'role' => User::ROLE_ADMIN]);
        foreach (['CIR', '1RFI'] as $code) {
            $compte('chef.'.strtolower($code).'@demo.mg', ['name' => "Chef d'unité {$code} (démo)", 'role' => User::ROLE_CHEF_UNITE, 'unite_id' => $unites[$code]->id]);
        }
        foreach (['CIR', '1RFI', 'BLOG'] as $code) {
            $m = $compte('magasinier.'.strtolower($code).'@demo.mg', ['name' => "Magasinier {$code} (démo)", 'role' => User::ROLE_MAGASINIER]);
            $m->magasins()->syncWithoutDetaching([$magasins[$code]->id]);
            $this->magasiniers[$code] = $m;
        }
        $this->magasiniers['2RG'] = $this->magasiniers['BLOG']; // le magasin du 2RG est servi par le magasinier du BLOG
        $this->magasiniers['2RG']->magasins()->syncWithoutDetaching([$magasins['2RG']->id]);
    }

    // ------------------------------------------------------------------ personnel

    private function personnel(array $unites): array
    {
        $noms = ['RAKOTOARISOA', 'RANDRIANARISOA', 'RASOLOFONIAINA', 'RAZAFINDRAKOTO', 'ANDRIAMAHEFA', 'RAHARISON', 'RAVELOJAONA',
            'RANDRIAMAMPIONONA', 'RABEMANANJARA', 'RATSIMBAZAFY', 'RAKOTONDRABE', 'ANDRIANJAFY', 'RAMAROSON', 'RAJAONARIVELO',
            'RAFANOMEZANA', 'RAZAKAMANANA', 'ANDRIANTSOA', 'RANAIVOSON', 'RAKOTOMALALA', 'RAVOAHANGY'];
        $prenoms = ['Hery', 'Tiana', 'Fanja', 'Mamy', 'Lova', 'Voahangy', 'Nirina', 'Haja', 'Toky', 'Fara', 'Andry', 'Miora',
            'Rado', 'Soa', 'Fetra', 'Njaka', 'Mialy', 'Tsiry', 'Holy', 'Aina'];
        $grade = fn (string $l) => Grade::where('libelle', $l)->value('id');
        $grilles = GrilleTaille::with('tailles')->get()->keyBy('libelle');
        $vet = $grilles['Vêtement']->tailles->values()->all();
        $poi = $grilles['Pointure']->tailles->values()->all();
        $tete = $grilles['Tour de tête']->tailles->values()->all();

        // [unité, nombre, grades possibles, ancienneté en jours (min, max)]
        $plan = [
            ['CIR', 30, ['Recrue'], [10, 260]],
            ['1RFI', 18, ['Soldat de 2e classe', 'Soldat de 1re classe', 'Caporal', 'Caporal-chef', 'Sergent', 'Sergent-chef', 'Lieutenant', 'Capitaine'], [200, 1400]],
            ['BLOG', 14, ['Soldat de 1re classe', 'Caporal', 'Sergent', 'Adjudant', 'Adjudant-chef', 'Sous-lieutenant', 'Commandant'], [200, 1600]],
            ['2RG', 8, ['Soldat de 2e classe', 'Caporal-chef', 'Sergent-chef', 'Capitaine', 'Lieutenant-colonel'], [300, 1800]],
        ];

        $militaires = [];
        $numero = 1;
        foreach ($plan as [$code, $nombre, $grades, [$min, $max]]) {
            for ($i = 0; $i < $nombre; $i++) {
                $prenom = $this->hasard() < 0.35 ? $this->pris($prenoms).' '.$this->pris($prenoms) : $this->pris($prenoms);
                $m = Militaire::create([
                    'matricule' => self::PREFIXE.sprintf('%04d', $numero++),
                    'nom' => $this->pris($noms),
                    'prenoms' => $prenom,
                    'grade_id' => $grade($this->pris($grades)),
                    'unite_id' => $unites[$code]->id,
                    'date_incorporation' => now()->subDays($this->entre($min, $max))->toDateString(),
                    'statut' => Militaire::STATUT_ACTIF,
                ]);
                // Quelques recrues arrivées récemment ne sont pas encore mesurées
                if (! ($code === 'CIR' && $i >= $nombre - 4)) {
                    $taille = fn (array $liste) => $liste[min(count($liste) - 1, max(0, (int) round((count($liste) - 1) / 2 + ($this->hasard() + $this->hasard() - 1) * (count($liste) / 2))))];
                    Mensuration::create([
                        'militaire_id' => $m->id,
                        'date_mesure' => Carbon::parse($m->date_incorporation)->addDay()->min(now())->toDateString(),
                        'taille_vetement_id' => $taille($vet)->id, 'pointure_id' => $taille($poi)->id,
                        'tour_tete_id' => $this->hasard() < 0.9 ? $taille($tete)->id : null,
                        'user_id' => ($this->magasiniers[$code] ?? $this->admin)->id,
                    ]);
                }
                $militaires[$code][] = $m;
            }
        }

        return $militaires;
    }

    // ------------------------------------------------------------------ stock

    /** Livraison initiale il y a cinq ans (avant toute dotation), puis une livraison récente ; tailles extrêmes en petite quantité. */
    private function livraisons(array $magasins): void
    {
        $articles = Article::with('grilleTaille.tailles')->where('actif', true)->get();
        foreach ($magasins as $code => $magasin) {
            $lignes = [];
            foreach ($articles as $a) {
                foreach ($a->grilleTaille?->tailles ?? [null] as $t) {
                    $extreme = $t && ($t->ordre === 1 || $t->ordre >= ($a->grilleTaille->tailles->count()));
                    $base = $code === 'CIR' ? 160 : 260;
                    $lignes[] = ['article_id' => $a->id, 'taille_id' => $t?->id, 'quantite' => $extreme ? $this->entre(8, 20) : $this->entre($base - 30, $base + 30)];
                }
            }
            StockService::bon(MouvementStock::ENTREE, $this->admin, $magasin, "BL-{$code}-INITIAL", now()->subYears(5)->toDateString(), LignesBon::valider($lignes));

            // Seuils d'alerte : 10 en général, 3 pour les tailles extrêmes
            $seuils = [];
            foreach ($articles as $a) {
                foreach ($a->grilleTaille?->tailles ?? [null] as $t) {
                    $extreme = $t && ($t->ordre === 1 || $t->ordre >= ($a->grilleTaille->tailles->count()));
                    $seuils[] = ['article_id' => $a->id, 'taille_id' => $t?->id, 'seuil_alerte' => $extreme ? 3 : 10];
                }
            }
            StockService::seuils($this->admin, $magasin, LignesBon::valider($seuils, 'seuil_alerte', 0));
        }

        // Livraison récente au CIR (journal et graphique non vides)
        $cir = $magasins['CIR'];
        $tre = Article::where('code', 'HAB-TRE')->with('grilleTaille.tailles')->first();
        StockService::bon(MouvementStock::ENTREE, $this->magasiniers['CIR'], $cir, 'BL-2026-118', now()->subDays(12)->toDateString(),
            LignesBon::valider($tre->grilleTaille->tailles->map(fn ($t) => ['article_id' => $tre->id, 'taille_id' => $t->id, 'quantite' => 40])->all()));
        $cei = Article::where('code', 'PEQ-CEI')->first();
        StockService::bon(MouvementStock::REFORME, $this->magasiniers['CIR'], $cir, 'PVR-2026-007', now()->subDays(5)->toDateString(),
            LignesBon::valider([['article_id' => $cei->id, 'taille_id' => null, 'quantite' => 4]]), 'Boucles cassées constatées à l\'inventaire');
    }

    // ------------------------------------------------------------------ dotations, renouvellements, incidents

    private function dotations(array $militaires, array $magasins): void
    {
        foreach ($militaires as $code => $liste) {
            $magasin = $magasins[$code];
            $magasinier = $this->magasiniers[$code];
            foreach ($liste as $i => $m) {
                if (! $m->mensurations()->exists()) {
                    continue; // pas encore mesuré : pas encore doté
                }
                if ($code === 'CIR' && $i % 9 === 8) {
                    continue; // quelques recrues mesurées attendent leur dotation initiale
                }
                $date = Carbon::parse($m->date_incorporation)->addDays(2)->min(now())->toDateString();
                $this->bonInitial($magasinier, $m, $magasin, $date);

                // Personnel ancien : les articles échus ont été renouvelés, sauf pour quelques-uns (en retard)
                if ($code !== 'CIR' && $i % 4 !== 0) {
                    $this->renouvelerEchus($magasinier, $m, $magasin);
                }
            }
        }

        // Une recrue avec des échéances dans les prochains jours (tableau de bord « sous 30 jours »)
        $cir = $militaires['CIR'];
        $proche = collect($cir)->first(fn ($m) => Dotation::where('militaire_id', $m->id)->exists());
        DotationLigne::whereHas('dotation', fn ($d) => $d->where('militaire_id', $proche->id))
            ->whereHas('article', fn ($a) => $a->whereIn('code', ['HAB-TSK', 'HAB-CHT']))
            ->update(['date_echeance' => now()->addDays(12)->toDateString()]);

        $this->incidents($militaires, $magasins);
        $this->mutation($militaires['1RFI'], $magasins['1RFI']);
    }

    private function bonInitial(User $magasinier, Militaire $m, Magasin $magasin, string $date): void
    {
        $proposition = DotationService::proposition($m, $magasin, Dotation::INITIALE);
        $lignes = collect($proposition['lignes'])
            ->filter(fn ($l) => ! $l['probleme']) // comme à l'écran : une ligne en rupture est décochée, le reste est remis
            ->map(fn ($l) => ['article_id' => $l['article']['id'], 'taille_id' => $l['taille']['id'] ?? null, 'quantite' => $l['quantite']])
            ->values()->all();
        try {
            DotationService::creer($magasinier, $m, $magasin, Dotation::INITIALE, $date, null, LignesBon::valider($lignes));
        } catch (RegleMetier $e) {
            $this->command?->line("  (bon initial de {$m->matricule} ignoré : {$e->getMessage()})");
        }
    }

    /** Renouvelle, période après période, tout ce qui est échu (date du renouvellement = date d'échéance). */
    private function renouvelerEchus(User $magasinier, Militaire $m, Magasin $magasin): void
    {
        for ($tour = 0; $tour < 40; $tour++) {
            $echues = DotationService::enService($m)->filter(fn ($l) => $l->date_echeance && $l->date_echeance->toDateString() <= now()->subDays(20)->toDateString())
                ->groupBy(fn ($l) => $l->date_echeance->toDateString());
            if ($echues->isEmpty()) {
                return;
            }
            foreach ($echues as $date => $lignes) {
                $donnees = $lignes->map(fn ($l) => ['article_id' => $l->article_id, 'taille_id' => $l->taille_id, 'quantite' => $l->quantite, 'remplace_ligne_id' => $l->id])->values()->all();
                try {
                    DotationService::creer($magasinier, $m, $magasin, Dotation::RENOUVELLEMENT, $date, null, LignesBon::valider($donnees));
                } catch (RegleMetier $e) {
                    $this->command?->line("  (renouvellement de {$m->matricule} arrêté : {$e->getMessage()})");

                    return;
                }
            }
        }
    }

    private function incidents(array $militaires, array $magasins): void
    {
        $avecBon = fn (array $liste) => collect($liste)->filter(fn ($m) => Dotation::where('militaire_id', $m->id)->exists())->values();
        $ligne = fn (Militaire $m, string $code) => DotationLigne::where('etat', DotationLigne::ETAT_EN_SERVICE)
            ->whereHas('dotation', fn ($d) => $d->where('militaire_id', $m->id))
            ->whereHas('article', fn ($a) => $a->where('code', $code))->first();

        $cir = $avecBon($militaires['CIR']);
        $rfi = $avecBon($militaires['1RFI']);

        // 1. Perte d'un tee-shirt (sur 3), imputable, remplacement encore à faire
        if ($l = $ligne($cir[3], 'HAB-TSK')) {
            DotationService::declarerIncident($this->magasiniers['CIR'], $l, ['type' => 'perte', 'pv_numero' => 'PV-2026-001', 'imputable' => true, 'quantite' => 1,
                'date' => now()->subDays(6)->toDateString(), 'observation' => "Perdu lors d'un exercice de nuit."]);
        }
        // 2. Rangers détériorés, non imputables, déjà remplacés
        if ($l = $ligne($rfi[2], 'CHA-RAN')) {
            $i = DotationService::declarerIncident($this->magasiniers['1RFI'], $l, ['type' => 'deterioration', 'pv_numero' => 'PV-2026-002', 'imputable' => false,
                'date' => now()->subDays(30)->toDateString(), 'observation' => 'Semelle décollée en opération.']);
            DotationService::remplacer($this->magasiniers['1RFI'], $i, $magasins['1RFI'], null, now()->subDays(29)->toDateString(), null);
        }
        // 3. Béret perdu, imputable, à remplacer
        if ($l = $ligne($rfi[5], 'COI-BER')) {
            DotationService::declarerIncident($this->magasiniers['1RFI'], $l, ['type' => 'perte', 'pv_numero' => 'PV-2026-003', 'imputable' => true,
                'date' => now()->subDays(3)->toDateString(), 'observation' => 'Oublié lors d\'un déplacement.']);
        }
    }

    /** Un sergent du 1RFI rend ses effets à réintégrer, puis il est muté (dossier clôturé). */
    private function mutation(array $rfi, Magasin $magasin): void
    {
        $m = collect($rfi)->first(fn ($x) => Personnel::aRendre($x)->isNotEmpty() && $x->id !== $rfi[2]->id && $x->id !== $rfi[5]->id);
        if (! $m) {
            return;
        }
        DotationService::reintegrer($this->magasiniers['1RFI'], $m, $magasin, now()->subDays(8)->toDateString(), Personnel::aRendre($m)->pluck('id')->all());
        $m->update(['statut' => Militaire::STATUT_MUTE]);

        // Une radiation ancienne au BLOG (dossier soldé)
        $r = Militaire::whereHas('unite', fn ($u) => $u->where('code', 'BLOG'))->whereDoesntHave('dotations')->first()
            ?? Militaire::whereHas('unite', fn ($u) => $u->where('code', '2RG'))->whereDoesntHave('dotations')->first();
        $r?->update(['statut' => Militaire::STATUT_RADIE]);
    }

    /** Le compte « militaire » est relié à une recrue dotée (pour « Mon paquetage »). */
    private function compteMilitaire(array $militaires): void
    {
        $m = collect($militaires['CIR'])->first(fn ($x) => Dotation::where('militaire_id', $x->id)->exists());
        User::firstOrCreate(['email' => 'militaire@demo.mg'], [
            'name' => "{$m->prenoms} {$m->nom}", 'password' => Hash::make('demo2026'),
            'role' => User::ROLE_MILITAIRE, 'militaire_id' => $m->id,
        ]);
    }
}
