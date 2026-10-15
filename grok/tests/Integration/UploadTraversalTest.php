<?php
final class UploadTraversalTest extends IntegrationCase
{
    private function tmp(string $content): string { $f = tempnam(sys_get_temp_dir(), 'up'); file_put_contents($f, $content); return $f; }

    public function testPathTraversalOnMediaEndpoint(): void
    {
        $c = $this->user('trav');
        foreach (['../../app/config.php', '..%2F..%2Fapp%2Fconfig.php', '/etc/passwd', '0'] as $id) {
            $r = $c->get('/api/media.php?id=' . $id);
            $this->assertSame(404, $r['status'], $id);
            $this->assertStringNotContainsString('<?php', $r['body']);
        }
        // mesmo com stored_name adulterado no banco, basename() impede sair da pasta do usuário
        $mid = $c->upload('/api/media.php', $this->png(), 'a.png', 'image/png')['json']['id'];
        db()->prepare('UPDATE media SET stored_name = ? WHERE id = ?')->execute(['../../../app/config.php', $mid]);
        $r = $c->get('/api/media.php?id=' . $mid);
        $this->assertSame(404, $r['status']);
        $this->assertStringNotContainsString('<?php', $r['body']);
    }
    public function testMimeAndExtensionSpoofing(): void
    {
        $c = $this->user('spoof');
        $cases = [
            ['<?php system($_GET["c"]); ?>', 'shell.png', 'image/png', 415],
            ['<?php echo 1; ?>', 'audio.webm', 'audio/webm', 415],
            ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'x.svg', 'image/svg+xml', 415],
            ['<html><script>alert(1)</script></html>', 'x.jpg', 'image/jpeg', 415],
            ["GIF89a\x01\x00\x01\x00\x00\x00\x00;", 'x.gif', 'image/gif', 415],
            ["MZ\x90\x00" . str_repeat("\0", 200), 'x.mp4', 'video/mp4', 415],
        ];
        foreach ($cases as [$content, $name, $type, $expect]) {
            $r = $c->upload('/api/media.php', $this->tmp($content), $name, $type);
            $this->assertSame($expect, $r['status'], "$name: " . $r['body']);
        }
        // PNG real com nome .php: aceito, mas guardado com extensão .png e servido como image/png
        $r = $c->upload('/api/media.php', $this->png(), 'backdoor.php', 'application/x-php');
        $this->assertSame(200, $r['status'], $r['body']);
        $row = db()->query('SELECT stored_name, mime FROM media WHERE id = ' . (int)$r['json']['id'])->fetch();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $row['stored_name']);
        $this->assertSame('image/png', $row['mime']);
        $g = $c->get('/api/media.php?id=' . $r['json']['id']);
        $this->assertSame('image/png', $c->header($g, 'Content-Type'));
        $this->assertSame('nosniff', $c->header($g, 'X-Content-Type-Options'));
        $this->assertStringStartsWith('inline; filename="registro-', (string)$c->header($g, 'Content-Disposition'));
        $this->assertFileExists(TestEnv::$tmp . '/storage/media/' . $this->uid('spoof') . '/' . $row['stored_name']);
    }
    public function testSizeLimits(): void
    {
        $c = $this->user('size');
        $r = $c->upload('/api/media.php', $this->png(1024 * 1024 + 10), 'big.png', 'image/png'); // limite de imagem no teste: 1 MB
        $this->assertSame(413, $r['status'], $r['body']);
        $r = $c->upload('/api/media.php', $this->png(512 * 1024), 'ok.png', 'image/png');
        $this->assertSame(200, $r['status']);
        // sem arquivo
        $r = $c->request('POST', '/api/media.php', ['x' => '1'], ['X-CSRF-Token' => (string)$c->csrf]);
        $this->assertSame(422, $r['status']);
    }
    public function testRangeRequests(): void
    {
        $c = $this->user('range');
        $mid = $c->upload('/api/media.php', $this->png(100), 'a.png', 'image/png')['json']['id'];
        $r = $c->get('/api/media.php?id=' . $mid, ['Range' => 'bytes=0-9']);
        $this->assertSame(206, $r['status']);
        $this->assertSame(10, strlen($r['body']));
        $this->assertSame(416, $c->get('/api/media.php?id=' . $mid, ['Range' => 'bytes=99999-'])['status']);
    }
}
