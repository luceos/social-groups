<?php

namespace Ernestdefoe\SocialGroups;

use Ernestdefoe\SocialGroups\Schema\SchemaCapabilities;
use Flarum\Foundation\AbstractServiceProvider;

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
    }
}
