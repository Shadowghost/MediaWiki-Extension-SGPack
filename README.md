# Stargate Wiki Extension Pack for MediaWiki

This MediaWiki extension bundles additional functionality for [https://stargate-wiki.de](https://stargate-wiki.de):

* Personal `Who's Online` link and auto-removal of logged out users
* Edit icon and jump-to-top icon instead of edit text on headings
* Multiple parser extensions
* Implementation of dropdown menu
* Automatical loading of a template selector when creating new pages (namespace specific)
* HTML5 audio player for audio files

## Requirements

MediaWiki 1.43 or later, and PHP 8.1 or later.

## Installation

```php
wfLoadExtension( 'SGPack' );
```

## Configuration

| Setting | Default | Purpose |
|---|---|---|
| `$wgSGPackImageTop` | `/extensions/SGPack/resources/arrow-up-icon.png` | Icon for the jump-to-top link on headings |
| `$wgSGPackImageEdit` | `/extensions/SGPack/resources/pencil-edit-icon.png` | Icon that replaces the `[edit]` text on headings |

## Upgrading

Three changes need attention when upgrading from an earlier version.

**Settings renamed.** `$wgSGHTMLImageTop` and `$wgSGHTMLImageEdit` are now `$wgSGPackImageTop` and
`$wgSGPackImageEdit`. Update `LocalSettings.php`, or the icons silently fall back to their defaults.

**`<jsbutton>` no longer accepts `click`, `mover` or `mout`.** Those attributes wrote their contents straight
into `onclick`, `onmouseover` and `onmouseout`, which let anyone able to edit a page run arbitrary JavaScript
for every visitor. They cannot be made safe while editors supply their contents, so they were removed rather
than escaped. Any page relying on them loses that behaviour; move the script into a gadget or
`MediaWiki:Common.js` instead. All other attributes still work and are now properly escaped.

**`{{userinfo:email|SomeUser}}` no longer accepts a username.** It used to load any account and render its
email address into the page with no permission check. `{{userinfo:email}}` still returns the requesting user's
own address. `home` and `talk` still accept a username, since they expose nothing that is not already public.

## Development

See `CLAUDE.md` for the architecture notes, the PHP↔JS contract, and how to run the checks:

```bash
composer test   # parallel-lint, minus-x, phpcs, phpunit structure tests
npm test        # eslint, TypeScript typecheck of resources/, banana i18n validation
```

The JavaScript in `resources/` is plain JS, typechecked in place against
[`types-mediawiki`](https://www.npmjs.com/package/types-mediawiki) via `checkJs` — run it alone with
`npm run typecheck`.
