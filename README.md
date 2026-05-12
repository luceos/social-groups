# Social Groups for Flarum 2

A modern social groups extension for Flarum 2. Members can create communities, post discussions inside groups, and manage membership — all without requiring Flarum tags.

---

## Features

### Group Management
- **Group directory** at `/groups` — responsive card grid with banner images, member counts, and server-side search
- **Group detail pages** at `/groups/{slug}` — full-width hero banner, group avatar, member list, about panel
- **Create & edit groups** — name, description, accent color, privacy toggle, membership type, group avatar, and banner image
- **Quick-action menu** — a vertical ⋮ menu on each group card lets authorized users edit or delete a group without navigating to the group page first
- **Image uploads** — group avatar and banner stored directly on your server; no third-party service needed (JPEG, PNG, GIF, WebP · max 5 MB)
- **Permission-controlled creation** — admins decide which user groups can create social groups
- **Groups navigation link** — added automatically to the primary forum navigation bar

### Membership
- **Open or approval-required** — groups can be set to open (anyone joins instantly) or require the creator to approve each request
- **Join / leave** — one-click for open groups; "Request to Join" and "Pending…" states for approval groups
- **Invite members** — group creators and moderators can invite any forum user directly by username, regardless of the group's privacy or membership type
- **Join requests panel** — creators and admins see a panel in the group sidebar listing pending requests with Approve / Reject buttons
- **Join notifications** — group creator receives an in-app notification when someone joins an open group
- **Member roles** — `creator`, `moderator` (admin), `member`
- **Promote / demote** — creators can promote any member to moderator, or demote a moderator back to member, directly from the members sidebar

### Group Discussions
- **In-group discussion feed** — fully independent from Flarum's tags system; posts stay inside the group
- **Thread view** at `/groups/{slug}/d/{discussionId}` — full post list, inline reply composer
- **Rich text formatting** — post content is processed through Flarum's formatter, so BBCode and Markdown work out of the box
- **File & image attachments** — attach images, videos, PDFs, and other files directly to posts via the paperclip button in the reply composer; handled by [fof/upload](https://github.com/FriendsOfFlarum/upload), including WebP conversion when your server supports it
- **Edit & delete** — authors can edit or delete their own posts; group moderators can delete any post or discussion
- **Paginated feed** — 20 discussions per page with Previous / Next navigation

### Security
- Post content is run through Flarum's formatter on save and only sanitized HTML is served to clients — raw user input is never rendered directly

### Theme Compatibility
- All colors use CSS custom properties (`var(--primary-color)`, `var(--body-bg)`, `var(--control-bg)`, `var(--muted-color)`, etc.) so the extension adapts to any Flarum 2 theme, including **Avocado**
- Responsive layout works on mobile, tablet, and desktop

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | ≥ 8.3 |
| Flarum | ^2.0 |
| [fof/upload](https://github.com/FriendsOfFlarum/upload) | ^2.0 |
| PHP extensions | `fileinfo`, `curl` |

> **fof/upload** is a required dependency and will be installed automatically by Composer. After installation, go to **Admin → Extensions → FoF Upload** and configure your storage adapter and allowed file types. WebP image conversion is an option in that settings panel.

---

## Installation

```bash
composer require ernestdefoe/social-groups
php flarum migrate
php flarum cache:clear
```

Then go to **Admin → Extensions** and enable **Social Groups** and **FoF Upload**.

---

## Configuration

### Permissions

Go to **Admin → Permissions** and find the **Social Groups** section:

| Permission | Controls |
|---|---|
| **Create social groups** | Which user groups can create new social groups |
| **Edit & delete any social group** | Which user groups can edit or delete any group, not just their own |

**Create social groups** — set this to `Members` to let anyone create groups, or restrict it to `Moderators` / `Admins`.

**Edit & delete any social group** — grant this to your `Moderators` group (or any custom group) to allow them to edit group details or delete groups they did not create. Site admins always have this ability regardless of this setting.

> Group creators can always edit and delete their own group — this permission only extends that ability to other trusted users.

### Membership types

When creating or editing a group, choose:

| Type | Behaviour |
|---|---|
| **Open** | Any logged-in member can join instantly |
| **Approval required** | A join request is queued; the creator (or a group moderator) must approve it before the user becomes a member |

> Group creators and moderators can always **invite** a user directly regardless of the membership type — the invite bypasses both open-join and approval flows.

### Group avatar & banner images

Images are stored in `public/assets/social-groups/` and served directly. Supported formats: JPEG, PNG, GIF, WebP. Maximum size: 5 MB.

### Post attachments (fof/upload)

File attachments in group post threads are handled entirely by fof/upload. To configure:

1. Go to **Admin → Extensions → FoF Upload**
2. Choose a storage adapter (local disk, S3, etc.)
3. Set allowed MIME types and maximum file size
4. Enable **Convert images to WebP** if your server has GD or Imagick with WebP support

The attachment button appears in the reply composer for all group members. Uploaded files are stored by fof/upload and referenced in posts via BBCode (`[upl-file uuid="..."]`), which Flarum's formatter renders into the correct HTML.

---

## How It Works

### Groups directory (`/groups`)

All public (and member-visible private) groups are displayed in a responsive card grid. Each card shows the banner, avatar, name, member count, privacy/approval tags, and description excerpt.

The search bar sends a server-side `?filter[q]=` query so it works correctly on large forums. A **Create Group** button appears for users with the appropriate permission.

### Group detail page (`/groups/{slug}`)

The page is divided into:

- **Hero** — full-width banner, group avatar, name, member count, privacy & approval indicators, and action buttons (Join / Request to Join / Leave / Edit)
- **Main column** — the group's discussion feed
- **Sidebar** (right column):
  - *Join Requests panel* — visible to creators/moderators on approval-required groups; shows queued requests with Approve / Reject controls
  - *About this Group* — description, privacy tag, approval tag
  - *Members* — list of members with role badges; creators see Promote / Demote buttons and an **Invite** button

### Inviting members

Creators and moderators see an **Invite** button at the top of the Members sidebar. Clicking it opens a modal where you type the exact Flarum username of the person to invite. The user is added immediately as a `member` — no join request, no approval step needed — and the member list updates live.

### Group discussions & post attachments

Discussions are completely separate from Flarum's core discussion/tag system. Each discussion lives at `/groups/{slug}/d/{id}`. Members (and moderators) can:

- Start new discussions with a title and first post
- Reply inline at the bottom of the thread using the full reply composer
- Attach files by clicking the **paperclip** button — a preview chip appears below the textarea while the upload is in progress; images show as thumbnails; the Reply button stays disabled until all uploads complete
- Edit or delete their own posts (raw BBCode is shown in the edit textarea, including any attachment tags)
- Group moderators can delete any post or discussion

Post content is processed through Flarum's formatter on the server before it reaches the browser, so BBCode, Markdown, and fof/upload's image rendering all work. Sanitized HTML is stored alongside the raw source; the raw source is only sent back to the author when they open the edit form.

---

## Upgrading

```bash
composer update ernestdefoe/social-groups
php flarum migrate
php flarum cache:clear
```

---

## License

MIT © Ernestdefoe
