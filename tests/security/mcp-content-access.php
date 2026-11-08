<?php
/**
 * Regression test for the 2.33.2 security fix.
 *
 * The MCP get_page_content tool resolved its `url` argument with
 * url_to_postid() and rendered post_content through the_content with no
 * status and no password check, so an unauthenticated caller could read
 * drafts, private posts and password-protected posts in full. Worse,
 * url_to_postid() resolves ?p=<id>, so every hidden post was reachable by
 * walking ID numbers -- no slug guessing needed.
 *
 * Only reachable when the MCP integration is enabled (default off), but
 * rest_permission_check() deliberately allows anonymous callers once it is,
 * so the content guards inside these handlers are the only protection.
 *
 * Found by an internal audit, not an outside report.
 *
 * This asserts both directions:
 *   - hidden posts (protected / private / draft) never leak, by permalink
 *     or by ?p=<id>, and never surface through either search handler
 *   - an ordinary published post IS still served
 *
 * It calls the public static handlers directly rather than going over HTTP,
 * so the result does not depend on route registration -- an earlier version
 * of this test wrote the wrong option name, the routes never registered,
 * every request 404'd and it reported a clean run. A test that cannot fail
 * is worse than no test.
 *
 * Run from the site root with WP-CLI -- see tests/security/README.md.
 * Exits non-zero on failure. Never shipped to wp.org: see EXCLUDE_PREFIXES
 * in deploy_release.py.
 */

$canaries = array(
	'protected' => 'CANARY-PASSWORDED-a91f33',
	'private'   => 'CANARY-PRIVATE-77cd20',
	'draft'     => 'CANARY-DRAFT-5b1e08',
);
$public_canary = 'CANARY-PUBLIC-OK-9a2f';

$ids = array();

$ids['protected'] = wp_insert_post( array(
	'post_title'    => 'Protected Report',
	'post_name'     => 'protected-report-test',
	'post_content'  => 'Behind a password. ' . $canaries['protected'],
	'post_status'   => 'publish',
	'post_password' => 'letmein',
	'post_type'     => 'post',
) );
$ids['private'] = wp_insert_post( array(
	'post_title'   => 'Private Report',
	'post_name'    => 'private-report-test',
	'post_content' => 'Private only. ' . $canaries['private'],
	'post_status'  => 'private',
	'post_type'    => 'post',
) );
$ids['draft'] = wp_insert_post( array(
	'post_title'   => 'Draft Report',
	'post_name'    => 'draft-report-test',
	'post_content' => 'Unfinished. ' . $canaries['draft'],
	'post_status'  => 'draft',
	'post_type'    => 'post',
) );
$public_id = wp_insert_post( array(
	'post_title'   => 'Ordinary Public Post',
	'post_name'    => 'ordinary-public-post-test',
	'post_content' => 'Anyone may read this. ' . $public_canary,
	'post_status'  => 'publish',
	'post_type'    => 'post',
) );

foreach ( array_merge( $ids, array( 'public' => $public_id ) ) as $what => $id ) {
	if ( is_wp_error( $id ) || ! $id ) {
		echo "FAILED to create the $what test post\n";
		exit( 1 );
	}
}

// Turn both modules on for the duration of the test, then put the option back.
$option = TWTAEO_AI_Ready::OPTION_KEY;
$saved  = get_option( $option );
$live   = is_array( $saved ) ? $saved : array();

$live['mcp_integration']  = 1;
$live['agent_search_api'] = 1;
update_option( $option, $live );

wp_set_current_user( 0 );

$settings = TWTAEO_AI_Ready::get_settings();
printf(
	"modules on: mcp=%d search=%d\n",
	(int) $settings['mcp_integration'],
	(int) $settings['agent_search_api']
);

$probe = new WP_REST_Request( 'GET', '/aeo/v1/mcp' );
printf(
	"rest_permission_check for an anonymous caller: %s\n\n",
	true === TWTAEO_AI_Ready::rest_permission_check( $probe ) ? 'ALLOWED' : 'denied'
);

$fail = array();

/**
 * Call an MCP tool as an anonymous caller and return the response as JSON.
 */
function twtaeo_test_mcp_call( $tool, array $args ) {
	$request = new WP_REST_Request( 'POST', '/aeo/v1/mcp' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body(
		wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => $tool,
					'arguments' => $args,
				),
			)
		)
	);
	$response = TWTAEO_AI_Ready::rest_mcp_endpoint( $request );

	return (string) wp_json_encode(
		$response instanceof WP_REST_Response ? $response->get_data() : $response
	);
}

echo "-- get_page_content by permalink: hidden posts must not leak --\n";
foreach ( $canaries as $what => $canary ) {
	$body = twtaeo_test_mcp_call( 'get_page_content', array( 'url' => get_permalink( $ids[ $what ] ) ) );
	$leak = false !== strpos( $body, $canary );
	printf( "  %-10s body leaked: %s\n", $what, $leak ? 'YES' : 'no' );
	if ( $leak ) {
		$fail[] = "permalink:$what";
	}
}

echo "-- get_page_content by ?p=<id>: enumeration must not work --\n";
foreach ( $canaries as $what => $canary ) {
	$body = twtaeo_test_mcp_call( 'get_page_content', array( 'url' => home_url( '/?p=' . $ids[ $what ] ) ) );
	$leak = false !== strpos( $body, $canary );
	printf( "  %-10s body leaked: %s\n", $what, $leak ? 'YES' : 'no' );
	if ( $leak ) {
		$fail[] = "enumeration:$what";
	}
}

echo "-- MCP search: must not act as a content oracle --\n";
foreach ( $canaries as $what => $canary ) {
	$body = twtaeo_test_mcp_call( 'search', array( 'q' => $canary ) );
	$hit  = false !== strpos( $body, 'Report' ) && false === strpos( $body, 'No results found' );
	printf( "  %-10s matched a hidden post: %s\n", $what, $hit ? 'YES' : 'no' );
	if ( $hit ) {
		$fail[] = "mcp-search:$what";
	}
}

echo "-- /aeo/v1/search: must not act as a content oracle --\n";
foreach ( $canaries as $what => $canary ) {
	$request = new WP_REST_Request( 'GET', '/aeo/v1/search' );
	$request->set_param( 'q', $canary );
	$response = TWTAEO_AI_Ready::rest_search( $request );
	$body     = (string) wp_json_encode(
		$response instanceof WP_REST_Response ? $response->get_data() : $response
	);
	$hit = false !== strpos( $body, 'Report' );
	printf( "  %-10s matched a hidden post: %s\n", $what, $hit ? 'YES' : 'no' );
	if ( $hit ) {
		$fail[] = "rest-search:$what";
	}
}

echo "-- an ordinary published post must STILL be served --\n";
$body = twtaeo_test_mcp_call( 'get_page_content', array( 'url' => get_permalink( $public_id ) ) );
if ( false !== strpos( $body, $public_canary ) ) {
	echo "  public post still served -- correct\n";
} else {
	echo "  REGRESSION: the ordinary public post is no longer served\n";
	$fail[] = 'regression:public-post';
}

// Clean up: restore the option exactly as it was, delete the test posts.
if ( false === $saved ) {
	delete_option( $option );
} else {
	update_option( $option, $saved );
}
foreach ( $ids as $id ) {
	wp_delete_post( $id, true );
}
wp_delete_post( $public_id, true );

echo "\n";
if ( empty( $fail ) ) {
	echo "RESULT: PASS -- nothing hidden exposed, public content intact\n";
	exit( 0 );
}

echo 'RESULT: FAIL -- ' . implode( ' | ', $fail ) . "\n";
exit( 1 );
