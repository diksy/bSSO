-- Seed 9 provider, semua nonaktif (is_enabled = 0). Admin mengisi client_id dan secret lewat halaman admin.
-- URL harus diverifikasi ulang di dokumentasi resmi provider sebelum dipakai (requirements §4).
INSERT INTO identity_providers
  (slug, display_name, protocol, driver, client_id, discovery_url, authorize_url, token_url, userinfo_url, scopes, trust_email, is_enabled, sort_order)
VALUES
  ('google', 'Google', 'oidc', 'GoogleDriver', '', 'https://accounts.google.com/.well-known/openid-configuration', NULL, NULL, NULL, 'openid email profile', 1, 0, 1),
  ('microsoft', 'Microsoft', 'oidc', 'MicrosoftDriver', '', 'https://login.microsoftonline.com/common/v2.0/.well-known/openid-configuration', NULL, NULL, NULL, 'openid email profile', 0, 0, 2),
  ('apple', 'Apple', 'oidc', 'AppleDriver', '', 'https://appleid.apple.com/.well-known/openid-configuration', NULL, NULL, NULL, 'name email', 1, 0, 3),
  ('github', 'GitHub', 'oauth2', 'GitHubDriver', '', NULL, 'https://github.com/login/oauth/authorize', 'https://github.com/login/oauth/access_token', 'https://api.github.com/user', 'read:user user:email', 0, 0, 4),
  ('gitlab', 'GitLab', 'oidc', 'GitLabDriver', '', 'https://gitlab.com/.well-known/openid-configuration', NULL, NULL, NULL, 'openid email profile', 0, 0, 5),
  ('facebook', 'Facebook', 'oauth2', 'FacebookDriver', '', NULL, 'https://www.facebook.com/dialog/oauth', 'https://graph.facebook.com/oauth/access_token', 'https://graph.facebook.com/me?fields=id,name,email,picture', 'email public_profile', 0, 0, 6),
  ('linkedin', 'LinkedIn', 'oidc', 'LinkedInDriver', '', 'https://www.linkedin.com/oauth/.well-known/openid-configuration', NULL, NULL, NULL, 'openid email profile', 0, 0, 7),
  ('discord', 'Discord', 'oauth2', 'DiscordDriver', '', NULL, 'https://discord.com/oauth2/authorize', 'https://discord.com/api/oauth2/token', 'https://discord.com/api/users/@me', 'identify email', 0, 0, 8),
  ('x', 'X', 'oauth2', 'XDriver', '', NULL, 'https://x.com/i/oauth2/authorize', 'https://api.x.com/2/oauth2/token', 'https://api.x.com/2/users/me', 'users.read tweet.read', 0, 0, 9);
