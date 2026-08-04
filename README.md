# Stargate Wiki Extension Pack for MediaWiki

This MediaWiki extension bundles additional functionality for [https://stargate-wiki.de](https://stargate-wiki.de):

* Personal `Who's Online` link and auto-removal of logged out users
* Edit icon and jump-to-top icon instead of edit text on headings
* Multiple parser extensions
* Implementation of dropdown menu
* Automatical loading of a template selector when creating new pages (namespace specific)
* Inline HTML5 audio play button for audio files, sized to fit a line of text
* User statistics (edit counts, page creations, first and last edit) usable in running text

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
| `$wgSGPackAudioSeekBar` | `false` | Show a seek bar on inline audio controls |
| `$wgSGPackUserStatisticsCacheExpiry` | `3600` | Seconds a page showing user statistics may stay in the parser cache; `0` never caches it |

### Inline audio

`<audioplay>` renders a small play/stop control that sits on the text baseline, instead of the browser's own
`<audio controls>` widget which is far too tall to use mid-sentence:

```
The gate makes a distinctive <audioplay file="Gate-dial.oga">dialling sound</audioplay> when activated.
```

| Attribute | Purpose |
|---|---|
| `file` | Required. File name, with or without the `File:` prefix |
| `seek` | `yes`/`no`, overriding `$wgSGPackAudioSeekBar` for this one control |

Tag content is the optional visible label; omit it for an icon on its own. The control is a plain link to the file
page until JavaScript upgrades it, so it still works without JavaScript, and it stays a link if the browser cannot
decode the format.

**Audio uploads have to be enabled first.** The MediaWiki default for `$wgFileExtensions` is
`png, gif, jpg, jpeg, webp`, so no audio file can be uploaded until it is extended, for example:

```php
$wgFileExtensions = array_merge( $wgFileExtensions, [ 'oga', 'opus', 'mp3', 'flac', 'wav' ] );
```

MP3 plays in every browser; Ogg Vorbis/Opus support in Safari is unreliable. Installing
[TimedMediaHandler](https://www.mediawiki.org/wiki/Extension:TimedMediaHandler) is recommended if the wiki relies
on Ogg, both because it provides transcodes and because it lets MediaWiki classify Ogg files as audio rather than
generic multimedia.

### User statistics

Five tags put contribution counts into running text. They are a port of Thomas Klein's
[`UserStatistics`](https://www.perrypedia.de/wiki/Benutzer:Bully1966/UserStatistics) extension, which
stargate-wiki.de used until the 1.43 migration dropped it. The syntax is unchanged, so existing pages keep
working.

| Tag | Renders |
|---|---|
| `<useredit>Name</useredit>` | Number of edits. Several names may be separated by `\|`, and their counts are added up |
| `<usercreate>Name</usercreate>` | Number of articles created in the main namespace |
| `<usercreate all>Name</usercreate>` | Number of pages created in any namespace |
| `<useredittopten>20</useredittopten>` | Ordered list of the 20 users with the most edits. No argument means 10, `all` means as many as the cap allows |
| `<usereditfirst>Name</usereditfirst>` | Date and time of the user's first edit |
| `<usereditlast>Name</usereditlast>` | Date and time of the user's most recent edit |

```
Benutzer <useredit>Bully1966</useredit> hat <usercreate>Bully1966</usercreate> Artikel erstellt.
```

An IP address works anywhere a user name does. A name that is neither an account on this wiki nor an IP
address renders an error message, as it did before. A known user with no edits at all renders nothing for
`<usereditfirst>`/`<usereditlast>`.

Five behavioural differences from the original are worth knowing:

* **Edit counts come from `user_editcount`**, the same number MediaWiki shows in preferences and
  Special:ListUsers, instead of counting rows in `revision` on every page view. Edits to pages that have since
  been deleted are therefore included, which they were not before.
* **Page creations are revisions with no parent revision**, which is how MediaWiki itself identifies them (the
  page-creation filter on Special:Contributions). Redirects and deleted pages are excluded, as before.
* **`<useredittopten>` is capped at 500 entries**, including in `all` mode. The original emitted one list item
  per account on the wiki, which does not end well on a large one.
* **Accounts hidden by a suppressing block never appear in `<useredittopten>`.** The list is rendered once and
  served to every reader, so it cannot depend on who is allowed to see the name.
* **Pages using these tags stay in the parser cache**, for `$wgSGPackUserStatisticsCacheExpiry` seconds. The
  original disabled the parser cache for any page carrying a counter; set the value to `0` to get that back.
  Counts are therefore up to an hour stale by default, which is the trade for not re-running the queries on
  every view.

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
