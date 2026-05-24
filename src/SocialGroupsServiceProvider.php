<?php

namespace Ernestdefoe\SocialGroups;

use Ernestdefoe\SocialGroups\Schema\SchemaCapabilities;
use Flarum\Foundation\AbstractServiceProvider;

/**
 * Bindings de container da extensão.
 *
 * `SchemaCapabilities` é resolvido como singleton: o objeto sonda o
 * banco apenas no primeiro `make()` do processo. Migrações novas exigem
 * `cache:clear` + restart de workers, prática-padrão de deploy Flarum.
 */
class SocialGroupsServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(SchemaCapabilities::class);
    }
}
