<?php

namespace Voope\SsoClient\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\Request as RequisicaoHttp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Voope\SsoClient\Laravel\RevalidarLoginVoope;
use Voope\SsoClient\Laravel\SsoClientServiceProvider;
use Voope\SsoClient\LoginVoope;
use Voope\SsoClient\SsoException;

final class LoginVoopeTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SsoClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('voope-sso.url', 'https://sso.exemplo.test');
        $app['config']->set('voope-sso.client_id', 'cliente');
        $app['config']->set('voope-sso.client_secret', 'segredo');
        $app['config']->set('voope-sso.redirect', 'https://app.exemplo.test/sso/callback');
    }

    private function requisicao(array $query = []): Request
    {
        $request = Request::create('/sso/callback', 'GET', $query);
        $request->setLaravelSession($this->app['session.store']);

        return $request;
    }

    private function identidadeOk(array $extra = []): array
    {
        return array_merge([
            'sub' => 'sub-1',
            'nome' => 'Fulano',
            'email' => 'fulano@exemplo.test',
            'papel' => 'admin',
            'dois_fatores' => true,
        ], $extra);
    }

    private function iniciar(Request $request): string
    {
        $url = (new LoginVoope())->urlDeAutorizacao($request);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['state'];
    }

    public function test_ativo_depende_das_tres_chaves(): void
    {
        $this->assertTrue(LoginVoope::ativo());

        config()->set('voope-sso.client_secret', null);

        $this->assertFalse(LoginVoope::ativo());
    }

    public function test_url_de_autorizacao_usa_pkce_s256(): void
    {
        $request = $this->requisicao();
        $url = (new LoginVoope())->urlDeAutorizacao($request);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://sso.exemplo.test/oauth/authorize?', $url);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('https://app.exemplo.test/sso/callback', $query['redirect_uri']);

        $verifier = $request->session()->get('voope_sso.verifier');
        $esperado = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $this->assertSame($esperado, $query['code_challenge']);
    }

    public function test_concluir_troca_o_codigo_e_guarda_o_token(): void
    {
        Http::fake([
            'sso.exemplo.test/oauth/token' => Http::response(['access_token' => 'tok']),
            'sso.exemplo.test/api/sso/eu' => Http::response($this->identidadeOk()),
        ]);

        $request = $this->requisicao();
        $state = $this->iniciar($request);
        $request->query->replace(['code' => 'abc', 'state' => $state]);

        $identidade = (new LoginVoope())->concluir($request);

        $this->assertSame('sub-1', $identidade->sub);
        $this->assertSame('tok', Crypt::decryptString($request->session()->get(LoginVoope::SESSAO_TOKEN)));
        Http::assertSent(fn (RequisicaoHttp $r) => $r->url() === 'https://sso.exemplo.test/oauth/token'
            && $r['code_verifier'] !== null
            && str_contains($r->header('User-Agent')[0], 'Firefox'));
    }

    public function test_state_diferente_e_recusado(): void
    {
        Http::fake();
        $request = $this->requisicao();
        $this->iniciar($request);
        $request->query->replace(['code' => 'abc', 'state' => 'outro']);

        $this->expectException(SsoException::class);

        try {
            (new LoginVoope())->concluir($request);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_sem_dois_fatores_e_recusado(): void
    {
        Http::fake([
            'sso.exemplo.test/oauth/token' => Http::response(['access_token' => 'tok']),
            'sso.exemplo.test/api/sso/eu' => Http::response($this->identidadeOk(['dois_fatores' => false])),
        ]);
        $request = $this->requisicao();
        $request->query->replace(['code' => 'abc', 'state' => $this->iniciar($request)]);

        $this->expectExceptionMessage('verificação em duas etapas');

        (new LoginVoope())->concluir($request);
    }

    public function test_acesso_negado_vira_excecao_sem_token_na_sessao(): void
    {
        Http::fake([
            'sso.exemplo.test/oauth/token' => Http::response(['access_token' => 'tok']),
            'sso.exemplo.test/api/sso/eu' => Http::response(['erro' => 'sem acesso'], 403),
        ]);
        $request = $this->requisicao();
        $request->query->replace(['code' => 'abc', 'state' => $this->iniciar($request)]);

        try {
            (new LoginVoope())->concluir($request);
            $this->fail('Deveria recusar');
        } catch (SsoException $e) {
            $this->assertFalse($e->indisponivel);
        }

        $this->assertNull($request->session()->get(LoginVoope::SESSAO_TOKEN));
    }

    public function test_identidade_com_erro_do_servidor_e_indisponivel(): void
    {
        Http::fake(['sso.exemplo.test/api/sso/eu' => Http::response('', 500)]);

        try {
            (new LoginVoope())->identidade('tok');
            $this->fail('Deveria lançar');
        } catch (SsoException $e) {
            $this->assertTrue($e->indisponivel);
        }
    }

    private function rotaProtegida(): void
    {
        Route::get('/sso/start', fn () => 'start')->name('teste.sso.start');
        Route::middleware(['web', RevalidarLoginVoope::para('web', 'teste.sso.start')])
            ->get('/painel', fn () => 'painel');
    }

    private function logar(string $sub, int $conferidoEm): void
    {
        Auth::guard('web')->setUser(new GenericUser(['id' => 1, 'sso_sub' => $sub, 'remember_token' => null]));
        $this->withSession([
            LoginVoope::SESSAO_TOKEN => Crypt::encryptString('tok'),
            LoginVoope::SESSAO_CONFERIDO_EM => $conferidoEm,
        ]);
    }

    public function test_middleware_derruba_sessao_sem_login_voope(): void
    {
        $this->rotaProtegida();
        Auth::guard('web')->setUser(new GenericUser(['id' => 1, 'sso_sub' => 'sub-1', 'remember_token' => null]));

        $this->get('/painel')->assertRedirect(route('teste.sso.start'));
    }

    public function test_middleware_nao_consulta_dentro_do_intervalo(): void
    {
        Http::fake();
        $this->rotaProtegida();
        $this->logar('sub-1', now()->getTimestamp());

        $this->get('/painel')->assertOk();
        Http::assertNothingSent();
    }

    public function test_middleware_derruba_quando_acesso_e_revogado(): void
    {
        Http::fake(['sso.exemplo.test/api/sso/eu' => Http::response([], 403)]);
        $this->rotaProtegida();
        $this->logar('sub-1', 0);

        $this->get('/painel')->assertRedirect(route('teste.sso.start'));
    }

    public function test_middleware_derruba_quando_o_sub_nao_bate(): void
    {
        Http::fake(['sso.exemplo.test/api/sso/eu' => Http::response($this->identidadeOk(['sub' => 'outro']))]);
        $this->rotaProtegida();
        $this->logar('sub-1', 0);

        $this->get('/painel')->assertRedirect(route('teste.sso.start'));
    }

    public function test_middleware_mantem_sessao_quando_sso_esta_fora(): void
    {
        Http::fake(['sso.exemplo.test/api/sso/eu' => Http::response('', 502)]);
        $this->rotaProtegida();
        $this->logar('sub-1', 0);

        $this->get('/painel')->assertOk();
    }

    public function test_middleware_revalida_e_segue(): void
    {
        Http::fake(['sso.exemplo.test/api/sso/eu' => Http::response($this->identidadeOk())]);
        $this->rotaProtegida();
        $this->logar('sub-1', 0);

        $this->get('/painel')->assertOk()->assertSessionHas(LoginVoope::SESSAO_CONFERIDO_EM);
    }

    public function test_middleware_nao_faz_nada_com_sso_desligado(): void
    {
        config()->set('voope-sso.url', null);
        $this->rotaProtegida();

        $this->get('/painel')->assertOk();
    }
}
