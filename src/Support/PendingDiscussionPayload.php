<?php

namespace Ernestdefoe\SocialGroups\Support;

/**
 * Carries the first-post + poll payload from SocialGroupDiscussionResource's
 * creating() hook to its created() hook. Held on the discussion model as a
 * single declared, typed property so the hand-off is one explicit contract a
 * static analyzer can follow, rather than three loose magic properties.
 */
class PendingDiscussionPayload
{
    public function __construct(
        public readonly string $content,
        public readonly ?array $linkPreview = null,
        public readonly ?array $poll = null,
    ) {}
}
