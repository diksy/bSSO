<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\OAuth\AuthCodeService;
use App\OAuth\AuthorizeRequest;
use App\OAuth\InvalidRedirectException;
use App\OAuth\OAuthException;
use App\Repository\ClientRedirectUriRepository;
use App\Repository\ClientRepository;
use App\Repository\Record\SsoSession;
use App\Repository\UserRepository;
use App\Session\SessionTerminator;
use App\Session\SsoSessionManager;

/**
 * GET /authorize. Tanpa halaman consent (semua klien first-party). Tanpa sesi valid -> /login?return=<authorize asli>;
 * prompt=none -> error login_required. Sesi pra-MFA (user MFA aktif tapi mfa_passed_at kosong) TIDAK dianggap login.
 */
final class AuthorizeController
{
    public function __construct(
        private readonly ClientRepository $clients,
        private readonly ClientRedirectUriRepository $uris,
        private readonly UserRepository $users,
        private readonly SsoSessionManager $sessions,
        private readonly SessionTerminator $terminator,
        private readonly AuthCodeService $codes,
        private readonly ErrorController $errors,
    ) {
    }

    public function authorize(Request $request): Response
    {
        try {
            $auth = AuthorizeRequest::fromQuery($request->query, $this->clients, $this->uris);
        } catch (InvalidRedirectException $e) {
            // JANGAN redirect ke URI yang tidak terverifikasi.
            return $this->errors->show($request->withAttribute('kind', $e->getMessage() === 'client' ? 'client' : 'redirect_uri'));
        } catch (OAuthException $e) {
            return $this->redirectError($e);
        }

        $prompt = is_string($request->query['prompt'] ?? null) ? $request->query['prompt'] : '';
        $session = $this->sessions->current($request);
        $clear = false;
        if ($session !== null && !$this->usable($session)) {
            if ($session->revokedAt === null && $this->mfaIncomplete($session)) {
                // Sesi tanpa MFA lulus: akhiri agar tidak berputar di /login (yang mengalihkan bila sesi ada).
                $this->terminator->endSession($session->sid, 'mfa_incomplete');
            }
            $session = null;
            $clear = true;
        }

        if ($session === null) {
            if ($prompt === 'none') {
                return $this->redirect($auth->redirectUri, ['error' => 'login_required', 'state' => $auth->state]);
            }
            $return = $request->path . '?' . http_build_query($request->query);
            if (strlen($return) > 2000) {
                return $this->redirectError(new OAuthException('invalid_request', 'Permintaan terlalu panjang', 400, $auth->redirectUri, $auth->state));
            }
            $response = Response::redirect('/login?return=' . rawurlencode($return))->withHeader('Cache-Control', 'no-store');

            return $clear ? $response->withCookie($this->sessions->clearCookie()) : $response;
        }

        $code = $this->codes->issue($auth, $session);

        return $this->redirect($auth->redirectUri, ['code' => $code, 'state' => $auth->state]);
    }

    private function usable(SsoSession $session): bool
    {
        $user = $this->users->findById($session->userId);

        return $user !== null && $user->status === 'active' && !$this->mfaIncomplete($session, $user->mfaEnabledAt !== null);
    }

    private function mfaIncomplete(SsoSession $session, ?bool $mfaEnabled = null): bool
    {
        $enabled = $mfaEnabled ?? (($this->users->findById($session->userId)?->mfaEnabledAt) !== null);

        return $enabled && $session->mfaPassedAt === null;
    }

    private function redirectError(OAuthException $e): Response
    {
        if ($e->redirectUri === null) {
            return $this->errors->show((new Request('GET', '/', []))->withAttribute('kind', 'redirect_uri'));
        }

        return $this->redirect($e->redirectUri, [
            'error' => $e->error,
            'error_description' => $e->errorDescription,
            'state' => $e->state,
        ]);
    }

    /** @param array<string,?string> $params */
    private function redirect(string $uri, array $params): Response
    {
        $query = http_build_query(array_filter($params, static fn (?string $v): bool => $v !== null), '', '&', PHP_QUERY_RFC3986);
        $url = $uri . (str_contains($uri, '?') ? '&' : '?') . $query;

        return Response::redirect($url, 302)->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }
}
