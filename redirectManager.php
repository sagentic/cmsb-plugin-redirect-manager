<?php
/*
Plugin Name: Redirect Manager
Description: Manage 301/302 URL redirects through the admin UI instead of manually editing .htaccess files
Version: 1.00
CMS Version Required: 3.50
Author: Sagentic Web Design
Author URI: https://www.sagentic.com
*/

namespace RedirectManager;

// Don't run from command-line
if (\inCLI()) {
	return;
}

// Plugin constants
$GLOBALS['REDIRECTMANAGER_PLUGIN'] = true;
$GLOBALS['REDIRECTMANAGER_VERSION'] = '1.00';

// Load helper functions
require_once __DIR__ . '/redirectManager_functions.php';

// Register cron job for log cleanup
addAction('_cron_daily', 'RedirectManager\dailyMaintenance', null, 0);

// Initialize plugin (create table if needed)
addAction('admin_postlogin', 'RedirectManager\pluginInit', null, -999);

// Admin UI - only load when in admin area
if (defined('IS_CMS_ADMIN')) {
	require_once __DIR__ . '/redirectManager_admin.php';

	// Register plugin menu pages
	\pluginAction_addHandlerAndLink(t('Dashboard'), 'RedirectManager\adminDashboard', 'admins');
	\pluginAction_addHandler('RedirectManager\adminRedirects', 'admins');
	\pluginAction_addHandler('RedirectManager\adminEdit', 'admins');
	\pluginAction_addHandler('RedirectManager\adminImport', 'admins');
	\pluginAction_addHandler('RedirectManager\adminSettings', 'admins');
	\pluginAction_addHandler('RedirectManager\adminHelp', 'admins');
}

/**
 * Initialize plugin - create database table if needed
 */
function pluginInit(): void
{
	// Create redirects table if it doesn't exist
	createRedirectsTableIfNeeded();
}

/**
 * Daily maintenance - cleanup old hit logs based on retention setting
 */
function dailyMaintenance(): void
{
	$settings = loadPluginSettings();

	// Clean old hit data if retention is set
	if ($settings['logRetentionDays'] > 0 && $settings['trackHits']) {
		cleanOldHitLogs($settings['logRetentionDays']);
	}

	// Check for chains/loops
	$chains = detectChains();
	$loops = detectLoops();

	// Email warning if found
	if (($chains || $loops) && !empty($settings['notificationEmail'])) {
		sendWarningEmail($chains, $loops);
	}
}
