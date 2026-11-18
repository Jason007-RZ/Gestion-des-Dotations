<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\DotationLigne;
use App\Models\Militaire;
use App\Models\MouvementStock;
use App\Models\TableauDotation;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retire de la base les données de test (ex. l'article « Bonnet F2 Test ») avant la mise en service.
 *
 * Prudence (RG3 : on ne détruit jamais l'historique) :
 *  - par défaut, la commande ne fait que LISTER (aucune suppression sans --appliquer) ;
 *  - ne cherche que le MOT entier (« Bonnet F2 Test » oui, « Testard » non) dans la désignation d'un article ou le nom/prénom d'un militaire ;
 *  - ne supprime que ce qui n'a AUCUN historique (bon de dotation, mouvement de stock) ni stock non nul ;
 *    le reste est signalé « à conserver ». Avec l'article partent ses lignes de stock à zéro et sa place dans le tableau de dotation ;
 *    avec le militaire, ses mensurations.
 */
class NettoyerDonneesTest extends Command
{
    protected $signature = 'dotations:nettoyer-tests {--motif=test : Mot à rechercher} {--appliquer : Supprime réellement (sans cette option, simple liste)}';

    protected $description = 'Liste (puis, avec --appliquer, supprime) les articles et militaires de test qui n\'ont aucun historique';

    public function handle(): int
    {
        $motif = trim((string) $this->option('motif'));
        if ($motif === '') {
            $this->error('Le motif ne peut pas être vide.');

            return self::FAILURE;
        }

        $regex = '/\b'.preg_quote($motif, '/').'\b/iu';
        $correspond = fn (?string ...$textes) => collect($textes)->contains(fn ($t) => $t !== null && preg_match($regex, $t) === 1);

        $articles = Article::where('designation', 'like', '%'.$motif.'%')->get()
            ->filter(fn (Article $a) => $correspond($a->designation));
        $militaires = Militaire::query()
            ->where(fn ($q) => $q->where('nom', 'like', '%'.$motif.'%')->orWhere('prenoms', 'like', '%'.$motif.'%'))->get()
            ->filter(fn (Militaire $m) => $correspond($m->nom, $m->prenoms));

        $aSupprimer = [];
        $lignes = [];

        foreach ($articles as $a) {
            $raison = DotationLigne::where('article_id', $a->id)->exists() ? 'figure sur des bons de dotation'
                : (MouvementStock::where('article_id', $a->id)->exists() ? 'a des mouvements de stock'
                : ($a->stocks()->where('quantite', '>', 0)->exists() ? 'a du stock' : null));
            $lignes[] = ['Article', "#{$a->id} {$a->designation}", $raison ? "À CONSERVER ({$raison})" : 'supprimable'];
            if (! $raison) {
                $aSupprimer[] = $a;
            }
        }

        foreach ($militaires as $m) {
            $raison = $m->dotations()->exists() ? 'a des bons de dotation' : null;
            $lignes[] = ['Militaire', "#{$m->id} {$m->matricule} {$m->nom} {$m->prenoms}", $raison ? "À CONSERVER ({$raison})" : 'supprimable'];
            if (! $raison) {
                $aSupprimer[] = $m;
            }
        }

        if ($lignes === []) {
            $this->info("Aucun article ni militaire ne contient le mot « {$motif} ».");

            return self::SUCCESS;
        }

        $this->table(['Type', 'Élément', 'Décision'], $lignes);

        if (! $this->option('appliquer')) {
            $this->warn('Simulation : rien n\'a été supprimé. Relancez avec --appliquer pour supprimer les éléments « supprimable ».');

            return self::SUCCESS;
        }

        if ($aSupprimer === []) {
            $this->info('Rien à supprimer : tout ce qui correspond a un historique.');

            return self::SUCCESS;
        }

        if ($this->input->isInteractive() && ! $this->confirm(count($aSupprimer).' élément(s) vont être supprimés définitivement. Continuer ?', false)) {
            $this->line('Annulé.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($aSupprimer) {
            foreach ($aSupprimer as $element) {
                $libelle = $element instanceof Article ? $element->designation : trim("{$element->matricule} {$element->nom} {$element->prenoms}");
                Audit::enregistrer('donnees_test_supprimees', 'Donnée de test supprimée : '.$libelle, $element);
                if ($element instanceof Article) {
                    $element->stocks()->delete();   // toutes à zéro (vérifié plus haut)
                    TableauDotation::where('article_id', $element->id)->delete();
                }
                $element->delete();
            }
        });

        $this->info(count($aSupprimer).' élément(s) supprimé(s).');

        return self::SUCCESS;
    }
}
