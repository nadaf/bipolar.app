<?php
/**
 * V4.4: gerador de QR Code (ISO/IEC 18004) em PHP puro, sem serviço externo, para o cadastro do app autenticador.
 * Escopo enxuto: modo byte, correção de erros nível M, versões 1 a 15 (até 412 bytes).
 * Escrito para este projeto (licença do projeto). O desenho segue a descrição pública da norma.
 */
const QR_EC_M = [
    // versão => [codewords de correção por bloco, [[blocos, dados por bloco], ...]]
    1 => [10, [[1, 16]]], 2 => [16, [[1, 28]]], 3 => [26, [[1, 44]]], 4 => [18, [[2, 32]]], 5 => [24, [[2, 43]]],
    6 => [16, [[4, 27]]], 7 => [18, [[4, 31]]], 8 => [22, [[2, 38], [2, 39]]], 9 => [22, [[3, 36], [2, 37]]],
    10 => [26, [[4, 43], [1, 44]]], 11 => [30, [[1, 50], [4, 51]]], 12 => [22, [[6, 36], [2, 37]]],
    13 => [22, [[8, 37], [1, 38]]], 14 => [24, [[4, 40], [5, 41]]], 15 => [24, [[5, 41], [5, 42]]],
];
const QR_ALIGN = [
    1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42],
    9 => [6, 26, 46], 10 => [6, 28, 50], 11 => [6, 30, 54], 12 => [6, 32, 58], 13 => [6, 34, 62], 14 => [6, 26, 46, 66], 15 => [6, 26, 48, 70],
];

/**
 * Tabelas exp (512) e log (256) de GF(256), polinômio 0x11D.
 * @return array{0:list<int>,1:list<int>}
 */
function qr_gf(): array
{
    static $t = null;
    if ($t !== null) return $t;
    $exp = array_fill(0, 512, 0);
    $log = array_fill(0, 256, 0);
    $x = 1;
    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $x;
        $log[$x] = $i;
        $x <<= 1;
        if ($x & 0x100) $x ^= 0x11D;
    }
    for ($i = 255; $i < 512; $i++) $exp[$i] = $exp[$i - 255];
    return $t = [$exp, $log];
}

/**
 * Codewords de correção Reed-Solomon.
 * @param list<int> $data
 * @return list<int>
 */
function qr_rs(array $data, int $ecLen): array
{
    [$exp, $log] = qr_gf();
    $gen = [1];
    for ($i = 0; $i < $ecLen; $i++) {
        $next = array_fill(0, count($gen) + 1, 0);
        foreach ($gen as $j => $g) {
            $next[$j] ^= $g;
            if ($g !== 0) $next[$j + 1] ^= $exp[$log[$g] + $i];
        }
        $gen = $next;
    }
    $rem = array_fill(0, $ecLen, 0);
    foreach ($data as $b) {
        $f = $b ^ $rem[0];
        array_shift($rem);
        $rem[] = 0;
        if ($f !== 0) for ($j = 0; $j < $ecLen; $j++) if ($gen[$j + 1] !== 0) $rem[$j] ^= $exp[$log[$gen[$j + 1]] + $log[$f]];
    }
    return $rem;
}

/**
 * Matriz do QR Code (true = módulo escuro).
 * @return list<list<bool>>
 */
function qr_matrix(string $text): array
{
    $len = strlen($text);
    $ver = 0;
    foreach (QR_EC_M as $v => [$ec, $groups]) {
        $cap = 0;
        foreach ($groups as [$n, $d]) $cap += $n * $d;
        $bits = 4 + ($v < 10 ? 8 : 16) + 8 * $len;
        if ($bits <= $cap * 8) { $ver = $v; break; }
    }
    if ($ver === 0) throw new InvalidArgumentException('Texto longo demais para o QR Code.');
    [$ecLen, $groups] = QR_EC_M[$ver];
    $capBytes = 0;
    foreach ($groups as [$n, $d]) $capBytes += $n * $d;

    // fluxo de bits: modo byte (0100), tamanho, dados, terminador, preenchimento
    $bits = '0100' . str_pad(decbin($len), $ver < 10 ? 8 : 16, '0', STR_PAD_LEFT);
    for ($i = 0; $i < $len; $i++) $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
    $bits .= str_repeat('0', min(4, $capBytes * 8 - strlen($bits)));
    if (strlen($bits) % 8) $bits .= str_repeat('0', 8 - strlen($bits) % 8);
    $data = array_map('bindec', str_split($bits, 8));
    for ($pad = 0; count($data) < $capBytes; $pad ^= 1) $data[] = $pad ? 0x11 : 0xEC;
    $data = array_map('intval', $data);

    // blocos + correção, intercalados
    $blocks = [];
    $off = 0;
    foreach ($groups as [$n, $d]) {
        for ($b = 0; $b < $n; $b++) {
            $chunk = array_slice($data, $off, $d);
            $off += $d;
            $blocks[] = [$chunk, qr_rs($chunk, $ecLen)];
        }
    }
    $final = [];
    $maxD = max(array_map(function (array $b): int { return count($b[0]); }, $blocks));
    for ($i = 0; $i < $maxD; $i++) foreach ($blocks as $b) if (isset($b[0][$i])) $final[] = $b[0][$i];
    for ($i = 0; $i < $ecLen; $i++) foreach ($blocks as $b) $final[] = $b[1][$i];

    $size = 17 + 4 * $ver;
    $m = array_fill(0, $size, array_fill(0, $size, false));
    $fn = array_fill(0, $size, array_fill(0, $size, false));
    $set = function (int $x, int $y, bool $dark) use (&$m, &$fn): void { $m[$y][$x] = $dark; $fn[$y][$x] = true; };

    for ($i = 0; $i < $size; $i++) { $set(6, $i, $i % 2 === 0); $set($i, 6, $i % 2 === 0); }
    foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
        for ($dy = -4; $dy <= 4; $dy++) for ($dx = -4; $dx <= 4; $dx++) {
            $x = $cx + $dx; $y = $cy + $dy;
            if ($x < 0 || $y < 0 || $x >= $size || $y >= $size) continue;
            $d = max(abs($dx), abs($dy));
            $set($x, $y, $d !== 2 && $d !== 4);
        }
    }
    $al = QR_ALIGN[$ver];
    $na = count($al);
    for ($i = 0; $i < $na; $i++) for ($j = 0; $j < $na; $j++) {
        if (($i === 0 && $j === 0) || ($i === 0 && $j === $na - 1) || ($i === $na - 1 && $j === 0)) continue;
        for ($dy = -2; $dy <= 2; $dy++) for ($dx = -2; $dx <= 2; $dx++) $set($al[$i] + $dx, $al[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
    }
    // reserva as áreas de formato (preenchidas depois) e o módulo escuro
    $drawFormat = function (int $mask) use ($set, $size): void {
        $data = $mask; // nível M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = function (int $i) use ($bits): bool { return (($bits >> $i) & 1) === 1; };
        for ($i = 0; $i <= 5; $i++) $set(8, $i, $bit($i));
        $set(8, 7, $bit(6)); $set(8, 8, $bit(7)); $set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) $set(14 - $i, 8, $bit($i));
        for ($i = 0; $i < 8; $i++) $set($size - 1 - $i, 8, $bit($i));
        for ($i = 8; $i < 15; $i++) $set(8, $size - 15 + $i, $bit($i));
        $set(8, $size - 8, true);
    };
    $drawFormat(0);
    if ($ver >= 7) {
        $rem = $ver;
        for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        $vb = ($ver << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $b = (($vb >> $i) & 1) === 1;
            $a = $size - 11 + $i % 3; $c = intdiv($i, 3);
            $set($a, $c, $b); $set($c, $a, $b);
        }
    }
    // dados em zigue-zague
    $total = count($final) * 8;
    $k = 0;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) $right = 5;
        for ($vert = 0; $vert < $size; $vert++) {
            for ($j = 0; $j < 2; $j++) {
                $x = $right - $j;
                $up = (($right + 1) & 2) === 0;
                $y = $up ? $size - 1 - $vert : $vert;
                if ($fn[$y][$x]) continue;
                $m[$y][$x] = $k < $total && ((($final[$k >> 3] >> (7 - ($k & 7))) & 1) === 1);
                $k++;
            }
        }
    }
    // escolhe a máscara de menor penalidade
    $best = null; $bestScore = PHP_INT_MAX; $base = $m;
    for ($mask = 0; $mask < 8; $mask++) {
        $m = $base;
        for ($y = 0; $y < $size; $y++) for ($x = 0; $x < $size; $x++) {
            if ($fn[$y][$x]) continue;
            switch ($mask) {
                case 0: $inv = ($x + $y) % 2 === 0; break;
                case 1: $inv = $y % 2 === 0; break;
                case 2: $inv = $x % 3 === 0; break;
                case 3: $inv = ($x + $y) % 3 === 0; break;
                case 4: $inv = (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0; break;
                case 5: $inv = ($x * $y) % 2 + ($x * $y) % 3 === 0; break;
                case 6: $inv = (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0; break;
                default: $inv = ((($x + $y) % 2) + ($x * $y) % 3) % 2 === 0;
            }
            if ($inv) $m[$y][$x] = !$m[$y][$x];
        }
        $drawFormat($mask);
        $score = qr_penalty($m);
        if ($score < $bestScore) { $bestScore = $score; $best = $m; }
    }
    return $best ?? $m;
}

/** @param list<list<bool>> $m */
function qr_penalty(array $m): int
{
    $n = count($m);
    $p = 0; $dark = 0;
    $lines = [];
    for ($i = 0; $i < $n; $i++) { $lines[] = $m[$i]; $lines[] = array_column($m, $i); }
    foreach ($lines as $line) {
        $run = 1;
        for ($i = 1; $i <= $n; $i++) {
            if ($i < $n && $line[$i] === $line[$i - 1]) { $run++; continue; }
            if ($run >= 5) $p += $run - 2;
            $run = 1;
        }
        $s = implode('', array_map('intval', $line));
        $p += 40 * (substr_count($s, '10111010000') + substr_count($s, '00001011101'));
    }
    for ($y = 0; $y < $n; $y++) for ($x = 0; $x < $n; $x++) {
        if ($m[$y][$x]) $dark++;
        if ($y < $n - 1 && $x < $n - 1 && $m[$y][$x] === $m[$y][$x + 1] && $m[$y][$x] === $m[$y + 1][$x] && $m[$y][$x] === $m[$y + 1][$x + 1]) $p += 3;
    }
    $p += 10 * intdiv(abs($dark * 20 - $n * $n * 10), $n * $n);
    return $p;
}

/** SVG do QR Code (com margem de 4 módulos), pronto para <img src="data:image/svg+xml;base64,...">. */
function qr_svg(string $text, int $px = 220): string
{
    $m = qr_matrix($text);
    $n = count($m) + 8;
    $d = '';
    foreach ($m as $y => $row) foreach ($row as $x => $dark) if ($dark) $d .= 'M' . ($x + 4) . ' ' . ($y + 4) . 'h1v1h-1z';
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $n . ' ' . $n . '" width="' . $px . '" height="' . $px . '" shape-rendering="crispEdges">'
        . '<rect width="' . $n . '" height="' . $n . '" fill="#fff"/><path fill="#000" d="' . $d . '"/></svg>';
}
