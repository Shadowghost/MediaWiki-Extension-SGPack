/**
 * Type declarations for the global surface this extension adds.
 *
 * `mw.SGPack` is not part of MediaWiki, so types-mediawiki does not know about
 * it and assigning to it is a type error without this augmentation. Declaring it
 * here rather than silencing the error keeps the public API written down in one
 * place — wiki content and gadgets call these three functions directly, so their
 * signatures are a compatibility surface.
 */

declare global {
	namespace mw {
		interface SGPackApi {
			/**
			 * Decode a percent-encoded payload, leaving a lone '%' intact.
			 */
			rawdecode( str: string ): string;

			/**
			 * Insert an encoded `pre+peri+post` payload at the cursor.
			 *
			 * Accepts undefined because callers read it straight out of a data
			 * attribute, which may be absent.
			 */
			insert( str: string | undefined ): void;

			/**
			 * Insert the payload held by the currently selected `<option>`.
			 */
			insertSelect( sel: HTMLSelectElement ): void;
		}

		// `let`, not `const`: ext.sgPack.js assigns this on load.
		let SGPack: SGPackApi;
	}
}

export {};
