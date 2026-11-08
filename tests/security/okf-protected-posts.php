<?php
/**
 * Regression test for the 2.33.1 security fix.
 *
 * Password-protected posts keep post_status = 'publish', so a status-only
 * query filter still matches them. Before 2.33.1 the /okf/ bundle and
 * llms-full.txt served them in full to anyone. Reported by kta1kri;
 * GHSA-73c3-vfgp-5g89.
 *
 * Creates a protected post and an ordinary one, renders every affected
 * surface, asserts the protected body never appears AND that the ordinary
 * post still does, then cleans up after itself.
 *
 * Run from the site root with WP-CLI -- see tests/security/README.md.
 * Exits non-zero on failure. Never shipped to wp.org: see EXCLUDE_PREFIXES
 * in deploy_release.py.
 */

$secret = 'SECRET-CANARY-BODY-7f3a91';
$normal = 'ORDINARY-PUBLIC-BODY-22b4';

$protected_id = wp_insert_post( array(
	'post_title'    => 'Members Only Report',
	'post_name'     => 'members-only-report',
	'post_content'  => "This paragraph is behind a password. $secret",
	'post_excerpt'  => "Excerpt leak check $secret",
	'post_status'   => 'publish',
	'post_password' => 'letmein',
	'post_type'     => 'post',
) );

$public_id = wp_insert_post( array(
	'post_title'   => 'Ordinary Public Post',
	'post_name'    => 'ordinary-public-post',
	'post_content' => "Anyone may read this. $normal",
	'post_status'  => 'publish',
	'post_type'    => 'post',
) );

if ( is_wp_error( $protected_id ) || is_wp_error( $public_id ) ) {
	echo "FAILED to create test posts\n";
	exit( 1 );
}

TWTAEO_OKF::flush_cache();
TWTAEO_Sitemap_Generator::flush_cache();

$surfaces = array(
	'/okf/posts/members-only-report.md' => (string) TWTAEO_OKF::render_path( 'posts/members-only-report.md' ),
	'/okf/index.md'                     => (string) TWTAEO_OKF::render_path( 'index.md' ),
	'/okf/posts/ordinary-public-post.md' => (string) TWTAEO_OKF::render_path( 'posts/ordinary-public-post.md' ),
	'llms-full.txt'                     => (string) TWTAEO_Sitemap_Generator::generate_llms(),
	'sitemap.xml'                       => (string) TWTAEO_Sitemap_Generator::generate(),
);

$fail = 0;

foreach ( $surfaces as $label => $body ) {
	$leaks_secret = ( '' !== $body && false !== strpos( $body, $secret ) );
	$names_slug   = ( '' !== $body && false !== strpos( $body, 'members-only-report' ) );
	printf(
		"%-36s len=%-7d secret_body=%-3s protected_slug=%-3s\n",
		$label,
		strlen( $body ),
		$leaks_secret ? 'YES' : 'no',
		$names_slug ? 'YES' : 'no'
	);
	if ( $leaks_secret ) {
		$fail++;
	}
}

echo "\n-- the protected route itself --\n";
$one = TWTAEO_OKF::render_path( 'posts/members-only-report.md' );
echo null === $one ? "render_path() returned NULL (404) -- correct\n" : "render_path() returned a document -- WRONG:\n" . substr( $one, 0, 300 ) . "\n";
if ( null !== $one ) {
	$fail++;
}

echo "\n-- the public post must still work --\n";
$ok = (string) TWTAEO_OKF::render_path( 'posts/ordinary-public-post.md' );
if ( '' !== $ok && false !== strpos( $ok, $normal ) ) {
	echo "public post still rendered in full -- correct\n";
} else {
	echo "REGRESSION: the ordinary public post no longer renders\n";
	$fail++;
}
$lf = (string) TWTAEO_Sitemap_Generator::generate_llms();
if ( false !== strpos( $lf, $normal ) ) {
	echo "llms-full.txt still carries the public post -- correct\n";
} else {
	echo "REGRESSION: llms-full.txt lost the public post\n";
	$fail++;
}

wp_delete_post( $protected_id, true );
wp_delete_post( $public_id, true );
TWTAEO_OKF::flush_cache();
TWTAEO_Sitemap_Generator::flush_cache();

echo "\n" . ( 0 === $fail ? "RESULT: PASS -- nothing protected leaked\n" : "RESULT: FAIL -- $fail leak(s)\n" );

exit( 0 === $fail ? 0 : 1 );
