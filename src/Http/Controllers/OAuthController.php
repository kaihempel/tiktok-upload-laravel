<?php

declare(strict_types=1);

namespace KaiHempel\TikTokUpload\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use KaiHempel\TikTokUpload\Contracts\TokenRepository;
use KaiHempel\TikTokUpload\Exceptions\TikTokException;
use KaiHempel\TikTokUpload\OAuth\OAuthService;
use KaiHempel\TikTokUpload\TikTokManager;

/**
 * Optional OAuth routes (connect, callback, disconnect).
 */
class OAuthController extends Controller
{
    public const string SESSION_KEY = 'tiktok-upload.oauth';

    public function connect(Request $request, OAuthService $oauth): RedirectResponse
    {
        $this->owner($request);

        $authorization = $oauth->createAuthorizationRequest();

        $request->session()->put(self::SESSION_KEY, [
            'state' => $authorization->state,
            'code_verifier' => $authorization->codeVerifier,
        ]);

        return redirect()->away($authorization->url);
    }

    public function callback(Request $request, OAuthService $oauth): RedirectResponse
    {
        $stored = $request->session()->pull(self::SESSION_KEY);
        $state = $request->query('state');

        // CSRF protection: the state has to match the one stored in the session.
        abort_unless(
            is_array($stored) && is_string($stored['state'] ?? null) && is_string($state) && hash_equals($stored['state'], $state),
            403,
            'Invalid OAuth state.',
        );

        if ($request->filled('error')) {
            return $this->failure((string) $request->query('error_description', (string) $request->query('error')));
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return $this->failure('TikTok did not return an authorization code.');
        }

        $verifier = is_string($stored['code_verifier'] ?? null) ? $stored['code_verifier'] : null;

        try {
            $account = $oauth->connect($code, $this->owner($request), $verifier);
        } catch (TikTokException $e) {
            report($e);

            return $this->failure($e->getMessage());
        }

        return redirect()
            ->to((string) config('tiktok-upload.routes.success_redirect', '/'))
            ->with('tiktok-upload.connected', $account->id);
    }

    public function disconnect(Request $request, string $id, OAuthService $oauth, TokenRepository $tokens): RedirectResponse
    {
        $owner = $this->owner($request);
        $account = $tokens->find($id);

        abort_if($account === null, 404);
        abort_unless(
            $account->ownerType === $owner->getMorphClass() && $account->ownerId === (string) $owner->getKey(),
            403,
        );

        try {
            $oauth->revoke($account, $request->boolean('delete'));
        } catch (TikTokException $e) {
            report($e);

            return $this->failure($e->getMessage());
        }

        return redirect()
            ->to((string) config('tiktok-upload.routes.success_redirect', '/'))
            ->with('tiktok-upload.disconnected', $account->id);
    }

    private function owner(Request $request): Model
    {
        $owner = TikTokManager::resolveOwner($request);

        abort_if($owner === null, 401);

        return $owner;
    }

    private function failure(string $message): RedirectResponse
    {
        return redirect()
            ->to((string) config('tiktok-upload.routes.failure_redirect', '/'))
            ->with('tiktok-upload.error', $message);
    }
}
