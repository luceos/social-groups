import app from 'flarum/admin/app';

// Re-exporting `extend` as a named export is what bootExtensions uses
// to discover and apply Admin extenders. The default export is ignored.
export { default as extend } from './extend';

app.initializers.add('ernestdefoe-social-groups', () => {});
