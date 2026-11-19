/**
 * Grunt tasks: `npm run readme` (or `npx grunt readme`) turns readme.txt, the file
 * WordPress.org reads, into README.md for GitHub. Edit readme.txt, never README.md.
 *
 * @param {Object} grunt Grunt.
 */
module.exports = function ( grunt ) {
	grunt.initConfig( {
		wp_readme_to_markdown: {
			readme: {
				files: {
					'README.md': 'readme.txt',
				},
			},
		},
	} );

	grunt.loadNpmTasks( 'grunt-wp-readme-to-markdown' );
	grunt.registerTask( 'readme', [ 'wp_readme_to_markdown' ] );
	grunt.registerTask( 'default', [ 'readme' ] );
};
