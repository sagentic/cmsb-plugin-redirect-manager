<?php
/*
Plugin Name: Redirect Manager
Description: Manage 301/302 URL redirects through the admin UI instead of manually editing .htaccess files
Version: 1.01
CMS Version Required: 3.50
Author: Sagentic Web Design
Author URI: https://www.sagentic.com
*/

namespace RedirectManager;

// Plugin constants
$GLOBALS['REDIRECTMANAGER_PLUGIN'] = true;
$GLOBALS['REDIRECTMANAGER_VERSION'] = '1.01';

// Load helper functions
require_once __DIR__ . '/redirectManager_functions.php';

// Register scheduled task (dispatched by webadmin/cron.php, which runs from the command line,
// so it must be registered before the command-line check below)
\addCronJob('RedirectManager\dailyMaintenance', 'Redirect Manager - Daily Maintenance', '15 4 * * *');

// Don't register anything else from command-line
if (\inCLI()) {
	return;
}

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
 * Called by daily cron; returns a summary for the Background Tasks log
 */
function dailyMaintenance(): string
{
	createRedirectsTableIfNeeded();
	$settings = loadPluginSettings();

	// Clean old hit data if retention is set
	$cleared = 0;
	if ($settings['logRetentionDays'] > 0 && $settings['trackHits']) {
		$cleared = cleanOldHitLogs($settings['logRetentionDays']);
	}

	// Check for chains/loops
	$chains = detectChains();
	$loops = detectLoops();

	// Email warning if found
	$emailed = false;
	if (($chains || $loops) && !empty($settings['notificationEmail'])) {
		$emailed = sendWarningEmail($chains, $loops);
	}

	return sprintf('Cleared hits on %d redirects; %d chains, %d loops%s',
		$cleared, count($chains), count($loops), $emailed ? '; warning emailed' : '');
}
