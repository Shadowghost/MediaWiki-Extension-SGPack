<?php

/**
 * @file
 * @ingroup Extensions
 * @author Shadowghost
 */

namespace MediaWiki\Extension\SGPack;

use MediaWiki\Html\Html;
use MediaWiki\Language\Language;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Contribution counters usable inside running text.
 *
 * A port of Thomas Klein's UserStatistics extension (2005-2006, GPL-2.0-or-later),
 * which stargate-wiki.de used until the MediaWiki 1.43 migration dropped it:
 *
 *   <useredit>Name[|Name…]</useredit>   edits, summed over every name given
 *   <usercreate [all]>Name</usercreate> pages created, main namespace or all
 *   <useredittopten>[n|all]</useredittopten>  the n busiest editors, as a list
 *   <usereditfirst>Name</usereditfirst> date of the first edit
 *   <usereditlast>Name</usereditlast>   date of the most recent edit
 *
 * The syntax is kept verbatim so existing wikitext keeps working; nothing else of
 * the original survives. It hand-built SQL against `rev_user`/`rev_user_text`,
 * which actor migration removed in 1.34, called `Parser::disableCache()`, which no
 * longer exists, and reached for `$wgUser->getSkin()`. Four notes on what replaced
 * what:
 *
 * - **Edits** come from `user_editcount` via UserEditTracker, the same number
 *   MediaWiki reports in preferences and Special:ListUsers, rather than a
 *   `COUNT(*)` over `revision` per page view. Anonymous editors have no user row
 *   to hold that field, so those are still counted by actor.
 * - **Creations** are `rev_parent_id = 0` rows, the marker core itself uses for
 *   "this revision created the page" (Special:Contributions' page-creation
 *   filter). The original ran a `MIN(rev_id)`-per-page subquery over the whole
 *   `revision` table — and a temporary HEAP table on MySQL 4.0 — to derive the
 *   same fact.
 * - **The top list** reads `user_editcount` from the `user` table rather than
 *   aggregating `revision` by user on every parse, which would be a full-table
 *   `GROUP BY`. Accounts hidden by a suppressing block are excluded, because this
 *   list renders into a page every reader sees.
 * - **Caching** is bounded, not disabled: see applyCacheExpiry().
 */
class UserStatistics {

	/**
	 * Length of <useredittopten> when it is given no argument.
	 *
	 * Ten, as the tag name says. The original fetched eleven rows here, to
	 * compensate for the anonymous-edits row its `GROUP BY rev_user` query
	 * produced and then skipped, and rendered whatever was left — so an
	 * unparameterised "top ten" listed eleven users on a wiki with no anonymous
	 * edits. Reading the user table cannot produce that row at all.
	 */
	private const DEFAULT_TOP = 10;

	/**
	 * Hard ceiling on the length of <useredittopten>, including its `all` mode.
	 *
	 * `all` meant "no LIMIT", which renders one list item per account on the wiki
	 * into a single page. The cap keeps that from becoming a way to make any page
	 * unservable.
	 */
	private const MAX_TOP = 500;

	/**
	 * Values of <usercreate>'s `all` attribute that mean "main namespace only".
	 *
	 * The attribute is a bare flag — `<usercreate all>` — and a bare attribute
	 * arrives with an empty value, so emptiness has to mean "on" for the original
	 * syntax to keep working. That leaves a template writing
	 * `all="{{{all|}}}"` unable to switch it off by passing nothing, which is what
	 * these explicit spellings are for.
	 */
	private const FALSY = [ 'no', 'false', '0' ];

	/**
	 * Expand template parameters and templates in raw tag input.
	 *
	 * setHook() callbacks receive tag content and attribute values unexpanded, so
	 * a username written as `<useredit>{{{1}}}</useredit>` inside a template
	 * arrives literally as "{{{1}}}". replaceVariables() rather than
	 * recursiveTagParse(): the result is a username, and parsing it as wikitext
	 * would turn it into HTML.
	 *
	 * @param string|null $text Raw tag content or attribute value
	 * @param Parser $parser
	 * @param PPFrame $frame
	 *
	 * @return string
	 */
	private static function expand( $text, $parser, $frame ) {
		if ( $text === null ) {
			return '';
		}

		return trim( $parser->replaceVariables( $text, $frame ) );
	}

	/**
	 * @param string $message Message key
	 * @param mixed ...$params Message parameters
	 *
	 * @return string HTML
	 * @return-taint escaped
	 */
	private static function error( $message, ...$params ) {
		return Html::element(
			'strong',
			[ 'class' => 'error' ],
			// Content language: this lands in the parser cache, so it must not vary
			// with the viewing user's language.
			wfMessage( $message, ...$params )->inContentLanguage()->text()
		);
	}

	/**
	 * Language to format numbers and dates in.
	 *
	 * The page's language, not the viewer's: the output is stored in the parser
	 * cache, which these tags do not vary by user.
	 *
	 * @param Parser $parser
	 *
	 * @return Language
	 */
	private static function language( $parser ) {
		return $parser->getTargetLanguage();
	}

	/**
	 * Bound how long these numbers may be served from the parser cache.
	 *
	 * The original called Parser::disableCache() in every one of its tags. That
	 * method is long gone, and its successor — updateCacheExpiry( 0 ) — would drop
	 * every page carrying a counter out of the parser cache entirely.
	 *
	 * Nothing here depends on who is reading, so the page stays cacheable; it just
	 * has to expire, or a count would sit in the cache until the page itself is
	 * next edited. $wgSGPackUserStatisticsCacheExpiry set to 0 restores the old
	 * always-uncached behaviour.
	 *
	 * @param Parser $parser
	 */
	private static function applyCacheExpiry( $parser ) {
		$expiry = (int)MediaWikiServices::getInstance()
			->getMainConfig()
			->get( 'SGPackUserStatisticsCacheExpiry' );

		// updateCacheExpiry() keeps the lowest value it is given, so a negative
		// number would be indistinguishable from 0, i.e. uncacheable.
		$parser->getOutput()->updateCacheExpiry( max( 0, $expiry ) );
	}

	/**
	 * Resolve a name from wikitext to somebody the statistics can be read for.
	 *
	 * @param string $name
	 *
	 * @return UserIdentity|null Null if the name is neither an account on this
	 *   wiki nor an IP address
	 */
	private static function resolveUser( $name ) {
		$services = MediaWikiServices::getInstance();

		// Before the account lookup, not after: RIGOR_VALID rejects names that look
		// like IP addresses, so newFromName() below cannot resolve one. Anonymous
		// editors have no user row, but they do have an actor, which is all the
		// queries here need. sanitizeIP() matches how the actor table stores IPv6.
		if ( $services->getUserNameUtils()->isIP( $name ) ) {
			return UserIdentityValue::newAnonymous( (string)IPUtils::sanitizeIP( $name ) );
		}

		$user = $services->getUserFactory()->newFromName( $name );

		// isRegistered() loads the user row; this is User::idFromName() != 0, which
		// is how the original decided a name was unknown.
		return $user && $user->isRegistered() ? $user : null;
	}

	/**
	 * @param UserIdentity $user
	 * @param IReadableDatabase $dbr
	 *
	 * @return int|null Null if the user has never been recorded as acting on this wiki
	 */
	private static function actorId( $user, $dbr ) {
		return MediaWikiServices::getInstance()->getActorNormalization()->findActorId( $user, $dbr );
	}

	/**
	 * @return IReadableDatabase
	 */
	private static function replica() {
		return MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
	}

	/**
	 * @param UserIdentity $user
	 *
	 * @return int
	 */
	private static function editCount( $user ) {
		if ( $user->isRegistered() ) {
			// user_editcount, initialised from the revision table by core on first
			// use. Null only for anonymous users, which cannot reach this branch.
			return (int)MediaWikiServices::getInstance()->getUserEditTracker()->getUserEditCount( $user );
		}

		$dbr = self::replica();
		$actorId = self::actorId( $user, $dbr );
		if ( !$actorId ) {
			return 0;
		}

		return (int)$dbr->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'revision' )
			->where( [ 'rev_actor' => $actorId ] )
			->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * Number of still-existing, non-redirect pages whose first revision is theirs.
	 *
	 * Joining `page` is what restricts this to pages that still exist, since a
	 * deleted page's revisions move to `archive`. The original had the same
	 * property, by way of the same join.
	 *
	 * @param UserIdentity $user
	 * @param bool $allNamespaces Count every namespace instead of only NS_MAIN
	 *
	 * @return int
	 */
	private static function creationCount( $user, $allNamespaces ) {
		$dbr = self::replica();
		$actorId = self::actorId( $user, $dbr );
		if ( !$actorId ) {
			return 0;
		}

		$conds = [
			'rev_actor' => $actorId,
			// No parent revision: this revision created the page.
			'rev_parent_id' => 0,
			'page_is_redirect' => 0,
		];
		if ( !$allNamespaces ) {
			$conds['page_namespace'] = NS_MAIN;
		}

		return (int)$dbr->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'revision' )
			->join( 'page', null, 'rev_page = page_id' )
			->where( $conds )
			->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * Timestamp of a user's first or most recent edit.
	 *
	 * UserEditTracker has getFirstEditTimestamp()/getLatestEditTimestamp(), but
	 * both return false for anonymous users, and <usereditfirst> accepts an IP.
	 * This is the same query core runs for registered users, by actor.
	 *
	 * @param UserIdentity $user
	 * @param string $sort SelectQueryBuilder::SORT_ASC or ::SORT_DESC
	 *
	 * @return string|null MediaWiki timestamp, or null if the user has no edits
	 */
	private static function editTimestamp( $user, $sort ) {
		$dbr = self::replica();
		$actorId = self::actorId( $user, $dbr );
		if ( !$actorId ) {
			return null;
		}

		$timestamp = $dbr->newSelectQueryBuilder()
			->select( 'rev_timestamp' )
			->from( 'revision' )
			// Ordering on the rev_actor_timestamp index, rather than MIN()/MAX() over
			// every revision the user has ever made
			->orderBy( 'rev_timestamp', $sort )
			->where( [ 'rev_actor' => $actorId ] )
			->caller( __METHOD__ )
			->fetchField();

		if ( !$timestamp ) {
			return null;
		}

		// Not every backend stores rev_timestamp in TS_MW
		return ConvertibleTimestamp::convert( TS_MW, $timestamp );
	}

	/**
	 * <useredit>Name[|Name…]</useredit> - edits, summed over every name given.
	 *
	 * @param string|null $input
	 * @param array $args
	 * @param Parser $parser
	 * @param PPFrame $frame
	 *
	 * @return string
	 */
	public static function renderUserEdit( $input, $args, $parser, $frame ) {
		self::applyCacheExpiry( $parser );

		$names = self::expand( $input, $parser, $frame );
		if ( $names === '' ) {
			return self::error( 'sgpack-userstats-nouser' );
		}

		$total = 0;
		foreach ( explode( '|', $names ) as $name ) {
			$name = trim( $name );
			// Tolerate a trailing or doubled separator rather than reporting the
			// empty string as an unknown user
			if ( $name === '' ) {
				continue;
			}

			$user = self::resolveUser( $name );
			if ( !$user ) {
				return self::error( 'sgpack-userstats-unknownuser', $name );
			}

			$total += self::editCount( $user );
		}

		return htmlspecialchars( self::language( $parser )->formatNum( $total ) );
	}

	/**
	 * <usercreate [all]>Name</usercreate> - pages created.
	 *
	 * @param string|null $input
	 * @param array $args
	 * @param Parser $parser
	 * @param PPFrame $frame
	 *
	 * @return string
	 */
	public static function renderUserCreate( $input, $args, $parser, $frame ) {
		self::applyCacheExpiry( $parser );

		$name = self::expand( $input, $parser, $frame );
		if ( $name === '' ) {
			return self::error( 'sgpack-userstats-nouser' );
		}

		$user = self::resolveUser( $name );
		if ( !$user ) {
			return self::error( 'sgpack-userstats-unknownuser', $name );
		}

		$allNamespaces = false;
		if ( isset( $args['all'] ) ) {
			$allNamespaces = !in_array(
				strtolower( self::expand( $args['all'], $parser, $frame ) ),
				self::FALSY,
				true
			);
		}

		return htmlspecialchars(
			self::language( $parser )->formatNum( self::creationCount( $user, $allNamespaces ) )
		);
	}

	/**
	 * <usereditfirst>Name</usereditfirst> - date of the user's first edit.
	 *
	 * @param string|null $input
	 * @param array $args
	 * @param Parser $parser
	 * @param PPFrame $frame
	 *
	 * @return string
	 */
	public static function renderFirstEdit( $input, $args, $parser, $frame ) {
		return self::renderEditDate( $input, $parser, $frame, SelectQueryBuilder::SORT_ASC );
	}

	/**
	 * <usereditlast>Name</usereditlast> - date of the user's most recent edit.
	 *
	 * @param string|null $input
	 * @param array $args
	 * @param Parser $parser
	 * @param PPFrame $frame
	 *
	 * @return string
	 */
	public static function renderLastEdit( $input, $args, $parser, $frame ) {
		return self::renderEditDate( $input, $parser, $frame, SelectQueryBuilder::SORT_DESC );
	}

	/**
	 * @param string|null $input
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @param string $sort SelectQueryBuilder::SORT_ASC or ::SORT_DESC
	 *
	 * @return string
	 */
	private static function renderEditDate( $input, $parser, $frame, $sort ) {
		self::applyCacheExpiry( $parser );

		$name = self::expand( $input, $parser, $frame );
		if ( $name === '' ) {
			return self::error( 'sgpack-userstats-nouser' );
		}

		$user = self::resolveUser( $name );
		if ( !$user ) {
			return self::error( 'sgpack-userstats-unknownuser', $name );
		}

		$timestamp = self::editTimestamp( $user, $sort );
		if ( $timestamp === null ) {
			// A known user with no edits has no first or last edit date. Rendering
			// nothing is what the original did, and it keeps the tag usable in a
			// sentence that reads correctly when empty.
			return '';
		}

		// adj = false: adjusting to the reader's timezone preference would put a
		// per-user value into an entry shared by every reader, so the date is shown
		// in the wiki's timezone.
		return htmlspecialchars( self::language( $parser )->timeanddate( $timestamp, false ) );
	}

	/**
	 * <useredittopten>[n|all]</useredittopten> - the busiest editors, as a list.
	 *
	 * @param string|null $input
	 * @param array $args
	 * @param Parser $parser
	 * @param PPFrame $frame
	 *
	 * @return string HTML
	 * @return-taint escaped
	 */
	public static function renderTopEditors( $input, $args, $parser, $frame ) {
		self::applyCacheExpiry( $parser );

		$argument = self::expand( $input, $parser, $frame );
		if ( $argument === '' ) {
			$limit = self::DEFAULT_TOP;
		} elseif ( strtolower( $argument ) === 'all' ) {
			$limit = self::MAX_TOP;
		} elseif ( preg_match( '/^\d+$/', $argument ) && (int)$argument > 0 ) {
			$limit = min( (int)$argument, self::MAX_TOP );
		} else {
			// Anything non-numeric is an error rather than a silent "all", so a typo
			// cannot produce a list of the entire wiki
			return self::error( 'sgpack-userstats-badcount', $argument );
		}

		$services = MediaWikiServices::getInstance();
		$dbr = self::replica();

		$rows = $dbr->newSelectQueryBuilder()
			->select( [ 'user_name', 'user_editcount' ] )
			->from( 'user' )
			->where( $dbr->expr( 'user_editcount', '>', 0 ) )
			// Accounts hidden by a suppressing block. Core keeps them out of
			// Special:ListUsers for anyone without the hideuser right; this list is
			// rendered once and served to everybody, so it excludes them outright.
			->andWhere( $services->getHideUserUtils()->getExpression( $dbr ) )
			// user_name breaks ties, so that equal edit counts do not reorder
			// between parses
			->orderBy( [ 'user_editcount DESC', 'user_name ASC' ] )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchResultSet();

		$userPages = [];
		$userTalkPages = [];
		$names = [];
		foreach ( $rows as $row ) {
			$userPages[$row->user_name] = Title::makeTitle( NS_USER, $row->user_name );
			$userTalkPages[$row->user_name] = Title::makeTitle( NS_USER_TALK, $row->user_name );
			$names[$row->user_name] = (int)$row->user_editcount;
		}

		if ( !$names ) {
			return '';
		}

		// One existence query for the whole list instead of two per entry, which at
		// the maximum length would be a thousand of them
		$linkBatch = $services->getLinkBatchFactory()->newLinkBatch();
		foreach ( $userPages as $name => $userPage ) {
			$linkBatch->addObj( $userPage );
			$linkBatch->addObj( $userTalkPages[$name] );
		}
		$linkBatch->setCaller( __METHOD__ );
		$linkBatch->execute();

		$linkRenderer = $services->getLinkRenderer();
		$language = self::language( $parser );
		// Core's own labels for these two links, in the content language for the
		// same reason as error()
		$talkLabel = wfMessage( 'talkpagelinktext' )->inContentLanguage()->text();
		$contribsLabel = wfMessage( 'contribslink' )->inContentLanguage()->text();

		$items = '';
		foreach ( $names as $name => $editCount ) {
			$userPage = $userPages[$name];
			$links = $linkRenderer->makeLink( $userPage, (string)$name )
				. ' (' . $linkRenderer->makeLink( $userTalkPages[$name], $talkLabel )
				. ' | ' . $linkRenderer->makeKnownLink(
					SpecialPage::getTitleFor( 'Contributions', (string)$name ),
					$contribsLabel
				)
				. ') - ' . $language->formatNum( $editCount );

			// rawElement, not element: $links is markup built by LinkRenderer, which
			// escapes the names it is given. Nothing unescaped from wikitext reaches
			// this string — tag-hook output is spliced into the page as-is.
			$items .= Html::rawElement( 'li', [], $links );
		}

		// @phan-suppress-next-line SecurityCheck-XSS LinkRenderer escapes every label
		return Html::rawElement( 'ol', [ 'class' => 'mw-sgpack-userstats-topeditors' ], $items );
	}
}
