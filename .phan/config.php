<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['suppress_issue_types'] = array_merge( $cfg['suppress_issue_types'] ?? [], [
	// Every hit is an empty() test on a parser-function parameter that has a
	// default. Rewriting them as `=== ''` is *not* equivalent: empty( '0' ) is
	// true while '0' === '' is false, so a literal "0" arriving from wikitext
	// would change meaning at every site — a <sort2> separator of "0", a carray
	// key of "0", a page title of "0", an #in mode of "0".
	//
	// Satisfying this rule would therefore change behaviour for existing wiki
	// content, so it is suppressed deliberately. Revisit per call site if the
	// intended semantics for "0" are ever established.
	'MediaWikiNoEmptyIfDefined',
] );

return $cfg;
