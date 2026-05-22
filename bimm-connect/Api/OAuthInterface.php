<?php

declare(strict_types=1);

namespace BIMM\Connect\Api;

interface OAuthInterface
{
    /**
     * @param string $grantType
     * @param string $code
     * @param string $redirectUri
     * @param string $clientId
     * @param string $codeVerifier
     * @return mixed[]
     */
    public function token(
        string $grantType,
        string $code,
        string $redirectUri,
        string $clientId,
        string $codeVerifier
    ): array;

    /**
     * @param string $grantType
     * @param string $refreshToken
     * @param string $clientId
     * @return mixed[]
     */
    public function refresh(
        string $grantType,
        string $refreshToken,
        string $clientId
    ): array;

    /**
     * @param string $token
     * @return void
     */
    public function revoke(string $token): void;
}
