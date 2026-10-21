<?php
declare(strict_types=1);

return [
    ['id' => 'portal-pegawai', 'name' => 'Portal Pegawai', 'type' => 'confidential', 'grants' => ['authorization_code', 'refresh_token'], 'scopes' => ['openid', 'profile', 'email', 'offline_access'], 'access_ttl' => 600, 'refresh_ttl' => 2592000, 'redirect_login' => ['https://portal.contoh.test/callback'], 'redirect_logout' => ['https://portal.contoh.test/'], 'backchannel' => 'https://portal.contoh.test/logout-bc', 'active' => true],
    ['id' => 'aplikasi-mobile', 'name' => 'Aplikasi Mobile', 'type' => 'public (PKCE)', 'grants' => ['authorization_code', 'refresh_token'], 'scopes' => ['openid', 'profile', 'email', 'offline_access'], 'access_ttl' => 600, 'refresh_ttl' => 1209600, 'redirect_login' => ['id.contoh.mobile:/callback'], 'redirect_logout' => [], 'backchannel' => '', 'active' => true],
    ['id' => 'layanan-laporan', 'name' => 'Layanan Laporan (server)', 'type' => 'confidential', 'grants' => ['client_credentials'], 'scopes' => ['laporan.baca'], 'access_ttl' => 900, 'refresh_ttl' => 0, 'redirect_login' => [], 'redirect_logout' => [], 'backchannel' => '', 'active' => true],
    ['id' => 'wiki-internal', 'name' => 'Wiki Internal', 'type' => 'confidential', 'grants' => ['authorization_code'], 'scopes' => ['openid', 'profile', 'email'], 'access_ttl' => 600, 'refresh_ttl' => 0, 'redirect_login' => ['https://wiki.contoh.test/oidc'], 'redirect_logout' => ['https://wiki.contoh.test/'], 'backchannel' => 'https://wiki.contoh.test/bc', 'active' => false],
];
