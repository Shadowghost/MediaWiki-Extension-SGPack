<?php

/**
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\SGPack\Tests\Structure;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Structural checks on extension.json, the i18n files and the magic-word file.
 *
 * These deliberately need neither a MediaWiki install nor a database, so they
 * run under `composer test` in plain CI as well as inside MediaWiki's own
 * PHPUnit suite. The cases exist because this extension has shipped every one of
 * these defects at least once: a ResourceLoader module referenced from PHP after
 * it was deleted from extension.json, a message used by code but never defined,
 * and a parser function registered without its magic word.
 *
 * @coversNothing
 */
class SGPackStructureTest extends TestCase {

	private const ROOT = __DIR__ . '/../../..';

	/**
	 * @param string $relative
	 *
	 * @return array
	 */
	private static function readJson( $relative ) {
		$path = self::ROOT . '/' . $relative;
		$raw = file_get_contents( $path );
		if ( $raw === false ) {
			throw new RuntimeException( "Could not read $relative" );
		}
		$decoded = json_decode( $raw, true );
		if ( !is_array( $decoded ) ) {
			throw new RuntimeException( "$relative is not valid JSON: " . json_last_error_msg() );
		}
		return $decoded;
	}

	/**
	 * @return string[] Absolute paths of the extension's PHP sources
	 */
	private static function phpSources() {
		return glob( self::ROOT . '/includes/*.php' ) ?: [];
	}

	public function testExtensionJsonHasTheExpectedShape() {
		$ext = self::readJson( 'extension.json' );

		$this->assertSame( 2, $ext['manifest_version'] ?? null, 'manifest_version must be 2' );
		foreach ( [ 'name', 'requires', 'AutoloadNamespaces', 'HookHandlers', 'Hooks' ] as $key ) {
			$this->assertArrayHasKey( $key, $ext );
		}

		$this->assertMatchesRegularExpression(
			'/^>= \d+\.\d+\.\d+$/',
			$ext['requires']['MediaWiki'],
			'The declared MediaWiki requirement should be a concrete lower bound'
		);
	}

	public function testAutoloadNamespacesPointAtRealDirectories() {
		$ext = self::readJson( 'extension.json' );

		foreach ( $ext['AutoloadNamespaces'] as $namespace => $dir ) {
			$this->assertDirectoryExists(
				self::ROOT . '/' . $dir,
				"AutoloadNamespaces maps $namespace to a directory that does not exist"
			);
		}
	}

	public function testEveryHookUsesADeclaredHandler() {
		$ext = self::readJson( 'extension.json' );
		$declared = array_keys( $ext['HookHandlers'] );

		foreach ( $ext['Hooks'] as $hook => $handlers ) {
			foreach ( (array)$handlers as $handler ) {
				$this->assertContains(
					$handler,
					$declared,
					"Hook $hook refers to undeclared HookHandler '$handler'"
				);
			}
		}
	}

	public function testEveryHookHandlerClassIsDefined() {
		$ext = self::readJson( 'extension.json' );

		foreach ( $ext['HookHandlers'] as $name => $spec ) {
			$class = $spec['class'];
			$short = substr( strrchr( $class, '\\' ), 1 );
			$file = self::ROOT . '/includes/' . $short . '.php';

			$this->assertFileExists( $file, "HookHandler '$name' has no file for $class" );
			$this->assertStringContainsString(
				"class $short",
				file_get_contents( $file ),
				"$file does not declare class $short"
			);
		}
	}

	public function testEveryResourceModuleFileExists() {
		$ext = self::readJson( 'extension.json' );
		$base = self::ROOT . '/' . $ext['ResourceFileModulePaths']['localBasePath'];

		foreach ( $ext['ResourceModules'] as $module => $spec ) {
			foreach ( [ 'scripts', 'styles' ] as $key ) {
				foreach ( $spec[$key] ?? [] as $file ) {
					$this->assertFileExists(
						$base . '/' . $file,
						"Module $module lists $file, which does not exist"
					);
				}
			}
		}
	}

	/**
	 * The regression this exists for: SGHTML kept calling
	 * addModuleStyles( 'ext.sgPack.styles' ) after that module was deleted from
	 * extension.json, which no other check caught.
	 */
	public function testEveryModuleReferencedFromPhpIsDeclared() {
		$ext = self::readJson( 'extension.json' );
		$declared = array_keys( $ext['ResourceModules'] );

		$referenced = [];
		foreach ( self::phpSources() as $file ) {
			preg_match_all(
				"/addModules?(?:Styles)?\(\s*'([^']+)'/",
				file_get_contents( $file ),
				$matches
			);
			foreach ( $matches[1] as $module ) {
				$referenced[$module] = basename( $file );
			}
		}

		$this->assertNotEmpty( $referenced, 'Expected at least one module to be loaded from PHP' );
		foreach ( $referenced as $module => $file ) {
			$this->assertContains(
				$module,
				$declared,
				"$file loads ResourceLoader module '$module', which extension.json does not define"
			);
		}
	}

	public function testModuleMessagesAreDefined() {
		$ext = self::readJson( 'extension.json' );
		$english = self::readJson( 'i18n/en.json' );

		foreach ( $ext['ResourceModules'] as $module => $spec ) {
			foreach ( $spec['messages'] ?? [] as $message ) {
				$this->assertArrayHasKey(
					$message,
					$english,
					"Module $module requests message '$message', which en.json does not define"
				);
			}
		}
	}

	public function testConfigDescriptionMessagesAreDefined() {
		$ext = self::readJson( 'extension.json' );
		$english = self::readJson( 'i18n/en.json' );
		$qqq = self::readJson( 'i18n/qqq.json' );

		$this->assertArrayHasKey( 'config_prefix', $ext );

		foreach ( $ext['config'] as $setting => $spec ) {
			$message = $spec['descriptionmsg'] ?? null;
			$this->assertNotNull( $message, "Config $setting has no descriptionmsg" );
			$this->assertArrayHasKey( $message, $english, "en.json is missing $message" );
			$this->assertArrayHasKey( $message, $qqq, "qqq.json is missing $message" );
		}
	}

	/**
	 * Every message the PHP asks for by literal name must exist.
	 *
	 * `sghtml-top` was used by SGHTML and defined nowhere, so the tooltip
	 * rendered as its own key. banana does not catch this: it validates that the
	 * message files agree with each other, not that the code's messages exist.
	 */
	public function testMessagesUsedInPhpAreDefined() {
		$english = self::readJson( 'i18n/en.json' );

		foreach ( self::phpSources() as $file ) {
			preg_match_all( "/wfMessage\(\s*'([^']+)'/", file_get_contents( $file ), $matches );
			foreach ( $matches[1] as $message ) {
				// Messages owned by MediaWiki core or another extension
				if ( !str_starts_with( $message, 'sgpack' )
					&& !str_starts_with( $message, 'sghtml' )
					&& !str_starts_with( $message, 'ddinsert' )
					&& !str_starts_with( $message, 'newarticle' )
					&& !str_starts_with( $message, 'addwhosonline' )
					&& !str_starts_with( $message, 'parseradds' )
				) {
					continue;
				}
				$this->assertArrayHasKey(
					$message,
					$english,
					basename( $file ) . " uses message '$message', which en.json does not define"
				);
			}
		}
	}

	public function testEnglishAndQqqDefineTheSameKeys() {
		$english = self::readJson( 'i18n/en.json' );
		$qqq = self::readJson( 'i18n/qqq.json' );
		unset( $english['@metadata'], $qqq['@metadata'] );

		$this->assertSame(
			[],
			array_keys( array_diff_key( $english, $qqq ) ),
			'Messages defined in en.json but undocumented in qqq.json'
		);
		$this->assertSame(
			[],
			array_keys( array_diff_key( $qqq, $english ) ),
			'Messages documented in qqq.json but not defined in en.json'
		);
	}

	/**
	 * A parser function needs a setFunctionHook() call *and* a magic word;
	 * without the magic word the function is simply unreachable. Tag hooks
	 * registered with setHook() do not need one.
	 */
	public function testEveryParserFunctionHasAMagicWord() {
		$hooks = file_get_contents( self::ROOT . '/includes/Hooks.php' );
		preg_match_all( "/setFunctionHook\(\s*'([^']+)'/", $hooks, $matches );
		$functions = $matches[1];

		$this->assertNotEmpty( $functions, 'Expected Hooks.php to register parser functions' );

		require self::ROOT . '/SGPack.i18n.magic.php';
		// phpcs:ignore MediaWiki.VariableAnalysis.MisleadingGlobalNames.UndeclaredGlobalVariableName
		$this->assertIsArray( $magicWords['en'] ?? null, 'No English magic words defined' );

		foreach ( $functions as $function ) {
			$this->assertArrayHasKey(
				$function,
				$magicWords['en'],
				"Parser function '$function' is registered but has no entry in \$magicWords['en']"
			);
		}
	}
}
