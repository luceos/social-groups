<?php

namespace Ernestdefoe\SocialGroups\Service\Discussion;

use Carbon\Carbon;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Ernestdefoe\SocialGroups\Schema\SchemaCapabilities;
use Flarum\Api\Context;
use Flarum\Formatter\Formatter;
use Flarum\User\Exception\PermissionDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tobyz\JsonApiServer\Exception\BadRequestException;

class ShareDiscussionService
{
    public function __construct(
        protected SchemaCapabilities $capabilities,
        protected Formatter $formatter,
        protected TranslatorInterface $translator,
    ) {
    }

    /**
     * Creates a NEW discussion in the target group that references the
     * source via shared_from_discussion_id. The body carries
     * targetGroupId + optional content. Mirrors the legacy
     * ShareGroupDiscussionController's auth (member of target group) and
     * side effects (auto-title, first post creation).
     *
     * Hands the new discussion back to the response pipeline via
     * $context->model so it re-serialises through the standard Resource
     * fields, matching what GET /api/social-group-discussions/{id}
     * would return.
     */
    public function share(Context $context): SocialGroupDiscussion
    {
        $actor = $context->getActor();
        /** @var SocialGroupDiscussion $source */
        $source = $context->model;

        $body  = (array) ($context->request->getParsedBody() ?? []);
        $targetGroupId = (int) ($body['targetGroupId'] ?? 0);
        $content       = trim((string) ($body['content'] ?? ''));

        if ($targetGroupId <= 0) {
            throw new BadRequestException($this->translator->trans('ernestdefoe-social-groups.lib.errors.target_group_required'));
        }
        if (! $this->capabilities->sharedFrom) {
            throw new BadRequestException($this->translator->trans('ernestdefoe-social-groups.lib.errors.sharing_unavailable'));
        }

        $target = SocialGroup::find($targetGroupId);
        if ($target === null) {
            throw new BadRequestException($this->translator->trans('ernestdefoe-social-groups.lib.errors.target_group_not_found'));
        }

        $isMember = $target->members()
            ->where('user_id', $actor->id)
            ->whereNull('banned_at')
            ->exists();
        if (! $isMember) {
            throw new PermissionDeniedException();
        }

        if ($content === '') {
            $content = $this->translator->trans('ernestdefoe-social-groups.lib.shared_from', [
                '{name}' => $source->group?->name
                    ?? $this->translator->trans('ernestdefoe-social-groups.lib.another_group'),
            ]);
        }
        if (mb_strlen($content) > 20000) {
            throw new BadRequestException($this->translator->trans('ernestdefoe-social-groups.lib.errors.content_too_long'));
        }

        $now           = Carbon::now();
        $contentParsed = $this->formatter->parse($content);

        $discussion = SocialGroupDiscussion::create([
            'group_id'                  => $target->id,
            'user_id'                   => $actor->id,
            'title'                     => mb_substr('Shared: ' . $source->title, 0, 255),
            'comment_count'             => 1,
            'last_posted_at'            => $now,
            'last_posted_user_id'       => $actor->id,
            'is_locked'                 => false,
            'shared_from_discussion_id' => $source->id,
        ]);

        SocialGroupPost::create([
            'discussion_id'  => $discussion->id,
            'group_id'       => $target->id,
            'user_id'        => $actor->id,
            'content'        => $content,
            'content_parsed' => $contentParsed,
        ]);

        $context->model = $discussion;
        return $discussion;
    }
}
