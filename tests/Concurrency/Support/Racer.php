<?php

declare(strict_types=1);

namespace Tests\Concurrency\Support;

use App\Models\User;
use RuntimeException;

/**
 * One user with their own session: a cookie jar kept across requests, exactly
 * like a browser. Logs in by GETting /login (session + XSRF-TOKEN cookies),
 * then POSTing the credentials with the token in X-XSRF-TOKEN.
 */
final class Racer
{
    /** @var array<string, string> */
    private array $cookies = [];

    public function __construct(public readonly User $user, public readonly string $label) {}

    public function cookieHeader(): ?string
    {
        if ($this->cookies === []) {
            return null;
        }

        return implode('; ', array_map(fn (string $name, string $value): string => "{$name}={$value}", array_keys($this->cookies), $this->cookies));
    }

    /** Laravel's CSRF token, as the X-XSRF-TOKEN header expects it (the cookie, URL-decoded). */
    public function xsrfToken(): ?string
    {
        return isset($this->cookies['XSRF-TOKEN']) ? urldecode($this->cookies['XSRF-TOKEN']) : null;
    }

    /** Keeps the cookies a response sets (name=value before the first `;`). */
    public function absorb(Exchange $exchange): void
    {
        foreach ($exchange->headers['set-cookie'] ?? [] as $line) {
            [$pair] = explode(';', $line, 2);
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $this->cookies[trim($name)] = trim($value);
        }
    }

    public function get(string $path): Exchange
    {
        return new Exchange($this, 'GET', $path);
    }

    /** @param array<string, scalar> $form */
    public function postForm(string $path, array $form): Exchange
    {
        return new Exchange($this, 'POST', $path, $form);
    }

    /** @param array<string, scalar> $data */
    public function postJson(string $path, array $data): Exchange
    {
        return new Exchange($this, 'POST', $path, $data, json: true);
    }

    /**
     * Logs every racer in, in parallel: their own session each.
     *
     * @param  list<Racer>  $racers
     */
    public static function logInAll(Http $http, array $racers): void
    {
        $pages = array_map(fn (Racer $racer): Exchange => $racer->get('/login'), $racers);
        $http->fire($pages);

        foreach ($pages as $page) {
            if ($page->status !== 200 || $page->racer->xsrfToken() === null) {
                throw new RuntimeException('GET /login gave no session: '.$page->describe());
            }
        }

        $logins = array_map(fn (Racer $racer): Exchange => $racer->postForm('/login', [
            'email' => $racer->user->email,
            'password' => 'password',
        ]), $racers);
        $http->fire($logins);

        foreach ($logins as $login) {
            $location = (string) $login->header('Location');

            if ($login->status !== 302 || str_contains($location, '/login')) {
                throw new RuntimeException("Login failed for {$login->racer->user->email}: HTTP {$login->status}, Location {$location}");
            }
        }
    }
}
