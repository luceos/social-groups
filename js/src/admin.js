import app from 'flarum/admin/app';
import extenders from './extend';

app.initializers.add('ernestdefoe-social-groups', () => {
  // Admin extenders register permissions and settings
  // The extend.js array is applied automatically by Flarum's admin bootstrap
});

export default extenders;
