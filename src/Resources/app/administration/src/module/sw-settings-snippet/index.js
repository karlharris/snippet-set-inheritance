import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

import './page/sw-settings-snippet-set-list';
import './page/sw-settings-snippet-list';
import './page/sw-settings-snippet-detail';

// Extends the core `sw-settings-snippet` module by overriding three of its
// components — no module of our own.
Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);
