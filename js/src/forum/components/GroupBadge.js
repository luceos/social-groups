import app from 'flarum/forum/app';

/**
 * A small colored pill displayed below a username on posts.
 * Props: name, color, slug
 */
export default class GroupBadge {
  view({ attrs }) {
    const { name, color, slug } = attrs;

    return m(
      'a.SG-Badge',
      {
        href: app.route('ernestdefoe-social-groups.show', { slug }),
        style: `--sg-badge-color: ${color || '#4A90E2'}`,
        onclick: (e) => {
          e.preventDefault();
          e.stopPropagation();
          m.route.set(app.route('ernestdefoe-social-groups.show', { slug }));
        },
        title: name,
      },
      [m('i.fas.fa-users'), ' ', name]
    );
  }
}
