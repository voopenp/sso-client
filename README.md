# SSO Client — login Voope nos sistemas da Voope

A equipe entra nos painéis internos (admin do voopeclubes, plataforma do voopeerp, ...) com a conta do
**voopesistema**, por OAuth2 com PKCE. Quem pode entrar em cada sistema é decidido lá, na tela de Equipe.

> **Leia este arquivo inteiro antes de mexer.** É a memória do pacote para qualquer sessão nova (pessoa ou IA).

## 0. Onde este código vive

- **Repositório oficial:** `voopenp/sso-client` (privado). Pacote Composer `voope/sso-client`.
- **Quem usa:** `voopeclubes` (admin). Próximo: `voopeerp` (plataforma), que hoje tem a mesma lógica copiada.
- **Provedor:** voopesistema (Passport). Rotas `GET /oauth/authorize`, `POST /oauth/token` e `GET /api/sso/eu`.
- **Laravel 10 a 13, PHP 8.1+.** Versão por tag `vX.Y.Z` (SemVer).

### O que fica no pacote e o que fica no sistema

| Pacote | Sistema |
|---|---|
| URL de autorização com PKCE, troca do código, `/api/sso/eu` | Rotas `sso/start` e `sso/callback` |
| Regras de recusa (erro, state, sem acesso, sem 2FA) | Achar ou criar o usuário local pelo `sub` (ou ligar pelo e-mail) |
| Token cifrado na sessão e revalidação a cada 15 min | Papel/permissão do usuário local, tela de login, logout, auditoria |

## 1. Instalar

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/voopenp/sso-client.git" }
],
"require": {
    "voope/sso-client": "^0.1"
}
```

`.env` (o `CLIENT_ID`/`SECRET` saem de `php artisan sso:client "Nome" <callback>` no voopesistema):

```
VOOPE_SSO_URL=https://www.voope.com.br
VOOPE_SSO_CLIENT_ID=
VOOPE_SSO_CLIENT_SECRET=
VOOPE_SSO_REDIRECT=            # opcional: URL fixa do callback
VOOPE_SSO_ROTA_CALLBACK=       # opcional: nome da rota do callback (padrão sso.callback)
```

Com as três primeiras preenchidas, `LoginVoope::ativo()` é `true`. A convenção é: ativo fecha o login por senha;
vazio volta ao login por senha (dev e emergência).

## 2. Usar

```php
use Voope\SsoClient\LoginVoope;
use Voope\SsoClient\SsoException;
use Voope\SsoClient\Laravel\RevalidarLoginVoope;

// sso/start
return redirect()->away($login->urlDeAutorizacao($request));

// sso/callback
try {
    $identidade = $login->concluir($request); // sub, nome, email, papel, doisFatores
    $usuario = /* acha pelo sub, liga pelo e-mail ou cria */;
} catch (SsoException $e) {
    return redirect()->route('login')->with('sso_message', $e->getMessage());
}
Auth::guard('admin')->login($usuario);
$request->session()->regenerate();

// rotas protegidas: guard, rota que reinicia o login e coluna com o sub (padrão sso_sub)
Route::middleware(['auth:admin', RevalidarLoginVoope::para('admin', 'admin.sso.start')])->group(...);
```

- `concluir()` só devolve a identidade quando o voopesistema liberou o acesso **e** a pessoa tem 2FA ou passkey.
- O middleware derruba a sessão que não veio do login Voope, a que teve o acesso revogado e a de outro `sub`.
  Se o voopesistema estiver fora (`SsoException::$indisponivel`), quem já está dentro continua.
- Depois do logout, mostre a tela de login com uma mensagem; sem ela, o redirect ao SSO devolve a pessoa logada.

## 3. Testes

`composer test` (Testbench). Versão nova: testes passando, histórico abaixo atualizado e tag.

## 4. Histórico

- **v0.1.0** — extraído do voopeerp/voopeclubes: PKCE, identidade, exigência de 2FA, revalidação por middleware.
