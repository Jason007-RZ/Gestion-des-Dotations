<?php

namespace Tests\Unit;

use App\Support\CodesSecours;
use App\Support\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    // Secret de test de la RFC 6238 (annexe B) : « 12345678901234567890 » en ASCII.
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_base32_aller_retour(): void
    {
        $this->assertSame(self::SECRET, Totp::base32Encode('12345678901234567890'));
        $this->assertSame('12345678901234567890', Totp::base32Decode(self::SECRET));
    }

    public function test_vecteurs_officiels_rfc_6238(): void
    {
        foreach ([[59, '94287082'], [1111111109, '07081804'], [1111111111, '14050471'], [1234567890, '89005924'], [2000000000, '69279037'], [20000000000, '65353130']] as [$instant, $attendu]) {
            $this->assertSame($attendu, Totp::code(self::SECRET, intdiv($instant, 30), 8));
        }
    }

    public function test_verification_avec_tolerance_et_espaces(): void
    {
        $instant = 1111111109;
        $pas = intdiv($instant, 30);

        $code = Totp::code(self::SECRET, $pas);
        $this->assertSame($pas, Totp::verifier(self::SECRET, $code, null, 1, $instant));
        $this->assertSame($pas, Totp::verifier(self::SECRET, substr($code, 0, 3).' '.substr($code, 3), null, 1, $instant));
        $this->assertSame($pas - 1, Totp::verifier(self::SECRET, Totp::code(self::SECRET, $pas - 1), null, 1, $instant));
        $this->assertNull(Totp::verifier(self::SECRET, Totp::code(self::SECRET, $pas - 2), null, 1, $instant));
    }

    public function test_un_code_ne_sert_qu_une_fois(): void
    {
        $instant = 1111111109;
        $pas = intdiv($instant, 30);
        $code = Totp::code(self::SECRET, $pas);

        $this->assertSame($pas, Totp::verifier(self::SECRET, $code, null, 1, $instant));
        $this->assertNull(Totp::verifier(self::SECRET, $code, $pas, 1, $instant)); // rejeu refusé
    }

    public function test_formats_invalides_refuses(): void
    {
        $this->assertNull(Totp::verifier(self::SECRET, '12345', null, 1, 1111111109));
        $this->assertNull(Totp::verifier(self::SECRET, 'abcdef', null, 1, 1111111109));
        $this->assertNull(Totp::verifier(self::SECRET, '', null, 1, 1111111109));
    }

    public function test_uri_otpauth(): void
    {
        $this->assertSame(
            'otpauth://totp/Dotations%20Militaires:jean%40armee.mg?secret='.self::SECRET.'&issuer=Dotations%20Militaires&algorithm=SHA1&digits=6&period=30',
            Totp::uri(self::SECRET, 'jean@armee.mg', 'Dotations Militaires')
        );
    }

    public function test_codes_de_secours(): void
    {
        $codes = CodesSecours::generer(8);
        $this->assertSame(8, count(array_unique($codes)));
        $this->assertSame(1, preg_match('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $codes[0]));
        $this->assertSame(CodesSecours::empreinte($codes[0], 'cle'), CodesSecours::empreinte(strtolower(str_replace('-', ' ', $codes[0])), 'cle'));
    }
}
