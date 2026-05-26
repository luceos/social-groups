# Social Groups for Flarum

[![Floxum](https://floxum.com/extension/ernestdefoe/social-groups/badge/name)](https://floxum.com/extension/ernestdefoe/social-groups)
[![Version](https://floxum.com/extension/ernestdefoe/social-groups/badge/highest-version)](https://floxum.com/extension/ernestdefoe/social-groups)
[![Downloads](https://floxum.com/extension/ernestdefoe/social-groups/badge/downloads)](https://floxum.com/extension/ernestdefoe/social-groups)
[![Review](https://floxum.com/extension/ernestdefoe/social-groups/badge/review)](https://floxum.com/extension/ernestdefoe/social-groups)
[![License](https://floxum.com/extension/ernestdefoe/social-groups/badge/license)](https://floxum.com/extension/ernestdefoe/social-groups)

![Flarum 2.x](https://img.shields.io/badge/Flarum-2.x-3B2ADB?logo=flarum&logoColor=white)
![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)
![License MIT](https://img.shields.io/badge/License-MIT-22c55e)

A full-featured social groups extension for Flarum 2. Members can create public or private groups, post to a Facebook-style feed, hold threaded discussions, share media, react to posts, run polls, and more — with optional real-time updates powered by `flarum/realtime`.

---

## Features

### Groups
- **Public & private groups** — public groups are open to all; private groups require a join request or direct invitation.
- **Group image & banner upload** — each group has its own avatar and cover banner image.
- **Featured groups** — administrators can pin groups to a featured row on the group directory.
- **Primary group badge** — members designate one group as their primary group; the badge is displayed on their Flarum profile.
- **Group analytics dashboard** — overview of member count, post activity, and growth over time (visible to group admins and moderators).
- **RSS feed** — each group exposes a per-group RSS 2.0 feed at `/groups/{slug}/feed.rss`.

### Feed
- Facebook-style post cards with full Flarum BBCode/Markdown rendering.
- **Emoji reactions** — six reaction types: 👍 Like, ❤️ Love, 😂 Haha, 😮 Wow, 😢 Sad, 😡 Angry; one reaction per user, toggleable.
- **Polls** — create polls directly from the post composer; members vote inline with live percentage bars.
- **Link previews** — URLs are automatically expanded into rich Open Graph preview cards (title, description, thumbnail).
- **Media attachments** — attach images and files to posts via `fof/upload` (optional).
- **Pin posts** — group admins can pin discussions to the top of the feed.
- **Post search** — debounced search bar filters discussions by title or content in real time.
- **Post sharing** — share any discussion into another group you belong to, with an optional comment; shared posts render a quoted card.

### Discussions
- Threaded discussion view at `/groups/{slug}/d/{discussionId}` with nested comment replies.
- **Pin discussions** to the top of the discussion list.
- **Share discussions** to the group feed.

### Media Gallery
- Dedicated "Media" tab on each group page aggregating all images from posts and discussions into a responsive thumbnail grid with lightbox.

### Member Management
- **Join requests** — group admins approve or reject membership requests; direct invitations bypass the queue.
- **Promote / demote** — elevate a member to group admin or step them back down.
- **Kick** — remove a member from the group.
- **Member badges on profiles** — group membership chips appear on Flarum user profile cards forum-wide.

### Notifications
- Alert when a new post is created in a group the user participates in.
- Alert when someone replies directly to a user's post.

### Real-time (optional — requires `flarum/realtime`)
See the [flarum/realtime Integration](#flarumrealtime-integration) section below.

---

## Requirements

| Dependency | Version | Required |
|---|---|---|
| Flarum | 2.x | Yes |
| PHP | 8.3+ | Yes |
| `flarum/realtime` | any | No — graceful no-op if absent |
| `fof/upload` | ^2.0 | No — required for file/image attachments in posts |

---

## Installation

```bash
composer require ernestdefoe/social-groups
php flarum migrate
php flarum cache:clear
```

Then go to **Admin → Extensions** and enable **Social Groups**.

> **After every update**, run the migrate and cache-clear commands to apply any new database migrations:
> ```bash
> php flarum migrate && php flarum cache:clear
> ```

---

## Configuration

### Admin panel settings

Navigate to **Admin → Extensions → Social Groups**:

| Setting | Options | Default |
|---|---|---|
| **Who can create groups** | `member` — any registered user; `admin` — forum administrators only | `member` |

### Group privacy

Group creators choose privacy at creation time:

| Type | Behaviour |
|---|---|
| **Public** | Anyone can view the feed, discussions, and gallery. Joining is instant. |
| **Private** | Content is hidden from non-members. New members must submit a join request (approved by a group admin) or be invited directly. |

---

## flarum/realtime Integration

The real-time features are entirely **optional**. If `flarum/realtime` is not installed the extension works normally — the typing endpoint silently returns `204` and the post-broadcast listener exits immediately without any error or warning.

When `flarum/realtime` **is** installed and running:

- **Live post injection** — after a member submits a reply in a group discussion thread, all other members viewing that thread see the new post card appear instantly via the `sg-post-created` WebSocket event. No page refresh is needed.
- **Typing indicator** — while a member is composing a reply, an animated "Jane is typing…" indicator with bouncing dots is broadcast via the `sg-typing` event and displayed above the reply box for all other members viewing the same thread. The indicator disappears automatically when the member stops typing or submits their post.
- **Deduplication** — the client compares each incoming post's ID against already-rendered posts to prevent duplicates when both the HTTP response and the WebSocket push arrive for the same post.
- **No extra configuration** — the extension uses the existing public Pusher/Soketi channel that `flarum/realtime` sets up. Nothing additional needs to be configured.

---

## Updating

```bash
composer update ernestdefoe/social-groups
php flarum migrate
php flarum cache:clear
```

---

## License

Released under the [MIT License](LICENSE). © Ernestdefoe
