<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['suppress_issue_types'] = array_merge( $cfg['suppress_issue_types'] ?? [], [
	// 15 hits, every one an empty() test on a parser-function parameter that has a
	// default. Rewriting them as `=== ''` is *not* equivalent: empty( '0' ) is
	// true while '0' === '' is false, so a literal "0" arriving from wikitext
	// would change meaning at every site — a <sort2> separator of "0", a carray
	// key of "0", a page title of "0", an #in mode of "0".
	//
	// That makes satisfying this rule a behaviour change for existing wiki
	// content rather than a cleanup, so it is suppressed deliberately. Revisit
	// per call site if the intended semantics for "0" are ever established;
	// IMPROVEMENT_PLAN.md records this alongside the related decision not to
	// change the falsy-parameter loop termination in CacheArray.
	'MediaWikiNoEmptyIfDefined',
] );

return $cfg;
