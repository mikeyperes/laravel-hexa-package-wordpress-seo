<?php

namespace hexa_package_wordpress_seo\Services;

use hexa_package_wordpress_seo\Contracts\SeoProviderInterface;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;

class SeoProviderRegistry
{
    public function __construct(protected Application $app)
    {
    }

    public function get(string $key): SeoProviderInterface
    {
        $providers = (array) config("wordpress-seo.providers", []);
        $class = $providers[$key] ?? null;

        if (!is_string($class) || !class_exists($class)) {
            throw new InvalidArgumentException("Unknown SEO provider: " . $key);
        }

        $provider = $this->app->make($class);
        if (!$provider instanceof SeoProviderInterface) {
            throw new InvalidArgumentException("SEO provider does not implement the required contract: " . $key);
        }

        return $provider;
    }

    public function all(): array
    {
        $providers = [];
        foreach (array_keys((array) config("wordpress-seo.providers", [])) as $key) {
            $providers[$key] = $this->get((string) $key);
        }

        return $providers;
    }
}
