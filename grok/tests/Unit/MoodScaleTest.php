<?php
use PHPUnit\Framework\TestCase;

final class MoodScaleTest extends TestCase
{
    public function testSevenSymmetricLevels(): void
    {
        $this->assertSame([-3, -2, -1, 0, 1, 2, 3], array_keys(MOOD_LEVELS));
        $this->assertSame('Eutimia / estável', mood_label(0));
        $this->assertSame('Depressão grave', mood_label(-3));
        $this->assertSame('Mania / euforia', mood_label(3));
        $this->assertSame('?', mood_label(4));
    }
    public function testEachLevelHasTextAndNonColorIcon(): void
    {
        $icons = [];
        foreach (MOOD_LEVELS as $v => [$label, $desc, $icon]) {
            $this->assertNotSame('', $label);
            $this->assertNotSame('', $desc);
            $icons[] = $icon;
            $this->assertArrayHasKey($v, MOOD_COLORS);
        }
        $this->assertCount(7, array_unique($icons), 'ícones devem distinguir os níveis sem depender de cor');
    }
    public function testOnlyThreePeriodsNoMadrugada(): void
    {
        $this->assertSame(['manha' => 'Manhã', 'tarde' => 'Tarde', 'noite' => 'Noite'], PERIODS);
        $schema = (string)file_get_contents(TestEnv::ROOT . '/schema.sql');
        $this->assertStringContainsString("period ENUM('manha','tarde','noite')", $schema);
        foreach (['schema.sql', 'public_html/assets/js/app.js', 'app/lib/report.php', 'public_html/api/entries.php'] as $f) {
            $this->assertStringNotContainsStringIgnoringCase("'madrugada'", (string)file_get_contents(TestEnv::ROOT . '/' . $f), $f);
        }
    }
    /** Contraste WCAG AA (4,5:1) do texto dos chips de humor, nos temas claro e escuro. */
    public function testChipColorContrast(): void
    {
        $css = (string)file_get_contents(TestEnv::ROOT . '/public_html/assets/css/app.css');
        preg_match_all('/--(l|i)(\d): (#[0-9a-f]{3,6})/i', $css, $m, PREG_SET_ORDER);
        $c = [];
        foreach ($m as $x) $c[$x[1] . $x[2]] = $x[3];
        for ($i = 0; $i <= 6; $i++) {
            $ratio = self::contrast($c["l$i"], $c["i$i"]);
            $this->assertGreaterThanOrEqual(4.5, $ratio, "nível $i: contraste " . round($ratio, 2));
        }
        // e-mail/relatório: MOOD_COLORS com texto branco para -3, -2, +3 e escuro para os demais
        foreach (MOOD_COLORS as $v => $bg) {
            $fg = in_array($v, [-3, -2, 3], true) ? '#ffffff' : '#1d2b36';
            $this->assertGreaterThanOrEqual(4.5, self::contrast($bg, $fg), "e-mail nível $v");
        }
    }
    public static function contrast(string $a, string $b): float
    {
        $lum = function (string $hex): float {
            $hex = ltrim($hex, '#');
            if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            $ch = array_map(function ($x) { $x = hexdec($x) / 255; return $x <= 0.03928 ? $x / 12.92 : (($x + 0.055) / 1.055) ** 2.4; }, str_split($hex, 2));
            return 0.2126 * $ch[0] + 0.7152 * $ch[1] + 0.0722 * $ch[2];
        };
        $l1 = $lum($a); $l2 = $lum($b);
        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }
}
