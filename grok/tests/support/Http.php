<?php
/** Cliente HTTP mínimo com cookie jar próprio (um por "navegador"). */
final class Http
{
    private string $jar;
    public ?string $csrf = null;

    public function __construct(public string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'bpjar');
    }

    /** @param array<string,string> $headers @return array{status:int,headers:array<string,list<string>>,body:string,json:mixed} */
    public function request(string $method, string $path, $body = null, array $headers = []): array
    {
        $ch = curl_init($this->base . $path);
        $h = [];
        foreach ($headers as $k => $v) $h[] = "$k: $v";
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
                $p = explode(':', $line, 2);
                if (count($p) === 2) $respHeaders[strtolower(trim($p[0]))][] = trim($p[1]);
                return strlen($line);
            },
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $resp = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => $respHeaders, 'body' => $resp, 'json' => json_decode($resp, true)];
    }
    /** V4.3.1: handle cURL pronto (JSON + CSRF + cookies) para requisições paralelas com curl_multi. @param mixed $data */
    public function curlHandle(string $method, string $path, $data): CurlHandle
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . (string)$this->csrf], CURLOPT_POSTFIELDS => json_encode($data)]);
        return $ch;
    }
    public function get(string $p, array $h = []): array { return $this->request('GET', $p, null, $h); }
    /** Envia JSON com o token CSRF (se houver), a menos que $csrf === false. */
    public function json(string $method, string $p, $data, $csrf = true): array
    {
        $h = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($csrf === true && $this->csrf) $h['X-CSRF-Token'] = $this->csrf;
        if (is_string($csrf)) $h['X-CSRF-Token'] = $csrf;
        return $this->request($method, $p, $data === null ? null : json_encode($data), $h);
    }
    public function upload(string $p, string $file, string $name, string $type, array $extra = [], bool $csrf = true): array
    {
        $fields = ['file' => new CURLFile($file, $type, $name)] + $extra;
        return $this->request('POST', $p, $fields, $csrf && $this->csrf ? ['X-CSRF-Token' => $this->csrf] : []);
    }
    /** Login de desenvolvimento como usuário $u e captura do token CSRF. */
    public function login(string $u): self
    {
        $r = $this->get('/auth/dev.php?u=' . $u);
        if ($r['status'] !== 302) throw new RuntimeException('login falhou: ' . $r['status']);
        $this->refreshCsrf();
        return $this;
    }
    public function refreshCsrf(): void
    {
        $page = $this->get('/')['body'];
        if (!preg_match('#<script id="boot" type="application/json">(.*?)</script>#s', $page, $m)) throw new RuntimeException('boot JSON ausente');
        $this->csrf = json_decode($m[1], true)['csrf'];
    }
    /** Busca o token CSRF do formulário em $page e envia POST urlencoded para $action. */
    public function form(string $page, string $action, array $fields, bool $withCsrf = true): array
    {
        if ($withCsrf) {
            $html = $this->get($page)['body'];
            if (!preg_match('/name="csrf" value="([a-f0-9]{64})"/', $html, $m)) throw new RuntimeException("csrf ausente em $page");
            $fields['csrf'] = $m[1];
        }
        return $this->request('POST', $action, http_build_query($fields), ['Content-Type' => 'application/x-www-form-urlencoded']);
    }
    /** Segue um redirecionamento (uma vez) e devolve a página de destino. */
    public function follow(array $r): array
    {
        $loc = $this->header($r, 'Location');
        if ($loc === null) return $r;
        return $this->get($loc);
    }
    public function header(array $r, string $name): ?string { return $r['headers'][strtolower($name)][0] ?? null; }
}
