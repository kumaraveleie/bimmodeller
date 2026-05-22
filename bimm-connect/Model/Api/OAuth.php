<?php

declare(strict_types=1);

namespace BIMM\Connect\Model\Api;

use BIMM\Connect\Api\OAuthInterface;

class OAuth implements OAuthInterface
{
    public function token(
        string $grantType,
        string $code,
        string $redirectUri,
        string $clientId,
        string $codeVerifier
    ): array {
        // TODO Week 8: implement with AuthorizationService::exchangeCodeForToken
        return [];
    }

    public function refresh(
        string $grantType,
        string $refreshToken,
        string $clientId
    ): array {
        // TODO Week 8: implement with TokenService::refresh
        return [];
    }

    public function revoke(string $token): void
    {
        // TODO Week 8: implement with TokenService::revoke
    }
}
