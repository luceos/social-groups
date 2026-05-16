import { apiBase } from '../utils/api';
import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

export default class GroupAnalyticsPanel extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.expanded = true;
    this.data     = null;
    this.loading  = false;
    this.error    = false;
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    this.load();
  }

  toggle() {
    this.expanded = !this.expanded;
    if (this.expanded && !this.data && !this.loading) this.load();
    m.redraw();
  }

  load() {
    this.loading = true;

    fetch(`${apiBase()}/sg-analytics/${this.attrs.groupId}`, {
      credentials: 'same-origin',
      headers:     { 'X-CSRF-Token': app.session.csrfToken || '' },
    })
      .then((r) => {
        if (!r.ok) return r.json().then((e) => { throw new Error(e.error || 'Error'); });
        return r.json();
      })
      .then((data) => {
        this.data    = data;
        this.loading = false;
        m.redraw();
      })
      .catch(() => {
        this.error   = true;
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    return m('.SGAnalytics', [
      m('button.SGAnalytics-toggle', { onclick: () => this.toggle() }, [
        m('span.SGAnalytics-toggleLabel', [m('i.fa-solid.fa-chart-bar'), ' Analytics']),
        m('i.fas', { class: this.expanded ? 'fa-chevron-up' : 'fa-chevron-down' }),
      ]),

      this.expanded ? this.viewBody() : null,
    ]);
  }

  viewBody() {
    if (this.loading) return m('.SGAnalytics-body', m(LoadingIndicator, { display: 'block' }));
    if (this.error)   return m('.SGAnalytics-body.SGAnalytics-error', 'Failed to load analytics.');
    if (!this.data)   return null;

    const { summary, memberGrowth, postVolume, topPosts } = this.data;

    return m('.SGAnalytics-body', [

      // Summary stats
      m('.SGAnalytics-stats', [
        this.viewStat(summary.totalMembers,   'Members',   'fa-users'),
        this.viewStat(summary.totalPosts,     'Posts',     'fa-comment'),
        this.viewStat(summary.totalReactions, 'Reactions', 'fa-heart'),
      ]),

      // Member growth chart
      m('.SGAnalytics-section', [
        m('h4.SGAnalytics-sectionTitle', 'New Members — Last 30 Days'),
        this.viewBarChart(memberGrowth, 'date',      'count', '#4A90E2'),
      ]),

      // Post volume chart
      m('.SGAnalytics-section', [
        m('h4.SGAnalytics-sectionTitle', 'Posts — Last 8 Weeks'),
        this.viewBarChart(postVolume,   'weekStart', 'count', '#7b5ea7'),
      ]),

      // Top posts
      topPosts && topPosts.length > 0
        ? m('.SGAnalytics-section', [
            m('h4.SGAnalytics-sectionTitle', 'Top Reacted Posts'),
            m('ol.SGAnalytics-topPosts',
              topPosts.map((p) =>
                m('li.SGAnalytics-topPost', {
                  key:     p.postId,
                  onclick: () => m.route.set(app.route('ernestdefoe-social-groups.discussion', {
                    slug:         this.attrs.groupSlug,
                    discussionId: p.discussionId,
                  })),
                }, [
                  m('span.SGAnalytics-topPostReactions', [
                    m('i.fa-solid.fa-heart'), ' ', p.totalReactions,
                  ]),
                  m('span.SGAnalytics-topPostSnippet', p.snippet || '—'),
                ])
              )
            ),
          ])
        : null,
    ]);
  }

  viewStat(value, label, icon) {
    return m('.SGAnalytics-stat', [
      m('i.fa-solid.' + icon),
      m('span.SGAnalytics-statValue', value),
      m('span.SGAnalytics-statLabel', label),
    ]);
  }

  viewBarChart(data, labelKey, countKey, color) {
    if (!data || !data.length) return null;

    const W   = 240;
    const H   = 56;
    const gap = 2;
    const max = Math.max(1, ...data.map((d) => d[countKey]));
    const barW = Math.max(1, (W - gap * (data.length - 1)) / data.length);

    return m('svg.SGAnalytics-chart', {
      viewBox:             `0 0 ${W} ${H}`,
      preserveAspectRatio: 'none',
      style:               `width:100%;height:${H}px`,
    }, [
      data.map((d, i) => {
        const h = Math.max(2, Math.round((d[countKey] / max) * H));
        return m('rect', {
          key:    i,
          x:      Math.round(i * (barW + gap)),
          y:      H - h,
          width:  Math.floor(barW),
          height: h,
          fill:   color,
          rx:     2,
          style:  'opacity:0.85',
        }, m('title', `${d[labelKey]}: ${d[countKey]}`));
      }),
    ]);
  }
}
