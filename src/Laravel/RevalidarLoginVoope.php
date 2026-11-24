<?php

namespace Voope\SsoClient\Laravel;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Voope\SsoClient\LoginVoope;
use Voope\SsoClient\SsoException;

class RevalidarLoginVoope
{
    public function __construct(private LoginVoope $login)
    {
    }

    public static function para(string $guard, string $rotaDoLogin, string $colunaSub = 'sso_sub'): string
    {
        return self::class . ':' . implode(',', [$guard, $rotaDoLogin, $colunaSub]);
    }

    public function handle(Request $request, Closure $next, string $guard, string $rotaDoLogin, string $colunaSub = 'sso_sub')
    {
        if (!LoginVoope::ativo()) {
            return $next($request);
        }

        if (!$this->login->entrouPeloLoginVoope($request)) {
            return $this->encerrar($request, $guard, $rotaDoLogin);
        }

        if (!$this->login->precisaRevalidar($request)) {
            return $next($request);
        }

        $usuario = Auth::guard($guard)->user();
        $sub = $usuario?->{$colunaSub};

        try {
            $valido = $this->login->revalidar($request, is_string($sub) ? $sub : null);
        } catch (SsoException $e) {
            Log::warning('Login Voope indisponível na revalidação; a sessão segue', ['erro' => $e->getMessage()]);

            return $next($request);
        }

        if (!$valido) {
            Log::info('Sessão encerrada pelo login Voope: acesso revogado ou token vencido', [
                'guard' => $guard,
                'usuario_id' => $usuario?->getAuthIdentifier(),
            ]);

            return $this->encerrar($request, $guard, $rotaDoLogin);
        }

        return $next($request);
    }

    private function encerrar(Request $request, string $guard, string $rotaDoLogin)
    {
        Auth::guard($guard)->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route($rotaDoLogin);
    }
}
