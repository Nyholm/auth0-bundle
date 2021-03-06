<?php

declare(strict_types=1);

namespace Happyr\Auth0Bundle\Security\Authentication;

use Auth0\SDK\API\Authentication;
use Auth0\SDK\Exception\ForbiddenException;
use Happyr\Auth0Bundle\Model\UserInfo;
use Happyr\Auth0Bundle\Security\Passport\Auth0Badge;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\PassportInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\HttpUtils;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

final class Auth0Authenticator extends AbstractAuthenticator implements ServiceSubscriberInterface
{
    /**
     * @var string
     */
    private $checkRoute;

    /**
     * @var ContainerInterface
     */
    private $locator;

    public function __construct(ContainerInterface $locator, string $checkRoute)
    {
        $this->locator = $locator;
        $this->checkRoute = $checkRoute;
    }

    public static function getSubscribedServices()
    {
        return [
            Authentication::class,
            CsrfTokenManagerInterface::class,
            HttpUtils::class,
            AuthenticationSuccessHandlerInterface::class,
            AuthenticationFailureHandlerInterface::class,
            '?'.Auth0UserProviderInterface::class,
        ];
    }

    public function supports(Request $request): ?bool
    {
        if ($request->attributes->get('_route') !== $this->checkRoute) {
            return false;
        }

        return $request->query->has('code') && $request->query->has('state');
    }

    public function authenticate(Request $request): PassportInterface
    {
        if (null === $code = $request->query->get('code')) {
            throw new AuthenticationException('No oauth code in the request.');
        }

        if (null === $state = $request->query->get('state')) {
            throw new AuthenticationException('No state in the request.');
        }

        if (!$this->get(CsrfTokenManagerInterface::class)->isTokenValid(new CsrfToken('auth0-sso', $state))) {
            throw new AuthenticationException('Invalid CSRF token');
        }

        try {
            $redirectUri = $this->get(HttpUtils::class)->generateUri($request, $this->checkRoute);
            $tokenStruct = $this->get(Authentication::class)->codeExchange($code, $redirectUri);
        } catch (ForbiddenException $e) {
            throw new AuthenticationException($e->getMessage(), $e->getCode(), $e);
        }

        try {
            // Fetch info from the user
            $userInfo = $this->get(Authentication::class)->userinfo($tokenStruct['access_token']);
            $userModel = UserInfo::create($userInfo);
        } catch (\Exception $e) {
            throw new AuthenticationException('Could not fetch user info from Auth0', 0, $e);
        }

        $userProviderCallback = null;
        if (null !== $up = $this->get(Auth0UserProviderInterface::class)) {
            $userProviderCallback = static function () use ($up, $userModel) {
                return $up->loadByUserModel($userModel);
            };
        }

        return new SelfValidatingPassport(new UserBadge($userModel->getUserId(), $userProviderCallback), [new Auth0Badge($userModel)]);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return $this->get(AuthenticationSuccessHandlerInterface::class)->onAuthenticationSuccess($request, $token);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new RedirectResponse('/');

        return $this->get(AuthenticationFailureHandlerInterface::class)->onAuthenticationFailure($request, $exception);
    }

    /**
     * @template T of object
     * @psalm-param class-string<T> $class
     *
     * @return T
     */
    private function get(string $service)
    {
        if ($this->locator->has($service)) {
            return $this->locator->get($service);
        }

        return null;
    }
}
