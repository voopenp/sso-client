<?php

namespace Voope\SsoClient;

final class Identidade
{
    public function __construct(
        public readonly string $sub,
        public readonly string $nome,
        public readonly string $email,
        public readonly string $papel,
        public readonly bool $doisFatores,
    ) {
    }

    public static function deResposta(array $dados): ?self
    {
        if (!is_string($dados['sub'] ?? null) || $dados['sub'] === '' || !is_string($dados['email'] ?? null)) {
            return null;
        }

        return new self(
            $dados['sub'],
            is_string($dados['nome'] ?? null) ? $dados['nome'] : $dados['email'],
            $dados['email'],
            is_string($dados['papel'] ?? null) ? $dados['papel'] : '',
            ($dados['dois_fatores'] ?? false) === true,
        );
    }
}
