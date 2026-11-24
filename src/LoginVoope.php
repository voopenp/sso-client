<?php

namespace Voope\SsoClient;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LoginVoope
{
    public const SESSAO_TOKEN = 'voope_sso.token';

    public const SESSAO_CONFERIDO_EM = 'voope_sso.conferido_em';

    private const SESSAO_STATE = 'voope_sso.state';

    private const SESSAO_VERIFIER = 'voope_sso.verifier';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:144.0) Gecko/20100101 Firefox/144.0';

    public static function ativo(): bool
    {
        return filled(config('voope-sso.url'))
            && filled(config('voope-sso.client_id'))
            && filled(config('voope-sso.client_secret'));
    }

    public function urlDeAutorizacao(Request $request): string
    {
        $state = Str::random(40);
        $verifier = Str::random(96);

        $request->session()->put([
            self::SESSAO_STATE => $state,
            self::SESSAO_VERIFIER => $verifier,
        ]);

        return $this->url('/oauth/authorize') . '?' . http_build_query([
            'client_id' => config('voope-sso.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => '',
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    public function concluir(Request $request): Identidade
    {
        if ($request->filled('error')) {
            throw new SsoException('O login Voope não liberou o acesso.');
        }

        $token = $this->trocarCodigo($request, (string) $request->query('code'), (string) $request->query('state'));
        $identidade = $this->identidade($token)
            ?? throw new SsoException('Seu usuário não tem acesso a este sistema.');

        if (!$identidade->doisFatores) {
            throw new SsoException('Ative a verificação em duas etapas no login Voope para entrar.');
        }

        $request->session()->put([
            self::SESSAO_TOKEN => Crypt::encryptString($token),
            self::SESSAO_CONFERIDO_EM => now()->getTimestamp(),
        ]);

        return $identidade;
    }

    public function entrouPeloLoginVoope(Request $request): bool
    {
        return is_string($request->session()->get(self::SESSAO_TOKEN));
    }

    public function precisaRevalidar(Request $request): bool
    {
        $conferidoEm = (int) $request->session()->get(self::SESSAO_CONFERIDO_EM, 0);

        return now()->getTimestamp() - $conferidoEm >= (int) config('voope-sso.revalidar_a_cada', 900);
    }

    public function revalidar(Request $request, ?string $subEsperado): bool
    {
        $cifrado = $request->session()->get(self::SESSAO_TOKEN);

        if (!is_string($cifrado) || $subEsperado === null || $subEsperado === '') {
            return false;
        }

        try {
            $identidade = $this->identidade(Crypt::decryptString($cifrado));
        } catch (DecryptException) {
            return false;
        }

        if ($identidade === null || !hash_equals($subEsperado, $identidade->sub)) {
            return false;
        }

        $request->session()->put(self::SESSAO_CONFERIDO_EM, now()->getTimestamp());

        return true;
    }

    public function trocarCodigo(Request $request, string $code, string $state): string
    {
        $esperado = $request->session()->pull(self::SESSAO_STATE);
        $verifier = $request->session()->pull(self::SESSAO_VERIFIER);

        if (!is_string($esperado) || !is_string($verifier) || $code === '' || !hash_equals($esperado, $state)) {
            throw new SsoException('A tentativa de login expirou. Tente de novo.');
        }

        try {
            $resposta = $this->http()->asForm()->post($this->url('/oauth/token'), [
                'grant_type' => 'authorization_code',
                'client_id' => config('voope-sso.client_id'),
                'client_secret' => config('voope-sso.client_secret'),
                'redirect_uri' => $this->redirectUri(),
                'code' => $code,
                'code_verifier' => $verifier,
            ]);
        } catch (ConnectionException) {
            throw SsoException::indisponivel();
        }

        $token = $resposta->json('access_token');

        if (!$resposta->successful() || !is_string($token) || $token === '') {
            throw new SsoException('O login Voope recusou a tentativa. Tente de novo.');
        }

        return $token;
    }

    public function identidade(string $accessToken): ?Identidade
    {
        try {
            $resposta = $this->http()->withToken($accessToken)->acceptJson()->get($this->url('/api/sso/eu'));
        } catch (ConnectionException) {
            throw SsoException::indisponivel();
        }

        if (in_array($resposta->status(), [401, 403], true)) {
            return null;
        }

        $dados = $resposta->json();
        $identidade = $resposta->successful() && is_array($dados) ? Identidade::deResposta($dados) : null;

        return $identidade ?? throw SsoException::indisponivel();
    }

    private function redirectUri(): string
    {
        $configurado = config('voope-sso.redirect');

        return is_string($configurado) && $configurado !== ''
            ? $configurado
            : route((string) config('voope-sso.rota_callback'));
    }

    private function url(string $caminho): string
    {
        return rtrim((string) config('voope-sso.url'), '/') . $caminho;
    }

    private function http(): PendingRequest
    {
        return Http::withUserAgent(self::USER_AGENT)->timeout((int) config('voope-sso.timeout', 10));
    }
}
