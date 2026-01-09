<?php
/**
 * Redirect Manager - PHP Dispatcher
 *
 * This file should be called from CMS Builder's 404 error handler or from a
 * custom ErrorDocument directive. It checks if the requested URL has a matching
 * redirect rule and performs the redirect if found.
 *
 * Installation Options:
 *
 * Option 1: .htaccess ErrorDocument (Recommended)
 *   Add to your .htaccess file (adjust path to match your installation):
 *   ErrorDocument 404 /cmsb/plugins/redirectManager/dispatcher.php
 *
 * Option 2: PHP include in existing 404 handler
 *   Add at the top of your 404.php:
 *   include_once '/path/to/cmsb/plugins/redirectManager/dispatcher.php';
 *
 * Option 3: CMS Builder integration
 *   The plugin can hook into CMS Builder's 404 handling automatically
 *   when configured in plugin settings.
 */

// Don't run from command-line
if (php_sapi_name() === 'cli') {
	exit;
}

// Find CMS Builder installation
$dirsToCheck = [
	__DIR__ . '/../../',                      // Standard location: plugins inside admin folder
	__DIR__ . '/../../../',                   // Alternative: plugins in admin parent
	$_SERVER['DOCUMENT_ROOT'] . '/cmsb/',     // Standard CMSB installation
];

$cmsFound = false;
foreach ($dirsToCheck as $dir) {
	$initFile = realpath($dir . 'lib/init.php');
	if ($initFile && file_exists($initFile)) {
		// Only include if not already loaded
		if (!defined('CMS_VERSION')) {
			require_once $initFile;
		}
		$cmsFound = true;
		break;
	}
}

if (!$cmsFound) {
	// CMS Builder not found, can't process redirects
	// Let the normal 404 handling continue
	return;
}

// Load plugin functions
require_once __DIR__ . '/redirectManager_functions.php';

// Get the requested URL
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$requestPath = parse_url($requestUri, PHP_URL_PATH);

// Clean up the path
$requestPath = '/' . ltrim($requestPath, '/');

// Load plugin settings
$settings = \RedirectManager\loadPluginSettings();

// Check if plugin is enabled
if (empty($settings['enabled'])) {
	return;
}

// Find matching redirect
$redirect = \RedirectManager\findMatchingRedirect($requestPath);

if (!$redirect) {
	// No matching redirect found
	return;
}

// Record the hit if tracking is enabled
if (!empty($settings['trackHits'])) {
	\RedirectManager\recordHit($redirect['num']);
}

// Build the destination URL using the function that handles patterns and query strings
$destinationUrl = \RedirectManager\buildDestinationUrl($redirect, $requestUri);

// Ensure destination is absolute or starts with /
if (!preg_match('#^https?://#i', $destinationUrl) && $destinationUrl[0] !== '/') {
	$destinationUrl = '/' . $destinationUrl;
}

// Get redirect type
$redirectType = (int) ($redirect['redirectType'] ?? 301);
$validTypes = [301, 302, 307, 308];
if (!in_array($redirectType, $validTypes)) {
	$redirectType = 301;
}

// Perform the redirect
http_response_code($redirectType);
header('Location: ' . $destinationUrl, true, $redirectType);
header('X-Redirect-By: RedirectManager');
exit;
