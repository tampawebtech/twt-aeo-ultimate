<?php
/**
 * Hostile-probe path patterns for Smart 404 Rescue (Gate 0).
 *
 * When a 404 request path matches anything here, the rescue pipeline exits
 * immediately: no fuzzy matching, no AI lookup, no admin notification, and
 * nothing written to the database. This is what keeps automated attack traffic
 * (typically the large majority of a site's 404s) from generating noise or cost.
 *
 * Matching is evaluated against the decoded request path (and, for `query`,
 * the query string). Everything is case-insensitive. Because this list is only
 * consulted on requests that ALREADY 404'd, matching a pattern never blocks a
 * real page — it only means "don't bother trying to rescue this one."
 *
 * Purely defensive. Safe to extend; admins can add their own patterns on top of
 * this baseline (stored as a single option, never one row per hit).
 *
 * @package TWTAEO_Connector
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(

	/*
	 * Substring matches against the request path (case-insensitive `stripos`).
	 * The fastest, highest-signal check — most probes hit a fixed known path.
	 */
	'contains' => array(

		// WordPress admin / auth surface probing.
		'wp-login', 'wp-admin', 'wp-signup', 'wp-register', 'xmlrpc.php',
		'wp-config', 'wp-content/debug.log', 'wp-json/wp/v2/users', 'author=',
		'wp-cron.php', 'wp-mail.php', 'wp-trackback.php', 'wlwmanifest.xml',

		// Config / secret / credential files.
		'.env', '.env.', 'config.php', 'configuration.php', 'settings.php',
		'web.config', 'database.yml', 'secrets', 'credentials', 'id_rsa',
		'.aws/', '.ssh/', '.htpasswd', '.htaccess', 'php.ini', '.npmrc', '.git-credentials',

		// Version-control / build metadata.
		'/.git', '/.svn', '/.hg', '/.bzr', 'composer.json', 'composer.lock',
		'package.json', 'yarn.lock', 'gruntfile', 'gulpfile', '.gitignore',

		// DB / admin tools.
		'phpmyadmin', 'phpmyadmin/', '/pma', 'adminer', 'mysql-admin',
		'dbadmin', '/sqlite', 'myadmin', 'phppgadmin',

		// Common shells / backdoors / RCE probes.
		'shell', 'c99', 'r57', 'wso', 'b374k', 'webshell', 'backdoor',
		'/eval', 'cmd.php', 'up.php', 'alfa', 'filemanager', 'wp-file-manager',
		'x.php', 'xx.php', 'test.php', 'info.php', 'phpinfo', '1.php', '2.php',

		// Other-CMS / other-platform probes (wrong stack = automated scan).
		'/administrator/', 'joomla', 'drupal', 'magento', 'typo3', 'bitrix',
		'/vendor/', '/node_modules/', '/cgi-bin/', '/actuator', '/telescope',
		'/solr', '/jenkins', '/struts', '/console', '/manager/html',

		// Vuln-scanner fingerprinting.
		'/wp-content/plugins/', // scanning plugin readme.txt for version CVEs
		'readme.html', 'license.txt', 'changelog.txt', 'wp-includes/',
	),

	/*
	 * Exact whole-path matches (normalized: lowercased, no surrounding slashes).
	 * These are generic install-location / admin-panel probes — a real page never
	 * lives at exactly these paths, and a 404 on one is a scanner looking for a
	 * second CMS, a backup, or an admin login. Matched only as the COMPLETE path
	 * so legitimate slugs like "/old-growth-forests" are unaffected.
	 */
	'exact_paths' => array(
		'admin', 'administrator', 'login', 'panel', 'dashboard',
		'wp', 'wordpress', 'wp1', 'wp2', 'blog2', 'cms', 'site', 'main',
		'old', 'new', 'backup', 'backups', 'bak', 'test', 'tests', 'demo',
		'dev', 'development', 'staging', 'stage', 'tmp', 'temp', 'archive',
		// Generic directory / asset paths — never a content page, so never a
		// redirect target (matched only as the COMPLETE path).
		'uploads', 'files', 'file', 'images', 'image', 'img', 'media',
		'assets', 'asset', 'static', 'data', 'docs', 'doc', 'cache', 'content',
	),

	/*
	 * File extensions that should never resolve on a normal WP page request.
	 * A 404 ending in one of these is a download/probe attempt, not a typo of a
	 * real page — so we skip rescue. (Legitimate assets are served by the web
	 * server and never reach this 404 path.)
	 */
	'extensions' => array(
		'php', 'php5', 'php7', 'phtml', 'asp', 'aspx', 'jsp', 'cgi', 'pl',
		'sql', 'bak', 'old', 'swp', 'save', 'orig', 'tmp', 'log', 'ini',
		'zip', 'tar', 'gz', 'tgz', 'rar', '7z', 'sql.gz', 'dump',
		'yml', 'yaml', 'sh', 'bash', 'env', 'key', 'pem', 'db', 'sqlite',
		'xml', 'xsl', 'dll', 'exe', 'cfg', 'conf', 'inc', 'lock', 'htaccess',
	),

	/*
	 * Regex patterns (case-insensitive) for structural attacks and admin-panel
	 * probing: path traversal, null-byte injection, user enumeration, protocol
	 * probes, and "admin" appearing as its own path segment (/admin, /admin/,
	 * /admin.php, /wp-content/admin.php) without catching slugs like /admin-tips.
	 */
	'regex' => array(
		'#(^|/)admin(/|\.|$)#i',   // admin as a path segment
		'#\.\./#',                 // path traversal
		'#\.\.%2f#i',              // encoded traversal
		'#%00#',                   // null byte
		'#(etc/passwd|proc/self|boot\.ini|win\.ini)#i', // classic LFI targets
		'#[?&]author=\d+#i',       // author/user enumeration
		'#[<>\'"();]#',            // reflected-XSS / injection characters in a path
		'#\b(union|select|concat|information_schema)\b#i', // SQLi in the URL
		'#php://|file://|data://|expect://#i',             // PHP stream wrappers
	),
);
