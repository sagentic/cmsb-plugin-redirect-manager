<?php

/**
 * Redirect Manager Plugin - Helper Functions
 *
 * @package RedirectManager
 */

namespace RedirectManager;

/**
 * Get the path to the settings JSON file
 *
 * @return string Settings file path
 */
function getSettingsFilePath(): string
{
	return __DIR__ . '/redirectManager_settings.json';
}

/**
 * Get default settings
 *
 * @return array Default settings
 */
function getDefaultSettings(): array
{
	return [
		'enabled'             => true,
		'redirectMethod'      => 'php',
		'defaultRedirectType' => '301',
		'trackHits'           => true,
		'logRetentionDays'    => 90,
		'enableWildcards'     => true,
		'caseSensitive'       => false,
		'preserveQueryString' => true,
		'notificationEmail'   => '',
		'emailOn404'          => false,
		'maxRedirectChain'    => 3,
		'lastModified'        => null,
	];
}

/**
 * Load plugin settings from JSON file
 *
 * @return array Settings array
 */
function loadPluginSettings(): array
{
	$settingsFile = getSettingsFilePath();
	$defaults = getDefaultSettings();

	if (!file_exists($settingsFile) || !is_readable($settingsFile)) {
		return $defaults;
	}

	$content = @file_get_contents($settingsFile);
	if ($content === false) {
		return $defaults;
	}

	$settings = @json_decode($content, true);

	if (!is_array($settings)) {
		return $defaults;
	}

	return array_merge($defaults, $settings);
}

/**
 * Save plugin settings to JSON file
 *
 * @param array $settings Settings to save
 * @return bool True on success
 */
function savePluginSettings(array $settings): bool
{
	$settingsFile = getSettingsFilePath();
	$settings['lastModified'] = date('Y-m-d H:i:s');
	$json = json_encode($settings, JSON_PRETTY_PRINT);
	return @file_put_contents($settingsFile, $json) !== false;
}

/**
 * Create the redirects table if it doesn't exist
 */
function createRedirectsTableIfNeeded(): void
{
	global $TABLE_PREFIX;

	// Check if table exists
	$tableExists = false;
	$result = \mysqli()->query("SHOW TABLES LIKE '{$TABLE_PREFIX}_redirectmanager_redirects'");
	if ($result && $result->num_rows > 0) {
		$tableExists = true;
	}

	if ($tableExists) {
		return;
	}

	// Create the table
	$sql = "CREATE TABLE IF NOT EXISTS `{$TABLE_PREFIX}_redirectmanager_redirects` (
		`num` int(10) unsigned NOT NULL AUTO_INCREMENT,
		`createdDate` datetime DEFAULT NULL,
		`createdByUserNum` int(10) unsigned DEFAULT NULL,
		`updatedDate` datetime DEFAULT NULL,
		`updatedByUserNum` int(10) unsigned DEFAULT NULL,
		`sourceUrl` varchar(500) NOT NULL,
		`destinationUrl` varchar(500) NOT NULL,
		`redirectType` enum('301','302','307','308') DEFAULT '301',
		`isPattern` tinyint(1) DEFAULT 0,
		`isActive` tinyint(1) DEFAULT 1,
		`hitCount` int(10) unsigned DEFAULT 0,
		`lastHitDate` datetime DEFAULT NULL,
		`notes` text,
		`dragSortOrder` int(10) unsigned DEFAULT 0,
		PRIMARY KEY (`num`),
		KEY `sourceUrl` (`sourceUrl`(191)),
		KEY `isActive` (`isActive`),
		KEY `dragSortOrder` (`dragSortOrder`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

	\mysqli()->query($sql);
}

/**
 * Find matching redirect for a request path
 *
 * @param string $requestPath The requested URL path
 * @return array|null Redirect record or null
 */
function findMatchingRedirect(string $requestPath): ?array
{
	global $TABLE_PREFIX;

	$settings = loadPluginSettings();

	if (!$settings['enabled']) {
		return null;
	}

	// Normalize the path
	$requestPath = '/' . ltrim($requestPath, '/');

	// Case sensitivity handling
	$compareFunc = $settings['caseSensitive'] ? 'strcmp' : 'strcasecmp';

	// First try exact matches (faster)
	$exactMatches = \mysql_select(
		'_redirectmanager_redirects',
		"`isActive` = 1 AND `isPattern` = 0 ORDER BY `dragSortOrder` ASC"
	);

	foreach ($exactMatches as $redirect) {
		$sourceUrl = '/' . ltrim($redirect['sourceUrl'], '/');

		if ($compareFunc($requestPath, $sourceUrl) === 0) {
			return $redirect;
		}
	}

	// Then try pattern matches if enabled
	if ($settings['enableWildcards']) {
		$patternMatches = \mysql_select(
			'_redirectmanager_redirects',
			"`isActive` = 1 AND `isPattern` = 1 ORDER BY `dragSortOrder` ASC"
		);

		foreach ($patternMatches as $redirect) {
			$pattern = patternToRegex($redirect['sourceUrl']);
			$flags = $settings['caseSensitive'] ? '' : 'i';

			if (preg_match($pattern . $flags, $requestPath)) {
				return $redirect;
			}
		}
	}

	return null;
}

/**
 * Convert a wildcard pattern to regex
 *
 * @param string $pattern Wildcard pattern (e.g., /blog/*)
 * @return string Regex pattern
 */
function patternToRegex(string $pattern): string
{
	// Normalize the pattern
	$pattern = '/' . ltrim($pattern, '/');

	// Escape regex special chars except *
	$regex = preg_quote($pattern, '/');

	// Convert * to regex (.*)
	$regex = str_replace('\\*', '(.*)', $regex);

	// Anchor to start/end
	return '/^' . $regex . '$/';
}

/**
 * Build destination URL with query string handling and wildcard replacement
 *
 * @param array $redirect The redirect record
 * @param string $originalUri The original request URI
 * @return string The destination URL
 */
function buildDestinationUrl(array $redirect, string $originalUri): string
{
	$settings = loadPluginSettings();
	$destination = $redirect['destinationUrl'];

	// Handle wildcard replacements if this is a pattern
	if ($redirect['isPattern']) {
		$pattern = patternToRegex($redirect['sourceUrl']);
		$flags = $settings['caseSensitive'] ? '' : 'i';
		$requestPath = parse_url($originalUri, PHP_URL_PATH);

		// Extract captured groups
		if (preg_match($pattern . $flags, $requestPath, $matches)) {
			// Replace $1, $2, etc. with captured groups
			for ($i = 1; $i < count($matches); $i++) {
				$destination = str_replace('$' . $i, $matches[$i], $destination);
			}
		}
	}

	// Handle query string preservation
	if ($settings['preserveQueryString']) {
		$queryString = parse_url($originalUri, PHP_URL_QUERY);
		if ($queryString) {
			$separator = str_contains($destination, '?') ? '&' : '?';
			$destination .= $separator . $queryString;
		}
	}

	return $destination;
}

/**
 * Record a redirect hit
 *
 * @param int $redirectNum The redirect record number
 * @return void
 */
function recordHit(int $redirectNum): void
{
	$settings = loadPluginSettings();

	if (!$settings['trackHits']) {
		return;
	}

	\mysql_update('_redirectmanager_redirects', $redirectNum, null, [
		'hitCount='    => '`hitCount` + 1',
		'lastHitDate=' => 'NOW()',
	]);
}

/**
 * Get all active redirects
 *
 * @return array Array of redirect records
 */
function getAllActiveRedirects(): array
{
	return \mysql_select(
		'_redirectmanager_redirects',
		"`isActive` = 1 ORDER BY `dragSortOrder` ASC"
	);
}

/**
 * Find redirect by source URL
 *
 * @param string $sourceUrl The source URL to find
 * @return array|null Redirect record or null
 */
function findRedirectBySource(string $sourceUrl): ?array
{
	$sourceUrl = '/' . ltrim($sourceUrl, '/');

	$redirects = \mysql_select(
		'_redirectmanager_redirects',
		"`isActive` = 1 AND `isPattern` = 0 AND `sourceUrl` = '" . \mysql_escape($sourceUrl) . "' LIMIT 1"
	);

	return $redirects[0] ?? null;
}

/**
 * Detect redirect chains (A -> B -> C)
 *
 * @return array Array of chain information
 */
function detectChains(): array
{
	$settings = loadPluginSettings();
	$redirects = getAllActiveRedirects();
	$chains = [];

	foreach ($redirects as $redirect) {
		$chain = [$redirect['sourceUrl']];
		$current = $redirect['destinationUrl'];
		$currentNum = $redirect['num'];

		// Follow the chain
		$visited = [$currentNum];
		while ($next = findRedirectByDestination($current)) {
			// Check for loop (handled separately)
			if (in_array($next['num'], $visited)) {
				break;
			}

			$chain[] = $current;
			$visited[] = $next['num'];
			$current = $next['destinationUrl'];

			// Check if chain exceeds max length
			if (count($chain) >= $settings['maxRedirectChain']) {
				$chain[] = $current;
				$chains[] = [
					'startNum' => $redirect['num'],
					'chain'    => $chain,
					'length'   => count($chain),
				];
				break;
			}
		}
	}

	return $chains;
}

/**
 * Find redirect by destination URL (for chain detection)
 *
 * @param string $destinationUrl The destination URL to find as a source
 * @return array|null Redirect record or null
 */
function findRedirectByDestination(string $destinationUrl): ?array
{
	// Normalize - remove protocol and domain for internal URLs
	$path = parse_url($destinationUrl, PHP_URL_PATH);
	if (!$path) {
		return null;
	}

	$path = '/' . ltrim($path, '/');

	$redirects = \mysql_select(
		'_redirectmanager_redirects',
		"`isActive` = 1 AND `isPattern` = 0 AND `sourceUrl` = '" . \mysql_escape($path) . "' LIMIT 1"
	);

	return $redirects[0] ?? null;
}

/**
 * Detect redirect loops (A -> B -> A)
 *
 * @return array Array of loop information
 */
function detectLoops(): array
{
	$redirects = getAllActiveRedirects();
	$loops = [];
	$foundLoops = []; // To avoid duplicates

	foreach ($redirects as $redirect) {
		$visited = [$redirect['sourceUrl'] => $redirect['num']];
		$path = [$redirect['sourceUrl']];
		$current = $redirect['destinationUrl'];

		// Follow the chain looking for loops
		$maxIterations = 100; // Safety limit
		$iterations = 0;

		while ($iterations < $maxIterations) {
			$iterations++;

			// Normalize current URL to path
			$currentPath = parse_url($current, PHP_URL_PATH);
			if (!$currentPath) {
				break;
			}
			$currentPath = '/' . ltrim($currentPath, '/');

			// Check if we've seen this URL before
			if (isset($visited[$currentPath])) {
				// Found a loop
				$loopKey = implode('->', array_merge($path, [$currentPath]));
				if (!isset($foundLoops[$loopKey])) {
					$foundLoops[$loopKey] = true;
					$loops[] = [
						'path'    => array_merge($path, [$currentPath]),
						'message' => implode(' -> ', array_merge($path, [$currentPath])),
					];
				}
				break;
			}

			// Find next redirect
			$next = findRedirectBySource($currentPath);
			if (!$next) {
				break;
			}

			$visited[$currentPath] = $next['num'];
			$path[] = $currentPath;
			$current = $next['destinationUrl'];
		}
	}

	return $loops;
}

/**
 * Test a URL against redirects
 *
 * @param string $testUrl URL to test
 * @return array Test results
 */
function testUrl(string $testUrl): array
{
	// Normalize - extract path
	$path = parse_url($testUrl, PHP_URL_PATH);
	if (!$path) {
		$path = $testUrl;
	}

	$redirect = findMatchingRedirect($path);

	if (!$redirect) {
		return [
			'matched'     => false,
			'message'     => t('No redirect found for this URL'),
			'redirect'    => null,
			'destination' => null,
			'chain'       => [],
		];
	}

	// Build destination
	$destination = buildDestinationUrl($redirect, $testUrl);

	// Check for chain
	$chain = [$redirect['sourceUrl']];
	$current = $destination;
	$maxChain = 10;

	while (count($chain) < $maxChain) {
		$currentPath = parse_url($current, PHP_URL_PATH);
		if (!$currentPath) {
			break;
		}

		$nextRedirect = findMatchingRedirect($currentPath);
		if (!$nextRedirect) {
			break;
		}

		$chain[] = $currentPath;
		$current = buildDestinationUrl($nextRedirect, $current);
	}

	$chain[] = $current;

	return [
		'matched'     => true,
		'message'     => t('Redirect found'),
		'redirect'    => $redirect,
		'destination' => $destination,
		'chain'       => $chain,
		'type'        => $redirect['redirectType'],
		'isPattern'   => (bool)$redirect['isPattern'],
	];
}

/**
 * Check if a source URL already exists (for duplicate detection)
 *
 * @param string $sourceUrl Source URL to check
 * @param int|null $excludeNum Record num to exclude (for edits)
 * @return bool True if duplicate exists
 */
function isDuplicateSource(string $sourceUrl, ?int $excludeNum = null): bool
{
	$sourceUrl = '/' . ltrim($sourceUrl, '/');

	$where = "`sourceUrl` = '" . \mysql_escape($sourceUrl) . "'";
	if ($excludeNum) {
		$where .= " AND `num` != " . intval($excludeNum);
	}

	$count = \mysql_count('_redirectmanager_redirects', $where);
	return $count > 0;
}

/**
 * Check if adding/editing a redirect would create a loop
 *
 * @param string $sourceUrl Source URL
 * @param string $destinationUrl Destination URL
 * @param int|null $excludeNum Record num to exclude
 * @return bool True if loop would be created
 */
function wouldCreateLoop(string $sourceUrl, string $destinationUrl, ?int $excludeNum = null): bool
{
	$sourceUrl = '/' . ltrim($sourceUrl, '/');
	$destPath = parse_url($destinationUrl, PHP_URL_PATH);

	if (!$destPath) {
		return false;
	}

	$destPath = '/' . ltrim($destPath, '/');

	// Direct loop: A -> A
	if ($sourceUrl === $destPath) {
		return true;
	}

	// Indirect loop: follow chain from destination to see if it leads back to source
	$visited = [$sourceUrl];
	$current = $destPath;
	$maxIterations = 100;
	$iterations = 0;

	while ($iterations < $maxIterations) {
		$iterations++;

		if (in_array($current, $visited)) {
			return true;
		}

		$redirect = findRedirectBySource($current);
		if (!$redirect || ($excludeNum && $redirect['num'] == $excludeNum)) {
			break;
		}

		$visited[] = $current;
		$nextPath = parse_url($redirect['destinationUrl'], PHP_URL_PATH);
		if (!$nextPath) {
			break;
		}
		$current = '/' . ltrim($nextPath, '/');
	}

	return false;
}

/**
 * Generate .htaccess rules from database
 *
 * @return string Generated rules
 */
function generateHtaccessRules(): string
{
	$redirects = getAllActiveRedirects();

	if (empty($redirects)) {
		return '';
	}

	$rules = "# BEGIN RedirectManager - DO NOT EDIT MANUALLY\n";
	$rules .= "RewriteEngine On\n";

	foreach ($redirects as $redirect) {
		$source = ltrim($redirect['sourceUrl'], '/');
		$dest = $redirect['destinationUrl'];
		$type = $redirect['redirectType'];

		if ($redirect['isPattern']) {
			// Pattern/wildcard redirect
			$source = str_replace('*', '(.*)', preg_quote($source, '/'));
			$dest = preg_replace('/\$(\d+)/', '$${1}', $dest);
		} else {
			// Exact match - escape regex special chars
			$source = preg_quote($source, '/');
		}

		$rules .= "RewriteRule ^{$source}$ {$dest} [R={$type},L]\n";
	}

	$rules .= "# END RedirectManager\n";

	return $rules;
}

/**
 * Write rules to .htaccess file
 *
 * @param string $rules The rules to write
 * @return bool Success status
 */
function writeHtaccessRules(string $rules): bool
{
	$webRootDir = \settings('webRootDir');
	if (!$webRootDir || !is_dir($webRootDir)) {
		return false;
	}

	$htaccessPath = rtrim($webRootDir, '/') . '/.htaccess';

	// Read existing .htaccess
	$existing = '';
	if (file_exists($htaccessPath)) {
		$existing = file_get_contents($htaccessPath);
	}

	// Remove old RedirectManager section
	$pattern = '/# BEGIN RedirectManager.*?# END RedirectManager\n?/s';
	$existing = preg_replace($pattern, '', $existing);

	// Add new rules at the beginning (after RewriteEngine if present)
	if (strpos($existing, 'RewriteEngine On') !== false) {
		// Insert after RewriteEngine On
		$existing = preg_replace(
			'/(RewriteEngine On\n?)/i',
			"$1\n" . $rules,
			$existing,
			1
		);
	} else {
		// Add at beginning
		$existing = $rules . "\n" . $existing;
	}

	return file_put_contents($htaccessPath, $existing) !== false;
}

/**
 * Import redirects from CSV
 *
 * @param string $csvContent CSV file content
 * @param array $options Import options
 * @return array Import results
 */
function importFromCsv(string $csvContent, array $options = []): array
{
	$defaults = [
		'skipDuplicates' => true,
		'updateExisting' => false,
		'dryRun'         => false,
	];
	$options = array_merge($defaults, $options);

	$results = [
		'imported' => 0,
		'updated'  => 0,
		'skipped'  => 0,
		'errors'   => [],
	];

	// Parse CSV
	$lines = str_getcsv($csvContent, "\n");
	$header = null;

	foreach ($lines as $lineNum => $line) {
		$row = str_getcsv($line);

		// First line is header
		if ($header === null) {
			$header = array_map('strtolower', array_map('trim', $row));
			continue;
		}

		// Map row to associative array
		$data = [];
		foreach ($header as $i => $col) {
			$data[$col] = $row[$i] ?? '';
		}

		// Required fields
		if (empty($data['source_url']) || empty($data['destination_url'])) {
			$results['errors'][] = sprintf(t('Line %d: Missing required fields'), $lineNum + 1);
			$results['skipped']++;
			continue;
		}

		$sourceUrl = '/' . ltrim(trim($data['source_url']), '/');
		$destinationUrl = trim($data['destination_url']);
		$redirectType = in_array($data['redirect_type'] ?? '301', ['301', '302', '307', '308'])
			? $data['redirect_type']
			: '301';
		$isActive = isset($data['is_active']) ? (bool)$data['is_active'] : true;
		$notes = $data['notes'] ?? '';

		// Check for duplicate
		$existing = findRedirectBySource($sourceUrl);

		if ($existing) {
			if ($options['updateExisting']) {
				if (!$options['dryRun']) {
					\mysql_update('_redirectmanager_redirects', $existing['num'], null, [
						'destinationUrl' => $destinationUrl,
						'redirectType'   => $redirectType,
						'isActive'       => $isActive ? 1 : 0,
						'notes'          => $notes,
						'updatedDate='   => 'NOW()',
					]);
				}
				$results['updated']++;
			} else {
				$results['skipped']++;
			}
			continue;
		}

		// Check for loop
		if (wouldCreateLoop($sourceUrl, $destinationUrl)) {
			$results['errors'][] = sprintf(t('Line %d: Would create redirect loop'), $lineNum + 1);
			$results['skipped']++;
			continue;
		}

		// Insert new redirect
		if (!$options['dryRun']) {
			\mysql_insert('_redirectmanager_redirects', [
				'createdDate='   => 'NOW()',
				'sourceUrl'      => $sourceUrl,
				'destinationUrl' => $destinationUrl,
				'redirectType'   => $redirectType,
				'isPattern'      => strpos($sourceUrl, '*') !== false ? 1 : 0,
				'isActive'       => $isActive ? 1 : 0,
				'notes'          => $notes,
				'hitCount'       => 0,
			]);
		}
		$results['imported']++;
	}

	return $results;
}

/**
 * Export redirects to CSV
 *
 * @param array $filters Export filters
 * @return string CSV content
 */
function exportToCsv(array $filters = []): string
{
	$where = '1=1';

	if (!empty($filters['type'])) {
		$where .= " AND `redirectType` = '" . \mysql_escape($filters['type']) . "'";
	}

	if (isset($filters['active'])) {
		$where .= " AND `isActive` = " . ($filters['active'] ? '1' : '0');
	}

	$redirects = \mysql_select('_redirectmanager_redirects', "{$where} ORDER BY `dragSortOrder` ASC");

	// Build CSV
	$output = fopen('php://temp', 'r+');

	// Header
	fputcsv($output, ['source_url', 'destination_url', 'redirect_type', 'is_active', 'hit_count', 'notes']);

	foreach ($redirects as $redirect) {
		fputcsv($output, [
			$redirect['sourceUrl'],
			$redirect['destinationUrl'],
			$redirect['redirectType'],
			$redirect['isActive'],
			$redirect['hitCount'],
			$redirect['notes'],
		]);
	}

	rewind($output);
	$csv = stream_get_contents($output);
	fclose($output);

	return $csv;
}

/**
 * Get redirect statistics
 *
 * @return array Statistics array
 */
function getStats(): array
{
	$stats = [
		'total'     => 0,
		'active'    => 0,
		'inactive'  => 0,
		'type301'   => 0,
		'type302'   => 0,
		'patterns'  => 0,
		'totalHits' => 0,
	];

	$stats['total'] = (int)\mysql_count('_redirectmanager_redirects', '1=1');
	$stats['active'] = (int)\mysql_count('_redirectmanager_redirects', "`isActive` = 1");
	$stats['inactive'] = (int)\mysql_count('_redirectmanager_redirects', "`isActive` = 0");
	$stats['type301'] = (int)\mysql_count('_redirectmanager_redirects', "`redirectType` = '301' AND `isActive` = 1");
	$stats['type302'] = (int)\mysql_count('_redirectmanager_redirects', "`redirectType` = '302' AND `isActive` = 1");
	$stats['patterns'] = (int)\mysql_count('_redirectmanager_redirects', "`isPattern` = 1 AND `isActive` = 1");

	// Total hits
	$result = \mysqli()->query("SELECT SUM(`hitCount`) as total FROM `" . $GLOBALS['TABLE_PREFIX'] . "_redirectmanager_redirects`");
	if ($result) {
		$row = $result->fetch_assoc();
		$stats['totalHits'] = (int)($row['total'] ?? 0);
	}

	return $stats;
}

/**
 * Get recent redirects (by hit count or date)
 *
 * @param int $limit Number to return
 * @return array Redirect records
 */
function getRecentRedirects(int $limit = 10): array
{
	return \mysql_select(
		'_redirectmanager_redirects',
		"`isActive` = 1 AND `lastHitDate` IS NOT NULL ORDER BY `lastHitDate` DESC LIMIT {$limit}"
	);
}

/**
 * Clean old hit logs (reset hit counts older than retention)
 *
 * @param int $days Days to retain
 * @return int Number of records affected
 */
function cleanOldHitLogs(int $days): int
{
	global $TABLE_PREFIX;

	$cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));

	$query = "UPDATE `{$TABLE_PREFIX}_redirectmanager_redirects`
	          SET `hitCount` = 0, `lastHitDate` = NULL
	          WHERE `lastHitDate` < '" . \mysql_escape($cutoffDate) . "'";

	\mysqli()->query($query);
	return \mysqli()->affected_rows;
}

/**
 * Send warning email about chains/loops
 *
 * @param array $chains Detected chains
 * @param array $loops Detected loops
 * @return bool Success status
 */
function sendWarningEmail(array $chains, array $loops): bool
{
	$settings = loadPluginSettings();

	if (empty($settings['notificationEmail'])) {
		return false;
	}

	$subject = t('Redirect Manager Warning: Issues Detected');
	$body = t('The Redirect Manager plugin has detected the following issues:') . "\n\n";

	if (!empty($loops)) {
		$body .= t('REDIRECT LOOPS (Critical - these will cause infinite redirects):') . "\n";
		foreach ($loops as $loop) {
			$body .= "- " . $loop['message'] . "\n";
		}
		$body .= "\n";
	}

	if (!empty($chains)) {
		$body .= t('REDIRECT CHAINS (Performance issue - too many hops):') . "\n";
		foreach ($chains as $chain) {
			$body .= "- " . implode(' -> ', $chain['chain']) . " ({$chain['length']} hops)\n";
		}
		$body .= "\n";
	}

	$body .= t('Please review and fix these issues in the Redirect Manager plugin.');

	return mail($settings['notificationEmail'], $subject, $body);
}

/**
 * Toggle redirect active status
 *
 * @param int $num Redirect record number
 * @param bool $active New active status
 * @return bool Success status
 */
function toggleRedirectActive(int $num, bool $active): bool
{
	\mysql_update('_redirectmanager_redirects', $num, null, [
		'isActive'     => $active ? 1 : 0,
		'updatedDate=' => 'NOW()',
	]);

	return true;
}

/**
 * Delete a redirect
 *
 * @param int $num Redirect record number
 * @return bool Success status
 */
function deleteRedirect(int $num): bool
{
	return \mysql_delete('_redirectmanager_redirects', $num);
}

/**
 * Get a single redirect by num
 *
 * @param int $num Redirect record number
 * @return array|null Redirect record or null
 */
function getRedirect(int $num): ?array
{
	$record = \mysql_get('_redirectmanager_redirects', $num);
	return $record ?: null;
}

/**
 * Save a redirect (insert or update)
 *
 * @param array $data Redirect data
 * @param int|null $num Record num for update, null for insert
 * @return int|bool Record num on success, false on failure
 */
function saveRedirect(array $data, ?int $num = null): int|bool
{
	global $CURRENT_USER;

	// Normalize source URL
	$data['sourceUrl'] = '/' . ltrim(trim($data['sourceUrl']), '/');

	// Check for duplicate
	if (isDuplicateSource($data['sourceUrl'], $num)) {
		return false;
	}

	// Check for loop
	if (wouldCreateLoop($data['sourceUrl'], $data['destinationUrl'], $num)) {
		return false;
	}

	// Detect if pattern
	$data['isPattern'] = strpos($data['sourceUrl'], '*') !== false ? 1 : 0;

	if ($num) {
		// Update
		$data['updatedDate='] = 'NOW()';
		$data['updatedByUserNum'] = $CURRENT_USER['num'] ?? 0;
		\mysql_update('_redirectmanager_redirects', $num, null, $data);
		return $num;
	} else {
		// Insert
		$data['createdDate='] = 'NOW()';
		$data['createdByUserNum'] = $CURRENT_USER['num'] ?? 0;
		$data['hitCount'] = 0;
		return \mysql_insert('_redirectmanager_redirects', $data);
	}
}
