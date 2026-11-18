<?php

namespace Tests\Unit;

use App\Support\MotsDePasseFaibles;
use PHPUnit\Framework\TestCase;

class MotsDePasseFaiblesTest extends TestCase
{
    public function test_mots_de_passe_faibles_refuses(): void
    {
        foreach ([
            'aaaaaaaaaaaaaaaa', 'abcabcabcabcabcabc', 'abcdefghijklmnopqrst', '123456789012345678',
            'P@ssw0rdP@ssw0rd2026', 'Administrateur123456', 'ArmeeMadagascar2026!', 'dotationsmilitaires', 'azertyuiopqsdfghjklm',
        ] as $motDePasse) {
            $this->assertSame(true, MotsDePasseFaibles::raison($motDePasse) !== null, $motDePasse);
        }
    }

    public function test_identite_de_l_utilisateur_refusee(): void
    {
        $this->assertSame(true, MotsDePasseFaibles::raison('jean.rakoto-superpass-2026', ['jean.rakoto@armee.mg']) !== null);
        $this->assertSame(true, MotsDePasseFaibles::raison('MaPhraseSecreteDeRakotoNirina', ['Rakoto Nirina']) !== null);
    }

    public function test_phrases_de_passe_acceptees(): void
    {
        foreach (['correct horse battery staple', 'Le chat mange la souris à 18h!', 'vert-lumineux-fusée-sablier-47', 'Tr0ub4dor&3-chevalier-lune'] as $motDePasse) {
            $this->assertSame(null, MotsDePasseFaibles::raison($motDePasse), $motDePasse);
        }
    }
}
