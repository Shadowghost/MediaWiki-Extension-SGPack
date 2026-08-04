/* eslint-env node */
module.exports = function ( grunt ) {
	// eslint is not run through grunt: grunt-eslint 26 depends on eslint ^9, which
	// only reads flat config (eslint.config.js), while eslint-config-wikimedia
	// 0.32.x still ships eslintrc-style configs. `npm test` runs eslint directly
	// against the eslint 8 this project actually resolves.
	grunt.loadNpmTasks( 'grunt-banana-checker' );

	grunt.initConfig( {
		banana: {
			all: 'i18n/'
		}
	} );

	grunt.registerTask( 'test', [ 'banana' ] );
	grunt.registerTask( 'default', 'test' );
};
