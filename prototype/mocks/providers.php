<?php
declare(strict_types=1);

return [
    ['slug' => 'google', 'name' => 'Google', 'basis' => 'OIDC', 'active' => true, 'order' => 1, 'client_id' => 'dummy-google-client.apps.contoh.test', 'secret_set' => true, 'trust_email' => true, 'extra' => '{}'],
    ['slug' => 'microsoft', 'name' => 'Microsoft', 'basis' => 'OIDC', 'active' => true, 'order' => 2, 'client_id' => 'dummy-ms-00000000', 'secret_set' => true, 'trust_email' => false, 'extra' => '{"tenant":"common"}'],
    ['slug' => 'apple', 'name' => 'Apple', 'basis' => 'OIDC', 'active' => true, 'order' => 3, 'client_id' => 'test.contoh.sso', 'secret_set' => true, 'trust_email' => true, 'extra' => '{"team_id":"TEAM000000","key_id":"KEY0000000"}'],
    ['slug' => 'github', 'name' => 'GitHub', 'basis' => 'OAuth2', 'active' => true, 'order' => 4, 'client_id' => 'dummy-gh-client', 'secret_set' => true, 'trust_email' => true, 'extra' => '{}'],
    ['slug' => 'gitlab', 'name' => 'GitLab', 'basis' => 'OIDC', 'active' => false, 'order' => 5, 'client_id' => '', 'secret_set' => false, 'trust_email' => true, 'extra' => '{}'],
    ['slug' => 'facebook', 'name' => 'Facebook', 'basis' => 'OAuth2', 'active' => false, 'order' => 6, 'client_id' => '', 'secret_set' => false, 'trust_email' => false, 'extra' => '{"graph_version":"v19.0"}'],
    ['slug' => 'linkedin', 'name' => 'LinkedIn', 'basis' => 'OIDC', 'active' => false, 'order' => 7, 'client_id' => '', 'secret_set' => false, 'trust_email' => false, 'extra' => '{}'],
    ['slug' => 'discord', 'name' => 'Discord', 'basis' => 'OAuth2', 'active' => false, 'order' => 8, 'client_id' => '', 'secret_set' => false, 'trust_email' => false, 'extra' => '{}'],
    ['slug' => 'x', 'name' => 'X', 'basis' => 'OAuth2 + PKCE', 'active' => true, 'order' => 9, 'client_id' => 'dummy-x-client', 'secret_set' => true, 'trust_email' => false, 'extra' => '{}'],
];
