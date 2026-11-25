# voope/sso-client

Login da equipe Voope nos nossos sistemas Laravel, usando a conta do voopesistema (OAuth2 com PKCE).
Quem pode entrar em cada sistema é definido na tela de Equipe do voopesistema.

Usado no admin do voopeclubes e no voope-starter-kit. Laravel 10 a 13, PHP 8.1+.

## Instalação

O pacote não está no Packagist, então entra pelo repositório:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/voopenp/sso-client.git" }
],
"require": {
    "voope/sso-client": "^0.1"
}
```

No `.env`:

```
VOOPE_SSO_URL=https://www.voope.com.br
VOOPE_SSO_CLIENT_ID=
VOOPE_SSO_CLIENT_SECRET=
VOOPE_SSO_REDIRECT=
VOOPE_SSO_ROTA_CALLBACK=
```

O id e o segredo saem de `php artisan sso:client "Nome do sistema" https://dominio/sso/callback`, rodado no voopesistema.
As duas últimas são opcionais: URL fixa do callback e nome da rota (padrão `sso.callback`).

Se as três primeiras estiverem vazias, `LoginVoope::ativo()` retorna `false` e o sistema usa só o login por senha.

## Uso

O pacote cuida do OAuth e da revalidação. As rotas, o usuário local e a tela de login ficam no sistema.

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

// rotas protegidas: guard, rota do login e coluna com o sub (padrão sso_sub)
Route::middleware(['auth:admin', RevalidarLoginVoope::para('admin', 'admin.sso.start')])->group(...);
```

- `concluir()` só retorna a identidade se o voopesistema liberou o acesso e a pessoa tem 2FA ou passkey.
- O middleware confere o acesso a cada 15 minutos e encerra a sessão que não veio do login Voope, que teve o acesso
  revogado ou que é de outro `sub`. Se o voopesistema estiver fora do ar, quem já entrou continua.
- Se o login redireciona direto para o SSO, mostre a tela com uma mensagem depois do logout. Senão a pessoa volta logada.

## Testes

```
composer test
```

## Versões

- **v0.1.0**: primeira versão, tirada do voopeerp e do voopeclubes.
