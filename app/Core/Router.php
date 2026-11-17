<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): self
    {
        $method = strtoupper($method);
        if (!preg_match('/\A[A-Z]+\z/', $method) || !str_starts_with($path, '/')) {
            throw new InvalidArgumentException('Invalid route.');
        }
        $names = [];
        $segments = explode('/', $path);
        foreach ($segments as &$segment) {
            if (preg_match('/\A\{([A-Za-z_][A-Za-z0-9_]*)\}\z/', $segment, $match)) {
                if (in_array($match[1], $names, true)) {
                    throw new InvalidArgumentException('Duplicate route parameter.');
                }
                $names[] = $match[1];
                $segment = '([1-9][0-9]*)';
            } else {
                if (strpbrk($segment, '{}') !== false) {
                    throw new InvalidArgumentException('Invalid route parameter.');
                }
                $segment = preg_quote($segment, '~');
            }
        }
        unset($segment);
        foreach ($this->routes as $route) {
            if ($route['method'] === $method && $route['path'] === $path) {
                throw new InvalidArgumentException('Duplicate route.');
            }
        }
        $this->routes[] = compact('method', 'path', 'handler', 'names') + ['regex' => '~\A' . implode('/', $segments) . '\z~'];
        return $this;
    }

    public function dispatch(Request $request): Response
    {
        $matches = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $values)) {
                continue;
            }
            $params = [];
            foreach ($route['names'] as $index => $name) {
                $value = filter_var($values[$index + 1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($value === false) {
                    continue 2;
                }
                $params[$name] = $value;
            }
            $matches[] = $route + ['params' => $params];
        }
        if ($matches === []) {
            throw new HttpException(404, 'Not found.');
        }
        // Specific paths win regardless of registration order or request method.
        $specificity = min(array_map(static fn (array $route): int => count($route['names']), $matches));
        $allowed = [];
        foreach ($matches as $route) {
            if (count($route['names']) !== $specificity) {
                continue;
            }
            $allowed[] = $route['method'];
            if ($route['method'] === $request->method) {
                return ($route['handler'])($request, $route['params']);
            }
        }
        throw new HttpException(405, 'Method not allowed.', [], ['Allow' => implode(', ', array_unique($allowed))]);
    }
}
