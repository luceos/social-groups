<?php

namespace Ernestdefoe\SocialGroups;

use Ernestdefoe\SocialGroups\Api\Controller\FetchLinkPreviewController;
use Ernestdefoe\SocialGroups\Schema\SchemaCapabilities;
use Flarum\Foundation\AbstractServiceProvider;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Builder as SchemaBuilder;

/**
 * Container bindings for the extension.
 *
 * `SchemaCapabilities` is resolved as a singleton: the object probes the
 * database only on the first `make()` of the process. New migrations
 * require `cache:clear` + worker restart, standard Flarum deploy practice.
 */
class SocialGroupsServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(SchemaCapabilities::class);

        // Resolve a concrete schema Builder for SchemaCapabilities so it can
        // type-hint the narrow introspection dependency instead of a whole
        // ConnectionInterface (CLAUDE.md §10 / §39.3). Bound once here so the
        // ConnectionInterface reference stays out of application code.
        $this->container->bind(SchemaBuilder::class, function ($container) {
            return $container->make(ConnectionInterface::class)->getSchemaBuilder();
        });

        // Inject a pre-configured Guzzle client into the link-preview
        // controller so its timeout/connect-timeout live in the container and
        // the dependency can be swapped or mocked. Scoped contextually so the
        // global ClientInterface binding other code relies on is untouched.
        $this->container->when(FetchLinkPreviewController::class)
            ->needs(ClientInterface::class)
            ->give(function () {
                return new Client([
                    'timeout'         => 8,
                    'connect_timeout' => 5,
                ]);
            });
    }
}
