<?php
use PHPUnit\Framework\TestCase;

final class PasswordAuthTest extends TestCase
{
    public function testPasswordPolicy(): void
    {
        $this->assertNotNull(password_problem('curta'));
        $this->assertNotNull(password_problem('12345678'));
        $this->assertNotNull(password_problem('Senha123'), 'lista de senhas comuns ignora maiúsculas');
        $this->assertNotNull(password_problem('aaaaaaaaaa'), 'pouca variedade');
        $this->assertNotNull(password_problem('ana@example.com', 'Ana@Example.com'));
        $this->assertNotNull(password_problem(str_repeat('ab1', 70)));
        $this->assertNull(password_problem('cavalo correto bateria'));
        $this->assertNull(password_problem('Ç@fé-com-pão'), 'aceita acentos');
    }
    public function testHashAlgorithm(): void
    {
        $h = pw_hash('cavalo correto bateria');
        $this->assertTrue(password_verify('cavalo correto bateria', $h));
        $this->assertSame(defined('PASSWORD_ARGON2ID') ? 'argon2id' : '2y', password_get_info($h)['algoName'] === 'bcrypt' ? '2y' : password_get_info($h)['algoName']);
        $this->assertFalse(password_needs_rehash($h, pw_algo()));
    }
    public function testOauthConfiguredDetection(): void
    {
        $orig = $GLOBALS['CONFIG']['oauth'];
        $GLOBALS['CONFIG']['oauth'] = [
            'google' => ['client_id' => 'SEU_CLIENT_ID.apps.googleusercontent.com', 'client_secret' => 'SEU_CLIENT_SECRET'],
            'microsoft' => ['client_id' => 'abc', 'client_secret' => ''],
            'x' => ['client_id' => 'xid', 'client_secret' => ''],
        ];
        try {
            $this->assertFalse(oauth_configured('google'), 'placeholder');
            $this->assertFalse(oauth_configured('microsoft'), 'sem segredo');
            $this->assertTrue(oauth_configured('x'), 'X aceita cliente público');
            $this->assertSame(['x'], array_keys(oauth_enabled_providers()));
            $this->assertNull(oauth_auth_url('google'));
            unset($GLOBALS['CONFIG']['oauth']);
            $this->assertSame([], oauth_enabled_providers(), 'sem bloco oauth no config');
        } finally {
            $GLOBALS['CONFIG']['oauth'] = $orig;
        }
    }
    public function testTokensAreHashedSingleUseAndExpire(): void
    {
        $uid = oauth_login_or_register('google', ['subject' => 'tok-' . bin2hex(random_bytes(4)), 'name' => 'T', 'email' => null]);
        $raw = create_auth_token($uid, 'reset', 60);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $raw);
        $this->assertNull(find_auth_token($raw, 'verify'), 'finalidade diferente não vale');
        $this->assertNotNull(find_auth_token($raw, 'reset'));
        $this->assertSame($uid, consume_auth_token($raw, 'reset'));
        $this->assertNull(consume_auth_token($raw, 'reset'), 'uso único');
        $exp = create_auth_token($uid, 'reset', -1);
        $this->assertNull(find_auth_token($exp, 'reset'), 'expirado');
        $this->assertNull(find_auth_token("' OR 1=1 --", 'reset'));
    }
    public function testSignupWithoutVerificationLogsInDirectly(): void
    {
        $GLOBALS['CONFIG']['require_email_verification'] = false;
        try {
            $email = 'semverif-' . bin2hex(random_bytes(3)) . '@example.com';
            $r = signup_email('Sem Verificação', $email, 'cavalo correto bateria');
            $this->assertSame('created', $r['status']);
            $this->assertSame('ok', login_email($email, 'cavalo correto bateria')['status']);
            $this->assertSame('exists', signup_email('Outro', $email, 'outra senha boa 1')['status']);
        } finally {
            $GLOBALS['CONFIG']['require_email_verification'] = true;
        }
        $email2 = 'comverif-' . bin2hex(random_bytes(3)) . '@example.com';
        signup_email('Com', $email2, 'cavalo correto bateria');
        $this->assertSame('unverified', login_email($email2, 'cavalo correto bateria')['status']);
        $this->assertSame('invalid', login_email($email2, 'errada errada')['status']);
        $this->assertSame('invalid', login_email('nao-existe@example.com', 'cavalo correto bateria')['status']);
    }
    public function testRehashOnLoginWhenAlgorithmChanges(): void
    {
        $email = 'rehash-' . bin2hex(random_bytes(3)) . '@example.com';
        $GLOBALS['CONFIG']['require_email_verification'] = false;
        try {
            signup_email('R', $email, 'cavalo correto bateria');
            $uid = (int)find_email_user($email)['id'];
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash('cavalo correto bateria', PASSWORD_BCRYPT, ['cost' => 4]), $uid]);
            $this->assertSame('ok', login_email($email, 'cavalo correto bateria')['status']);
            $this->assertFalse(password_needs_rehash((string)find_email_user($email)['password_hash'], pw_algo()));
        } finally {
            $GLOBALS['CONFIG']['require_email_verification'] = true;
        }
    }
}
