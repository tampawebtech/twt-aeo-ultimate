<?php
/**
 * Regression: the Publish Ready panel must never write alt text against a body
 * it is not looking at.
 *
 * What went wrong. Alt text is the one field written back into post_content,
 * and TWTAEO_Image_Optimizer rewrites the SAVED body by position: the first
 * <img>, the second, and so on. The panel reads the editor's live body, so it
 * can count images as they are pasted. Those two bodies diverge the instant
 * anything is unsaved.
 *
 * With an image inserted but not yet saved, the panel offered an alt field for
 * it, the write then rewrote the old saved body, which contained a different
 * image or none, and the saved body came back to the editor and replaced
 * everything on screen. The inserted image, and every other unsaved change,
 * was gone.
 *
 * Guarded now by an optimistic-concurrency check: a caller states the body it
 * believes is current, and anything writing into post_content is refused when
 * that does not match what is stored.
 *
 * Run: wp eval-file tests/security/publish-ready-content-loss.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$twtaeo_fails = 0;

/**
 * @param string $label
 * @param bool   $passed
 * @param string $detail
 */
function twtaeo_pr_check( $label, $passed, $detail = '' ) {
	global $twtaeo_fails;
	if ( ! $passed ) {
		$twtaeo_fails++;
	}
	printf(
		"  %-58s %s%s\n",
		$label,
		$passed ? 'ok' : '** FAIL **',
		$detail ? '  (' . $detail . ')' : ''
	);
}

$twtaeo_admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $twtaeo_admins ) {
	echo "No administrator to run as.\n";
	return;
}
wp_set_current_user( $twtaeo_admins[0]->ID );

// The saved body: one image, no alt.
$twtaeo_saved_body = '<p>Body text with enough words in it to look like a real page of content.</p>'
	. '<img src="http://example.test/one.jpg" class="wp-image-101" />';

$twtaeo_id = wp_insert_post( array(
	'post_title'   => 'TEMP publish-ready content-loss regression',
	'post_content' => $twtaeo_saved_body,
	'post_status'  => 'draft',
	'post_type'    => 'post',
) );

/**
 * @param string $route
 * @param array  $body
 * @return array
 */
function twtaeo_pr_call( $route, array $body ) {
	$request = new WP_REST_Request( 'POST', '/aeo/v1/publish-ready/' . $route );
	foreach ( $body as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$response = rest_do_request( $request );
	if ( $response->is_error() ) {
		return array( '_error' => $response->as_error()->get_error_code() );
	}
	return $response->get_data();
}

echo "\n-- an unsaved body must not be allowed to write alt text --\n";

// What the editor holds: a second image the database has never seen.
$twtaeo_live_body = $twtaeo_saved_body
	. '<p>A paragraph added in the editor.</p>'
	. '<img src="http://example.test/two.jpg" class="wp-image-102" />';

$twtaeo_result = twtaeo_pr_call( 'save', array(
	'post_id' => $twtaeo_id,
	'field'   => 'image_alt',
	'value'   => array( 'c1' => 'The second image' ),
	'content' => $twtaeo_live_body,
) );

twtaeo_pr_check(
	'write against an unsaved body is refused',
	isset( $twtaeo_result['_error'] ) && 'twtaeo_stale_content' === $twtaeo_result['_error'],
	$twtaeo_result['_error'] ?? 'no error returned'
);

$twtaeo_after = get_post( $twtaeo_id );
twtaeo_pr_check(
	'the stored body is untouched',
	$twtaeo_after->post_content === $twtaeo_saved_body
);

echo "\n-- generating alt text has the same guard --\n";

$twtaeo_result = twtaeo_pr_call( 'generate', array(
	'post_id' => $twtaeo_id,
	'field'   => 'image_alt',
	'content' => $twtaeo_live_body,
) );
twtaeo_pr_check(
	'generate against an unsaved body is refused',
	isset( $twtaeo_result['_error'] ) && 'twtaeo_stale_content' === $twtaeo_result['_error'],
	$twtaeo_result['_error'] ?? 'no error returned'
);
twtaeo_pr_check(
	'no AI call was reached, so nothing was billed',
	isset( $twtaeo_result['_error'] ) && 'twtaeo_stale_content' === $twtaeo_result['_error']
);

echo "\n-- the legitimate path still works --\n";

$twtaeo_result = twtaeo_pr_call( 'save', array(
	'post_id' => $twtaeo_id,
	'field'   => 'image_alt',
	'value'   => array( 'c0' => 'A described image' ),
	'content' => $twtaeo_saved_body,
) );
twtaeo_pr_check(
	'a matching body is accepted',
	! isset( $twtaeo_result['_error'] ),
	$twtaeo_result['_error'] ?? ''
);

$twtaeo_after = get_post( $twtaeo_id );
twtaeo_pr_check(
	'the alt text reached the content',
	false !== strpos( $twtaeo_after->post_content, 'alt="A described image"' )
);
twtaeo_pr_check(
	'the image itself survived',
	false !== strpos( $twtaeo_after->post_content, 'one.jpg' )
);
twtaeo_pr_check(
	'the body was not otherwise rewritten',
	false !== strpos( $twtaeo_after->post_content, 'Body text with enough words' )
);

echo "\n-- a caller that claims no body is trusted to mean the stored one --\n";
$twtaeo_result = twtaeo_pr_call( 'save', array(
	'post_id' => $twtaeo_id,
	'field'   => 'image_alt',
	'value'   => array( 'c0' => 'Updated again' ),
) );
twtaeo_pr_check(
	'omitting the body is accepted',
	! isset( $twtaeo_result['_error'] ),
	$twtaeo_result['_error'] ?? ''
);

wp_delete_post( $twtaeo_id, true );

echo "\n";
if ( $twtaeo_fails ) {
	echo "RESULT: FAIL -- {$twtaeo_fails} check(s) failed\n";
	exit( 1 );
}
echo "RESULT: PASS -- unsaved content cannot be overwritten\n";
