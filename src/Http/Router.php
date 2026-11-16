<?php

namespace Social\Http;

use Social\App;
use Social\Exceptions\ApiException;

final class Router
{
    private array $routes = [];

    public function __construct(private readonly App $app)
    {
    }

    public function get(string $pattern, callable $handler, array $options = []): self
    {
        return $this->add('GET', $pattern, $handler, $options);
    }

    public function post(string $pattern, callable $handler, array $options = []): self
    {
        return $this->add('POST', $pattern, $handler, $options);
    }

    public function put(string $pattern, callable $handler, array $options = []): self
    {
        return $this->add('PUT', $pattern, $handler, $options);
    }

    public function patch(string $pattern, callable $handler, array $options = []): self
    {
        return $this->add('PATCH', $pattern, $handler, $options);
    }

    public function delete(string $pattern, callable $handler, array $options = []): self
    {
        return $this->add('DELETE', $pattern, $handler, $options);
    }

    public function add(string $method, string $pattern, callable $handler, array $options = []): self
    {
        [$regex, $names] = $this->compile($pattern);
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => $regex,
            'names' => $names,
            'handler' => $handler,
            'options' => $options,
        ];

        return $this;
    }

    public function dispatch(Request $request): Response
    {
        $method = strtoupper($request->method);
        $lookupMethod = $method === 'HEAD' ? 'GET' : $method;
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }

            $pathMatched = true;

            if ($route['method'] !== $lookupMethod) {
                continue;
            }

            $params = [];

            foreach ($route['names'] as $index => $name) {
                $params[$name] = rawurldecode((string) ($matches[$index + 1] ?? ''));
            }

            $this->authorize($request, $route['options']);

            return ($route['handler'])($request, $params);
        }

        if ($pathMatched) {
            throw new ApiException(405, 'method_not_allowed', 'Method not allowed for this endpoint.', [], ['Allow' => $this->allowedMethods($request->path)]);
        }

        throw ApiException::notFound('Endpoint not found.');
    }

    private function authorize(Request $request, array $options): void
    {
        $mode = $options['auth'] ?? false;

        if ($mode === true || $mode === 'optional') {
            $user = $this->app->resolveUser($request, $mode === true);
            $request->setUser($user);

            if ($mode === true && $user === null) {
                throw ApiException::unauthorized();
            }
        }

        $role = $options['role'] ?? null;

        if ($role !== null) {
            $allowed = is_array($role) ? $role : [$role];
            $current = $request->user()['role'] ?? null;

            if (!in_array($current, $allowed, true)) {
                throw ApiException::forbidden('This endpoint requires a different role.');
            }
        }
    }

    private function allowedMethods(string $path): string
    {
        $methods = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path)) {
                $methods[] = $route['method'];
            }
        }

        return implode(', ', array_unique($methods));
    }

    private function compile(string $pattern): array
    {
        $pattern = '/' . trim($pattern, '/');

        if ($pattern === '/') {
            return ['#^/$#', []];
        }

        $names = [];
        $parts = [];

        foreach (explode('/', substr($pattern, 1)) as $segment) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)(?::(.+))?\}$/', $segment, $match)) {
                $names[] = $match[1];
                $parts[] = '(' . ($match[2] ?? '[^/]+') . ')';
            } else {
                $parts[] = preg_quote($segment, '#');
            }
        }

        return ['#^/' . implode('/', $parts) . '/?$#', $names];
    }
}
