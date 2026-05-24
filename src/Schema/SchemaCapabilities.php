<?php

namespace Ernestdefoe\SocialGroups\Schema;

use Illuminate\Database\ConnectionInterface;

/**
 * Snapshot dos flags de capacidade do schema. Inspeciona o banco uma
 * única vez no boot do container e expõe os resultados como propriedades
 * readonly para todos os controllers e services.
 *
 * Substituiu o cache `static $schema = null` por dentro de `handle()`:
 * estáticos em workers de queue / Octane / RoadRunner persistem por todo
 * o ciclo do processo. Se uma migração nova rodar com workers vivos, o
 * static velho continuaria respondendo como se a coluna não existisse,
 * indefinidamente, até o próximo restart. Singletons de container têm
 * exatamente o mesmo escopo de processo, mas a dependência fica
 * explícita no constructor e o contrato fica documentado em um único
 * lugar — operadores de prod recebem instrução para rodar
 * `cache:clear` + reiniciar workers depois de migrate (prática-padrão
 * Flarum). Veja CLAUDE.md §44.
 */
class SchemaCapabilities
{
    public readonly bool $isGallery;
    public readonly bool $isPinned;
    public readonly bool $sharedFrom;
    public readonly bool $polls;
    public readonly bool $reactions;
    public readonly bool $linkPreview;

    public function __construct(ConnectionInterface $db)
    {
        $sb = $db->getSchemaBuilder();

        $this->isGallery   = $sb->hasColumn('social_group_discussions', 'is_gallery');
        $this->isPinned    = $sb->hasColumn('social_group_discussions', 'is_pinned');
        $this->sharedFrom  = $sb->hasColumn('social_group_discussions', 'shared_from_discussion_id');
        $this->polls       = $sb->hasTable('sg_polls')
                          && $sb->hasTable('sg_poll_options')
                          && $sb->hasTable('sg_poll_votes');
        $this->reactions   = $sb->hasTable('social_group_post_reactions');
        $this->linkPreview = $sb->hasColumn('social_group_posts', 'link_preview');
    }

    /**
     * Forma legada de array para consumidores ainda não migrados para
     * acesso por propriedade. Remover quando todos os call-sites usarem
     * `$capabilities->isGallery` etc.
     */
    public function toArray(): array
    {
        return [
            'is_gallery'   => $this->isGallery,
            'is_pinned'    => $this->isPinned,
            'shared_from'  => $this->sharedFrom,
            'polls'        => $this->polls,
            'reactions'    => $this->reactions,
            'link_preview' => $this->linkPreview,
        ];
    }
}
