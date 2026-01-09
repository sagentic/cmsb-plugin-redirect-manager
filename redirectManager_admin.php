<?php

/**
 * Redirect Manager Plugin - Admin UI Pages
 *
 * @package RedirectManager
 */

namespace RedirectManager;

/**
 * Generate plugin navigation bar
 *
 * @param string $currentPage Current page identifier
 * @return string HTML for navigation bar
 */
function getPluginNav(string $currentPage): string
{
	$pages = [
		'dashboard' => ['label' => t('Dashboard'), 'action' => 'RedirectManager\adminDashboard'],
		'redirects' => ['label' => t('Redirects'), 'action' => 'RedirectManager\adminRedirects'],
		'import'    => ['label' => t('Import/Export'), 'action' => 'RedirectManager\adminImport'],
		'settings'  => ['label' => t('Settings'), 'action' => 'RedirectManager\adminSettings'],
		'help'      => ['label' => t('Help'), 'action' => 'RedirectManager\adminHelp'],
	];

	$html = '<nav aria-label="' . t('Redirect Manager plugin navigation') . '"><div class="btn-group" role="group" style="margin-bottom:20px">';
	foreach ($pages as $key => $page) {
		$isActive = ($key === $currentPage);
		$btnClass = $isActive ? 'btn btn-primary' : 'btn btn-default';
		$ariaCurrent = $isActive ? ' aria-current="page"' : '';
		$html .= '<a href="?_pluginAction=' . urlencode($page['action']) . '" class="' . $btnClass . '"' . $ariaCurrent . '>' . $page['label'] . '</a>';
	}
	$html .= '</div></nav>';

	return $html;
}

/**
 * Get advanced actions menu array
 *
 * @return array Advanced actions array for adminUI
 */
function getAdvancedActions(): array
{
	$csrfToken = $_SESSION['_CSRFToken'] ?? '';
	return [
		t('Add New Redirect')    => '?_pluginAction=RedirectManager\adminEdit',
		t('Regenerate .htaccess') => '?_pluginAction=RedirectManager\adminDashboard&_action=regenerateHtaccess&_CSRFToken=' . urlencode($csrfToken),
		t('Clear All Hit Data')  => '?_pluginAction=RedirectManager\adminDashboard&_action=clearHits&_CSRFToken=' . urlencode($csrfToken),
	];
}

/**
 * Dashboard page - Main plugin overview
 */
function adminDashboard(): void
{
	global $SETTINGS;

	// Handle actions
	if (($_REQUEST['_action'] ?? '') === 'regenerateHtaccess') {
		\security_dieOnInvalidCsrfToken();
		$rules = generateHtaccessRules();
		if (writeHtaccessRules($rules)) {
			\alert(t('.htaccess rules regenerated successfully'));
		} else {
			\alert(t('Failed to write .htaccess rules. Check file permissions.'));
		}
		\redirectBrowserToURL('?_pluginAction=' . __FUNCTION__);
	}

	if (($_REQUEST['_action'] ?? '') === 'clearHits') {
		\security_dieOnInvalidCsrfToken();
		global $TABLE_PREFIX;
		\mysqli()->query("UPDATE `{$TABLE_PREFIX}_redirectmanager_redirects` SET `hitCount` = 0, `lastHitDate` = NULL");
		\alert(t('All hit data cleared'));
		\redirectBrowserToURL('?_pluginAction=' . __FUNCTION__);
	}

	if (($_REQUEST['_action'] ?? '') === 'testUrl') {
		// Handled via AJAX below
	}

	// Get data for display
	$stats = getStats();
	$recentRedirects = getRecentRedirects(10);
	$chains = detectChains();
	$loops = detectLoops();

	// Build content
	$adminUI = [];

	$adminUI['PAGE_TITLE'] = [
		t("Plugins") => '?menu=admin&action=plugins',
		t("Redirect Manager"),
	];

	$adminUI['ADVANCED_ACTIONS'] = getAdvancedActions();

	$content = '';

	// Plugin navigation
	$content .= getPluginNav('dashboard');

	// Load settings
	$settings = loadPluginSettings();

	// Warnings Section (if any issues)
	if (!empty($loops) || !empty($chains)) {
		$content .= '<div class="alert alert-danger" style="margin-bottom:20px">';
		$content .= '<h4 style="margin-top:0"><i class="fa-duotone fa-solid fa-triangle-exclamation" aria-hidden="true"></i> ' . t('Redirect Issues Detected') . '</h4>';

		if (!empty($loops)) {
			$content .= '<p><strong>' . t('Redirect Loops (Critical):') . '</strong></p>';
			$content .= '<ul>';
			foreach ($loops as $loop) {
				$content .= '<li>' . \htmlencode($loop['message']) . '</li>';
			}
			$content .= '</ul>';
		}

		if (!empty($chains)) {
			$content .= '<p><strong>' . t('Redirect Chains (Performance Issue):') . '</strong></p>';
			$content .= '<ul>';
			foreach ($chains as $chain) {
				$content .= '<li>' . \htmlencode(implode(' -> ', $chain['chain'])) . ' (' . $chain['length'] . ' ' . t('hops') . ')</li>';
			}
			$content .= '</ul>';
		}

		$content .= '<p>' . t('Please review and fix these issues to ensure optimal redirect performance.') . '</p>';
		$content .= '</div>';
	}

	// Status Section
	$content .= '<div class="separator"><div>' . t('Plugin Status') . '</div></div>';

	$content .= '<div class="form-horizontal">';

	// Plugin Status
	$content .= '<div class="form-group" style="margin-bottom:8px">';
	$content .= '<label class="col-sm-2 control-label" style="padding-top:0">' . t('Status') . '</label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<p class="form-control-static" style="padding-top:0;padding-bottom:0;margin-bottom:0;min-height:0">';
	if ($settings['enabled']) {
		$content .= '<strong style="color:#28a745"><i class="fa-duotone fa-solid fa-check" aria-hidden="true"></i> ' . t('Enabled') . '</strong>';
	} else {
		$content .= '<strong style="color:#dc3545"><i class="fa-duotone fa-solid fa-xmark" aria-hidden="true"></i> ' . t('Disabled') . '</strong>';
	}
	$content .= '</p></div></div>';

	// Redirect Method
	$content .= '<div class="form-group" style="margin-bottom:8px">';
	$content .= '<label class="col-sm-2 control-label" style="padding-top:0">' . t('Method') . '</label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<p class="form-control-static" style="padding-top:0;padding-bottom:0;margin-bottom:0;min-height:0">';
	$content .= '<strong>' . ($settings['redirectMethod'] === 'php' ? t('PHP Dispatcher') : t('.htaccess Rules')) . '</strong>';
	$content .= '</p></div></div>';

	// Hit Tracking
	$content .= '<div class="form-group" style="margin-bottom:8px">';
	$content .= '<label class="col-sm-2 control-label" style="padding-top:0">' . t('Hit Tracking') . '</label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<p class="form-control-static" style="padding-top:0;padding-bottom:0;margin-bottom:0;min-height:0">';
	if ($settings['trackHits']) {
		$content .= '<strong style="color:#28a745"><i class="fa-duotone fa-solid fa-check" aria-hidden="true"></i> ' . t('Enabled') . '</strong>';
	} else {
		$content .= '<strong style="color:#dc3545"><i class="fa-duotone fa-solid fa-xmark" aria-hidden="true"></i> ' . t('Disabled') . '</strong>';
	}
	$content .= '</p></div></div>';

	$content .= '</div>'; // end form-horizontal

	// Statistics Section
	$content .= '<div class="separator"><div>' . t('Redirect Statistics') . '</div></div>';

	$content .= '<div class="row g-3 mb-4">';

	// Helper function for stat card
	$renderStatCard = function ($label, $count, $color) {
		$html = '<div class="col-6 col-lg-3">';
		$html .= '<div class="border rounded-3 p-3 h-100 text-center">';
		$html .= '<div class="text-uppercase small fw-semibold mb-3">' . $label . '</div>';
		$html .= '<div class="fs-2 fw-bold" style="color:' . $color . '">' . $count . '</div>';
		$html .= '</div></div>';
		return $html;
	};

	$content .= $renderStatCard(t('Total Redirects'), $stats['active'], '#6c757d');
	$content .= $renderStatCard(t('301 Permanent'), $stats['type301'], '#28a745');
	$content .= $renderStatCard(t('302 Temporary'), $stats['type302'], '#ffc107');
	$content .= $renderStatCard(t('Total Hits'), number_format($stats['totalHits']), '#17a2b8');

	$content .= '</div>';

	// Quick Actions Section
	$content .= '<div class="separator"><div>' . t('Quick Actions') . '</div></div>';

	$content .= '<div style="margin-bottom:20px">';
	$content .= '<a href="?_pluginAction=RedirectManager\adminEdit" class="btn btn-primary"><i class="fa-duotone fa-solid fa-plus" aria-hidden="true"></i> ' . t('Add New Redirect') . '</a> ';
	$content .= '<a href="?_pluginAction=RedirectManager\adminRedirects" class="btn btn-default"><i class="fa-duotone fa-solid fa-list" aria-hidden="true"></i> ' . t('View All Redirects') . '</a> ';
	$content .= '<a href="?_pluginAction=RedirectManager\adminImport" class="btn btn-default"><i class="fa-duotone fa-solid fa-file-import" aria-hidden="true"></i> ' . t('Import/Export') . '</a>';
	$content .= '</div>';

	// Test URL Section
	$content .= '<div class="separator"><div>' . t('Test URL') . '</div></div>';

	$content .= '<p class="help-block" style="margin-top:0;margin-bottom:15px">' . t('Enter a URL path to test if it matches any redirect rules.') . '</p>';

	$content .= '<div class="form-horizontal">';
	$content .= '<div class="form-group">';
	$content .= '<label for="testUrlInput" class="col-sm-2 control-label">' . t('URL Path') . '</label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<div class="input-group" style="max-width:500px">';
	$content .= '<input type="text" class="form-control" id="testUrlInput" placeholder="/old-page-url">';
	$content .= '<span class="input-group-btn">';
	$content .= '<button type="button" class="btn btn-primary" onclick="testRedirectUrl()"><i class="fa-duotone fa-solid fa-vial" aria-hidden="true"></i> ' . t('Test') . '</button>';
	$content .= '</span>';
	$content .= '</div>';
	$content .= '<div id="testUrlResult" style="margin-top:10px"></div>';
	$content .= '</div></div>';
	$content .= '</div>';

	// Recent Activity Section
	$content .= '<div class="separator"><div>' . t('Recent Redirect Activity') . '</div></div>';

	if (empty($recentRedirects)) {
		$content .= '<p style="color:#6c757d">' . t('No redirect activity yet.') . '</p>';
	} else {
		$content .= '<div class="table-responsive">';
		$content .= '<table class="table table-striped table-hover">';
		$content .= '<thead><tr>';
		$content .= '<th scope="col">' . t('Source URL') . '</th>';
		$content .= '<th scope="col">' . t('Destination') . '</th>';
		$content .= '<th scope="col">' . t('Type') . '</th>';
		$content .= '<th scope="col">' . t('Hits') . '</th>';
		$content .= '<th scope="col">' . t('Last Hit') . '</th>';
		$content .= '</tr></thead><tbody>';

		foreach ($recentRedirects as $redirect) {
			$typeBadge = renderTypeBadge($redirect['redirectType']);

			$content .= '<tr>';
			$content .= '<td class="text-truncate" style="max-width:200px" title="' . \htmlencode($redirect['sourceUrl']) . '">';
			$content .= '<a href="?_pluginAction=RedirectManager\adminEdit&num=' . intval($redirect['num']) . '">' . \htmlencode($redirect['sourceUrl']) . '</a>';
			$content .= '</td>';
			$content .= '<td class="text-truncate" style="max-width:200px" title="' . \htmlencode($redirect['destinationUrl']) . '">' . \htmlencode($redirect['destinationUrl']) . '</td>';
			$content .= '<td>' . $typeBadge . '</td>';
			$content .= '<td>' . number_format($redirect['hitCount']) . '</td>';
			$content .= '<td class="text-nowrap">' . ($redirect['lastHitDate'] ? date('Y-m-d H:i', strtotime($redirect['lastHitDate'])) : '-') . '</td>';
			$content .= '</tr>';
		}

		$content .= '</tbody></table></div>';
	}

	// JavaScript for URL testing
	$content .= '<script>
function testRedirectUrl() {
	var url = document.getElementById("testUrlInput").value;
	if (!url) {
		document.getElementById("testUrlResult").innerHTML = "<div class=\"alert alert-warning\">' . t('Please enter a URL path to test') . '</div>";
		return;
	}

	// Normalize - ensure starts with /
	if (url.charAt(0) !== "/") {
		url = "/" + url;
	}

	// Make AJAX request
	var xhr = new XMLHttpRequest();
	xhr.open("POST", "?_pluginAction=RedirectManager\\\\adminDashboard&_action=testUrlAjax", true);
	xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
	xhr.onreadystatechange = function() {
		if (xhr.readyState === 4) {
			if (xhr.status === 200) {
				try {
					var result = JSON.parse(xhr.responseText);
					var html = "";
					if (result.matched) {
						html = "<div class=\"alert alert-success\">";
						html += "<strong><i class=\"fa-duotone fa-solid fa-check\" aria-hidden=\"true\"></i> ' . t('Match Found!') . '</strong><br>";
						html += "' . t('Type:') . ' <span class=\"badge\" style=\"background-color:#28a745;color:#fff\">" + result.type + "</span><br>";
						html += "' . t('Destination:') . ' <code>" + result.destination + "</code><br>";
						if (result.chain && result.chain.length > 2) {
							html += "' . t('Chain:') . ' <code>" + result.chain.join(" &rarr; ") + "</code>";
						}
						html += "</div>";
					} else {
						html = "<div class=\"alert alert-info\">";
						html += "<i class=\"fa-duotone fa-solid fa-info-circle\" aria-hidden=\"true\"></i> " + result.message;
						html += "</div>";
					}
					document.getElementById("testUrlResult").innerHTML = html;
				} catch (e) {
					document.getElementById("testUrlResult").innerHTML = "<div class=\"alert alert-danger\">' . t('Error parsing response') . '</div>";
				}
			} else {
				document.getElementById("testUrlResult").innerHTML = "<div class=\"alert alert-danger\">' . t('Request failed') . '</div>";
			}
		}
	};
	xhr.send("url=" + encodeURIComponent(url) + "&_CSRFToken=" + encodeURIComponent("' . \htmlencode($_SESSION['_CSRFToken'] ?? '') . '"));
}

// Allow Enter key to trigger test
document.getElementById("testUrlInput").addEventListener("keypress", function(e) {
	if (e.key === "Enter") {
		e.preventDefault();
		testRedirectUrl();
	}
});
</script>';

	$adminUI['CONTENT'] = $content;

	// Handle AJAX test URL request
	if (($_REQUEST['_action'] ?? '') === 'testUrlAjax') {
		header('Content-Type: application/json');
		$testUrl = $_POST['url'] ?? '';
		$result = testUrl($testUrl);
		echo json_encode($result);
		exit;
	}

	\adminUI($adminUI);
	exit;
}

/**
 * Redirects list page - View and manage all redirects
 */
function adminRedirects(): void
{
	$csrfToken = $_SESSION['_CSRFToken'] ?? '';

	// Handle single-item actions
	if (($_REQUEST['action'] ?? '') === 'activate') {
		\security_dieOnInvalidCsrfToken();
		$num = intval($_REQUEST['num'] ?? 0);
		if ($num) {
			toggleRedirectActive($num, true);
			\alert(t('Redirect activated'));
		}
		\redirectBrowserToURL('?_pluginAction=' . __FUNCTION__);
	}

	if (($_REQUEST['action'] ?? '') === 'deactivate') {
		\security_dieOnInvalidCsrfToken();
		$num = intval($_REQUEST['num'] ?? 0);
		if ($num) {
			toggleRedirectActive($num, false);
			\alert(t('Redirect deactivated'));
		}
		\redirectBrowserToURL('?_pluginAction=' . __FUNCTION__);
	}

	if (($_REQUEST['action'] ?? '') === 'delete') {
		\security_dieOnInvalidCsrfToken();
		$num = intval($_REQUEST['num'] ?? 0);
		if ($num) {
			deleteRedirect($num);
			\alert(t('Redirect deleted'));
		}
		\redirectBrowserToURL('?_pluginAction=' . __FUNCTION__);
	}

	// Handle bulk actions
	if (($_REQUEST['bulkAction'] ?? '') && !empty($_REQUEST['selectedRedirects'])) {
		\security_dieOnInvalidCsrfToken();

		if (!is_array($_REQUEST['selectedRedirects'])) {
			\alert(t('Invalid input'));
			\redirectBrowserToURL('?_pluginAction=' . __FUNCTION__);
		}

		$action = $_REQUEST['bulkAction'];
		$selectedIds = array_map('intval', $_REQUEST['selectedRedirects']);

		if ($action === 'enable') {
			foreach ($selectedIds as $id) {
				toggleRedirectActive($id, true);
			}
			\alert(sprintf(t('%d redirects enabled'), count($selectedIds)));
		} elseif ($action === 'disable') {
			foreach ($selectedIds as $id) {
				toggleRedirectActive($id, false);
			}
			\alert(sprintf(t('%d redirects disabled'), count($selectedIds)));
		} elseif ($action === 'delete') {
			foreach ($selectedIds as $id) {
				deleteRedirect($id);
			}
			\alert(sprintf(t('%d redirects deleted'), count($selectedIds)));
		} elseif ($action === 'change301') {
			foreach ($selectedIds as $id) {
				\mysql_update('_redirectmanager_redirects', $id, null, ['redirectType' => '301', 'updatedDate=' => 'NOW()']);
			}
			\alert(sprintf(t('%d redirects changed to 301'), count($selectedIds)));
		} elseif ($action === 'change302') {
			foreach ($selectedIds as $id) {
				\mysql_update('_redirectmanager_redirects', $id, null, ['redirectType' => '302', 'updatedDate=' => 'NOW()']);
			}
			\alert(sprintf(t('%d redirects changed to 302'), count($selectedIds)));
		}

		\redirectBrowserToURL('?_pluginAction=' . __FUNCTION__);
	}

	// Pagination
	$page = max(1, intval($_REQUEST['page'] ?? 1));
	$perPage = intval($_REQUEST['perPage'] ?? 50);
	$perPage = in_array($perPage, [10, 25, 50, 100, 250]) ? $perPage : 50;
	$offset = ($page - 1) * $perPage;

	// Filters
	$filterType = $_REQUEST['filterType'] ?? '';
	$filterStatus = $_REQUEST['filterStatus'] ?? '';
	$filterSearch = $_REQUEST['filterSearch'] ?? '';

	// Sorting
	$sortBy = $_REQUEST['sortBy'] ?? 'dragSortOrder';
	$sortDir = $_REQUEST['sortDir'] ?? 'ASC';
	$sortDir = in_array(strtoupper($sortDir), ['ASC', 'DESC']) ? strtoupper($sortDir) : 'ASC';

	// Build where clause
	$where = "1=1";
	if ($filterType) {
		$where .= " AND `redirectType` = '" . \mysql_escape($filterType) . "'";
	}
	if ($filterStatus === 'active') {
		$where .= " AND `isActive` = 1";
	} elseif ($filterStatus === 'inactive') {
		$where .= " AND `isActive` = 0";
	}
	if ($filterSearch) {
		$search = \mysql_escape($filterSearch);
		$where .= " AND (`sourceUrl` LIKE '%{$search}%' OR `destinationUrl` LIKE '%{$search}%' OR `notes` LIKE '%{$search}%')";
	}

	// Get total count
	$totalCount = \mysql_count('_redirectmanager_redirects', $where);
	$totalPages = ceil($totalCount / $perPage);

	// Valid sort columns mapping
	$sortColumnMap = [
		'sourceUrl'      => '`sourceUrl`',
		'destinationUrl' => '`destinationUrl`',
		'redirectType'   => '`redirectType`',
		'hitCount'       => '`hitCount`',
		'lastHitDate'    => '`lastHitDate`',
		'isActive'       => '`isActive`',
		'dragSortOrder'  => '`dragSortOrder`',
	];
	$sortColumn = $sortColumnMap[$sortBy] ?? '`dragSortOrder`';

	// Get results
	$redirects = \mysql_select('_redirectmanager_redirects', "{$where} ORDER BY {$sortColumn} {$sortDir} LIMIT {$offset}, {$perPage}");

	// Build content
	$adminUI = [];

	$adminUI['PAGE_TITLE'] = [
		t("Plugins") => '?menu=admin&action=plugins',
		t("Redirect Manager") => '?_pluginAction=RedirectManager\adminDashboard',
		t("Redirects"),
	];

	$adminUI['ADVANCED_ACTIONS'] = getAdvancedActions();

	$content = '';

	// Plugin navigation
	$content .= getPluginNav('redirects');

	// Filters Section
	$content .= '<div class="separator"><div>' . t('Filter Redirects') . '</div></div>';

	$content .= '<form method="get" id="filterForm">';
	$content .= '<input type="hidden" name="_pluginAction" value="RedirectManager\adminRedirects">';
	$content .= '<div class="form-horizontal">';

	// Search
	$content .= '<div class="form-group">';
	$content .= '<label for="filterSearch" class="col-sm-2 control-label">' . t('Search') . '</label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<input type="text" name="filterSearch" id="filterSearch" class="form-control" style="width:300px;display:inline-block" value="' . \htmlencode($filterSearch) . '" placeholder="' . t('Search source, destination, or notes...') . '">';
	$content .= '</div></div>';

	// Type filter
	$content .= '<div class="form-group">';
	$content .= '<label for="filterType" class="col-sm-2 control-label">' . t('Type') . '</label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<select name="filterType" id="filterType" class="form-control" style="width:150px;display:inline-block">';
	$content .= '<option value="">' . t('All Types') . '</option>';
	$content .= '<option value="301"' . ($filterType === '301' ? ' selected' : '') . '>301</option>';
	$content .= '<option value="302"' . ($filterType === '302' ? ' selected' : '') . '>302</option>';
	$content .= '<option value="307"' . ($filterType === '307' ? ' selected' : '') . '>307</option>';
	$content .= '<option value="308"' . ($filterType === '308' ? ' selected' : '') . '>308</option>';
	$content .= '</select>';
	$content .= '</div></div>';

	// Status filter
	$content .= '<div class="form-group">';
	$content .= '<label for="filterStatus" class="col-sm-2 control-label">' . t('Status') . '</label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<select name="filterStatus" id="filterStatus" class="form-control" style="width:150px;display:inline-block">';
	$content .= '<option value="">' . t('All') . '</option>';
	$content .= '<option value="active"' . ($filterStatus === 'active' ? ' selected' : '') . '>' . t('Active') . '</option>';
	$content .= '<option value="inactive"' . ($filterStatus === 'inactive' ? ' selected' : '') . '>' . t('Inactive') . '</option>';
	$content .= '</select>';
	$content .= '</div></div>';

	// Per page
	$content .= '<div class="form-group">';
	$content .= '<label for="perPage" class="col-sm-2 control-label">' . t('Per Page') . '</label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<select name="perPage" id="perPage" class="form-control" style="width:100px;display:inline-block">';
	foreach ([10, 25, 50, 100, 250] as $pp) {
		$content .= '<option value="' . $pp . '"' . ($perPage === $pp ? ' selected' : '') . '>' . $pp . '</option>';
	}
	$content .= '</select>';
	$content .= '</div></div>';

	// Buttons
	$content .= '<div class="form-group">';
	$content .= '<div class="col-sm-2 control-label"></div>';
	$content .= '<div class="col-sm-10">';
	$content .= '<button type="submit" class="btn btn-primary">' . t('Filter') . '</button>';
	$content .= ' <a href="?_pluginAction=RedirectManager\adminRedirects" class="btn btn-default">' . t('Reset') . '</a>';
	$content .= ' <a href="?_pluginAction=RedirectManager\adminEdit" class="btn btn-success"><i class="fa-duotone fa-solid fa-plus" aria-hidden="true"></i> ' . t('Add New') . '</a>';
	$content .= '</div></div>';

	$content .= '</div>'; // end form-horizontal
	$content .= '</form>';

	// Results count and bulk actions
	$content .= '<div style="margin-top:20px;margin-bottom:15px">';
	$content .= '<div class="row">';
	$content .= '<div class="col-sm-6">';
	$content .= '<p>' . sprintf(t('Showing %d - %d of %d redirects'), min($offset + 1, $totalCount), min($offset + $perPage, $totalCount), $totalCount) . '</p>';
	$content .= '</div>';
	$content .= '<div class="col-sm-6 text-right">';
	$content .= '<form method="post" id="bulkActionForm" style="display:inline-block">';
	$content .= '<input type="hidden" name="_pluginAction" value="RedirectManager\adminRedirects">';
	$content .= '<input type="hidden" name="_CSRFToken" value="' . \htmlencode($_SESSION['_CSRFToken'] ?? '') . '">';
	$content .= '<select name="bulkAction" id="bulkAction" class="form-control" style="width:180px;display:inline-block;margin-right:5px">';
	$content .= '<option value="">' . t('Bulk Actions') . '</option>';
	$content .= '<option value="enable">' . t('Enable Selected') . '</option>';
	$content .= '<option value="disable">' . t('Disable Selected') . '</option>';
	$content .= '<option value="change301">' . t('Change to 301') . '</option>';
	$content .= '<option value="change302">' . t('Change to 302') . '</option>';
	$content .= '<option value="delete">' . t('Delete Selected') . '</option>';
	$content .= '</select>';
	$content .= '<button type="submit" class="btn btn-default" onclick="return confirmBulkAction()">' . t('Apply') . '</button>';
	$content .= '</form>';
	$content .= '</div>';
	$content .= '</div>';
	$content .= '</div>';

	// Results table
	if (empty($redirects)) {
		$content .= '<div class="alert alert-info">';
		if ($filterSearch || $filterType || $filterStatus) {
			$content .= t('No redirects match your current filters.') . ' <a href="?_pluginAction=RedirectManager\adminRedirects">' . t('Reset filters') . '</a>';
		} else {
			$content .= t('No redirects have been created yet.') . ' <a href="?_pluginAction=RedirectManager\adminEdit">' . t('Add your first redirect') . '</a>';
		}
		$content .= '</div>';
	} else {
		$content .= '<div class="table-responsive">';
		$content .= '<table class="table table-striped table-hover">';
		$content .= '<thead><tr>';
		$content .= '<th scope="col" style="width:40px"><input type="checkbox" id="selectAll" aria-label="' . t('Select all') . '"></th>';
		$content .= '<th scope="col">' . t('Source URL') . '</th>';
		$content .= '<th scope="col">' . t('Destination URL') . '</th>';
		$content .= '<th scope="col" style="width:60px">' . t('Type') . '</th>';
		$content .= '<th scope="col" style="width:80px">' . t('Hits') . '</th>';
		$content .= '<th scope="col" style="width:80px">' . t('Status') . '</th>';
		$content .= '<th scope="col" style="width:120px" class="text-center">' . t('Actions') . '</th>';
		$content .= '</tr></thead><tbody>';

		foreach ($redirects as $redirect) {
			$num = intval($redirect['num']);
			$typeBadge = renderTypeBadge($redirect['redirectType']);
			$statusToggle = renderStatusToggle($num, (bool)$redirect['isActive']);

			// Check if external URL
			$isExternal = preg_match('/^https?:\/\//', $redirect['destinationUrl']);
			$externalIcon = $isExternal ? ' <i class="fa-duotone fa-solid fa-arrow-up-right-from-square text-muted" aria-hidden="true" title="' . t('External URL') . '"></i>' : '';

			// Pattern indicator
			$patternIcon = $redirect['isPattern'] ? ' <i class="fa-duotone fa-solid fa-asterisk text-info" aria-hidden="true" title="' . t('Wildcard Pattern') . '"></i>' : '';

			$content .= '<tr>';
			$content .= '<td><input type="checkbox" name="selectedRedirects[]" value="' . $num . '" form="bulkActionForm" aria-label="' . t('Select redirect') . ' ' . $num . '"></td>';
			$content .= '<td class="text-truncate" style="max-width:200px" title="' . \htmlencode($redirect['sourceUrl']) . '">';
			$content .= '<a href="?_pluginAction=RedirectManager\adminEdit&num=' . $num . '">' . \htmlencode($redirect['sourceUrl']) . '</a>' . $patternIcon;
			$content .= '</td>';
			$content .= '<td class="text-truncate" style="max-width:200px" title="' . \htmlencode($redirect['destinationUrl']) . '">' . \htmlencode($redirect['destinationUrl']) . $externalIcon . '</td>';
			$content .= '<td>' . $typeBadge . '</td>';
			$content .= '<td>' . number_format($redirect['hitCount']) . '</td>';
			$content .= '<td class="text-center">' . $statusToggle . '</td>';
			$content .= '<td class="text-center text-nowrap">';

			// Edit button
			$content .= '<a href="?_pluginAction=RedirectManager\adminEdit&num=' . $num . '" class="btn btn-xs btn-primary" title="' . t('Edit') . '">';
			$content .= '<i class="fa-duotone fa-solid fa-pencil" aria-hidden="true"></i>';
			$content .= '<span class="sr-only">' . t('Edit') . '</span>';
			$content .= '</a> ';

			// Test button
			$content .= '<button type="button" class="btn btn-xs btn-default" onclick="quickTest(\'' . \htmlencode(addslashes($redirect['sourceUrl'])) . '\')" title="' . t('Test') . '">';
			$content .= '<i class="fa-duotone fa-solid fa-vial" aria-hidden="true"></i>';
			$content .= '<span class="sr-only">' . t('Test') . '</span>';
			$content .= '</button> ';

			// Delete button
			$content .= '<a href="?_pluginAction=RedirectManager\adminRedirects&action=delete&num=' . $num . '&_CSRFToken=' . urlencode($csrfToken) . '" class="btn btn-xs btn-danger" onclick="return confirm(\'' . t('Are you sure you want to delete this redirect?') . '\')" title="' . t('Delete') . '">';
			$content .= '<i class="fa-duotone fa-solid fa-trash" aria-hidden="true"></i>';
			$content .= '<span class="sr-only">' . t('Delete') . '</span>';
			$content .= '</a>';

			$content .= '</td>';
			$content .= '</tr>';
		}

		$content .= '</tbody></table></div>';

		// Pagination
		if ($totalPages > 1) {
			$baseUrl = '?_pluginAction=RedirectManager\adminRedirects';
			if ($filterType) $baseUrl .= '&filterType=' . urlencode($filterType);
			if ($filterStatus) $baseUrl .= '&filterStatus=' . urlencode($filterStatus);
			if ($filterSearch) $baseUrl .= '&filterSearch=' . urlencode($filterSearch);
			$baseUrl .= '&perPage=' . $perPage;
			$baseUrl .= '&sortBy=' . urlencode($sortBy);
			$baseUrl .= '&sortDir=' . urlencode($sortDir);

			$content .= '<div class="text-center" style="margin-top:15px">';

			if ($page > 1) {
				$content .= '<a href="' . $baseUrl . '&page=' . ($page - 1) . '" class="btn btn-default btn-sm">&laquo; ' . t('Previous') . '</a> ';
			}

			$startPage = max(1, $page - 2);
			$endPage = min($totalPages, $page + 2);

			for ($i = $startPage; $i <= $endPage; $i++) {
				if ($i === $page) {
					$content .= '<span class="btn btn-primary btn-sm">' . $i . '</span> ';
				} else {
					$content .= '<a href="' . $baseUrl . '&page=' . $i . '" class="btn btn-default btn-sm">' . $i . '</a> ';
				}
			}

			if ($page < $totalPages) {
				$content .= '<a href="' . $baseUrl . '&page=' . ($page + 1) . '" class="btn btn-default btn-sm">' . t('Next') . ' &raquo;</a>';
			}

			$content .= '</div>';
		}
	}

	// JavaScript
	$content .= '<script>
// Select all checkbox
document.getElementById("selectAll").addEventListener("change", function() {
	var checkboxes = document.querySelectorAll("input[name=\'selectedRedirects[]\']");
	for (var i = 0; i < checkboxes.length; i++) {
		checkboxes[i].checked = this.checked;
	}
});

// Confirm bulk action
function confirmBulkAction() {
	var action = document.getElementById("bulkAction").value;
	if (!action) {
		alert("' . t('Please select a bulk action') . '");
		return false;
	}
	var selected = document.querySelectorAll("input[name=\'selectedRedirects[]\']:checked");
	if (selected.length === 0) {
		alert("' . t('Please select at least one redirect') . '");
		return false;
	}
	if (action === "delete") {
		return confirm("' . t('Are you sure you want to delete the selected redirects?') . '");
	}
	return true;
}

// Quick test
function quickTest(url) {
	window.location.href = "?_pluginAction=RedirectManager\\\\adminDashboard#testUrl";
	// Store URL to test in session storage
	sessionStorage.setItem("redirectManager_testUrl", url);
}

// Check for stored test URL on dashboard
if (window.location.href.indexOf("adminDashboard") > -1) {
	var storedUrl = sessionStorage.getItem("redirectManager_testUrl");
	if (storedUrl) {
		sessionStorage.removeItem("redirectManager_testUrl");
		var input = document.getElementById("testUrlInput");
		if (input) {
			input.value = storedUrl;
			testRedirectUrl();
		}
	}
}
</script>';

	$adminUI['CONTENT'] = $content;

	\adminUI($adminUI);
	exit;
}

/**
 * Add/Edit redirect page
 */
function adminEdit(): void
{
	$num = intval($_REQUEST['num'] ?? 0);
	$redirect = null;
	$errors = [];

	if ($num) {
		$redirect = getRedirect($num);
		if (!$redirect) {
			\alert(t('Redirect not found'));
			\redirectBrowserToURL('?_pluginAction=RedirectManager\adminRedirects');
		}
	}

	// Handle form submission
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_REQUEST['saveRedirect'] ?? '')) {
		\security_dieOnInvalidCsrfToken();

		$sourceUrl = trim($_POST['sourceUrl'] ?? '');
		$destinationUrl = trim($_POST['destinationUrl'] ?? '');
		$redirectType = $_POST['redirectType'] ?? '301';
		$isActive = isset($_POST['isActive']) ? 1 : 0;
		$notes = trim($_POST['notes'] ?? '');

		// Validation
		if (empty($sourceUrl)) {
			$errors[] = t('Source URL is required');
		} elseif ($sourceUrl[0] !== '/' && strpos($sourceUrl, '*') !== 0) {
			$errors[] = t('Source URL must start with /');
		}

		if (empty($destinationUrl)) {
			$errors[] = t('Destination URL is required');
		}

		if (!in_array($redirectType, ['301', '302', '307', '308'])) {
			$errors[] = t('Invalid redirect type');
		}

		// Check for duplicate
		if (empty($errors) && isDuplicateSource($sourceUrl, $num ?: null)) {
			$errors[] = t('A redirect with this source URL already exists');
		}

		// Check for loop
		if (empty($errors) && wouldCreateLoop($sourceUrl, $destinationUrl, $num ?: null)) {
			$errors[] = t('This redirect would create a redirect loop');
		}

		// Save if no errors
		if (empty($errors)) {
			$data = [
				'sourceUrl'      => $sourceUrl,
				'destinationUrl' => $destinationUrl,
				'redirectType'   => $redirectType,
				'isActive'       => $isActive,
				'notes'          => $notes,
			];

			$result = saveRedirect($data, $num ?: null);

			if ($result) {
				\alert($num ? t('Redirect updated successfully') : t('Redirect created successfully'));
				\redirectBrowserToURL('?_pluginAction=RedirectManager\adminRedirects');
			} else {
				$errors[] = t('Failed to save redirect. It may create a duplicate or loop.');
			}
		}
	}

	// Load settings for defaults
	$settings = loadPluginSettings();

	// Build content
	$adminUI = [];

	$adminUI['PAGE_TITLE'] = [
		t("Plugins") => '?menu=admin&action=plugins',
		t("Redirect Manager") => '?_pluginAction=RedirectManager\adminDashboard',
		t("Redirects") => '?_pluginAction=RedirectManager\adminRedirects',
		$num ? t("Edit Redirect") : t("Add Redirect"),
	];

	$adminUI['FORM'] = ['name' => 'redirectForm', 'autocomplete' => 'off'];
	$adminUI['HIDDEN_FIELDS'] = [
		['name' => 'saveRedirect', 'value' => '1'],
		['name' => '_pluginAction', 'value' => 'RedirectManager\adminEdit'],
		['name' => 'num', 'value' => $num],
	];
	$adminUI['BUTTONS'] = [
		['name' => '_action=save', 'label' => t('Save Redirect')],
		['name' => 'cancel', 'label' => t('Cancel'), 'onclick' => "window.location='?_pluginAction=RedirectManager\\adminRedirects';return false;"],
	];

	$content = '';

	// Plugin navigation
	$content .= getPluginNav('redirects');

	// Show errors
	if (!empty($errors)) {
		$content .= '<div class="alert alert-danger">';
		$content .= '<ul style="margin-bottom:0">';
		foreach ($errors as $error) {
			$content .= '<li>' . \htmlencode($error) . '</li>';
		}
		$content .= '</ul>';
		$content .= '</div>';
	}

	// Form fields
	$content .= '<div class="separator"><div>' . t('Redirect Details') . '</div></div>';

	$content .= '<div class="form-horizontal">';

	// Source URL
	$sourceValue = $_POST['sourceUrl'] ?? ($redirect['sourceUrl'] ?? '');
	$content .= '<div class="form-group">';
	$content .= '<label for="sourceUrl" class="col-sm-2 control-label">' . t('Source URL Path') . ' <span class="text-danger">*</span></label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<input type="text" class="form-control" id="sourceUrl" name="sourceUrl" value="' . \htmlencode($sourceValue) . '" placeholder="/old-page-url" required style="max-width:500px">';
	$content .= '<p class="help-block">' . t('Without domain. Use * for wildcard, e.g., /blog/*') . '</p>';
	$content .= '</div></div>';

	// Destination URL
	$destValue = $_POST['destinationUrl'] ?? ($redirect['destinationUrl'] ?? '');
	$content .= '<div class="form-group">';
	$content .= '<label for="destinationUrl" class="col-sm-2 control-label">' . t('Destination URL') . ' <span class="text-danger">*</span></label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<input type="text" class="form-control" id="destinationUrl" name="destinationUrl" value="' . \htmlencode($destValue) . '" placeholder="https://example.com/new-page or /new-page" required style="max-width:500px">';
	$content .= '<p class="help-block">' . t('Full URL or relative path. Use $1, $2 for wildcard captures.') . '</p>';
	$content .= '</div></div>';

	// Redirect Type
	$typeValue = $_POST['redirectType'] ?? ($redirect['redirectType'] ?? $settings['defaultRedirectType']);
	$content .= '<div class="form-group">';
	$content .= '<label class="col-sm-2 control-label">' . t('Redirect Type') . '</label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<div class="radio"><label>';
	$content .= '<input type="radio" name="redirectType" value="301"' . ($typeValue === '301' ? ' checked' : '') . '> ';
	$content .= '<strong>301</strong> - ' . t('Permanent (recommended for SEO)');
	$content .= '</label></div>';
	$content .= '<div class="radio"><label>';
	$content .= '<input type="radio" name="redirectType" value="302"' . ($typeValue === '302' ? ' checked' : '') . '> ';
	$content .= '<strong>302</strong> - ' . t('Temporary');
	$content .= '</label></div>';
	$content .= '<div class="radio"><label>';
	$content .= '<input type="radio" name="redirectType" value="307"' . ($typeValue === '307' ? ' checked' : '') . '> ';
	$content .= '<strong>307</strong> - ' . t('Temporary (strict - preserves request method)');
	$content .= '</label></div>';
	$content .= '<div class="radio"><label>';
	$content .= '<input type="radio" name="redirectType" value="308"' . ($typeValue === '308' ? ' checked' : '') . '> ';
	$content .= '<strong>308</strong> - ' . t('Permanent (strict - preserves request method)');
	$content .= '</label></div>';
	$content .= '</div></div>';

	// Active
	$isActiveValue = isset($_POST['isActive']) ? (bool)$_POST['isActive'] : ($redirect['isActive'] ?? true);
	$content .= '<div class="form-group">';
	$content .= '<div class="col-sm-2 control-label"></div>';
	$content .= '<div class="col-sm-10">';
	$content .= '<div class="checkbox"><label>';
	$content .= '<input type="checkbox" name="isActive" value="1"' . ($isActiveValue ? ' checked' : '') . '> ';
	$content .= t('Active');
	$content .= '</label></div>';
	$content .= '</div></div>';

	// Notes
	$notesValue = $_POST['notes'] ?? ($redirect['notes'] ?? '');
	$content .= '<div class="form-group">';
	$content .= '<label for="notes" class="col-sm-2 control-label">' . t('Notes') . '</label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<textarea class="form-control" id="notes" name="notes" rows="3" style="max-width:500px">' . \htmlencode($notesValue) . '</textarea>';
	$content .= '<p class="help-block">' . t('Optional admin notes about this redirect') . '</p>';
	$content .= '</div></div>';

	$content .= '</div>'; // end form-horizontal

	// Show hit stats if editing
	if ($redirect) {
		$content .= '<div class="separator"><div>' . t('Statistics') . '</div></div>';

		$content .= '<div class="form-horizontal">';
		$content .= '<div class="form-group" style="margin-bottom:8px">';
		$content .= '<label class="col-sm-2 control-label" style="padding-top:0">' . t('Hit Count') . '</label>';
		$content .= '<div class="col-sm-10">';
		$content .= '<p class="form-control-static" style="padding-top:0;padding-bottom:0;margin-bottom:0;min-height:0"><strong>' . number_format($redirect['hitCount']) . '</strong></p>';
		$content .= '</div></div>';

		$content .= '<div class="form-group" style="margin-bottom:8px">';
		$content .= '<label class="col-sm-2 control-label" style="padding-top:0">' . t('Last Hit') . '</label>';
		$content .= '<div class="col-sm-10">';
		$content .= '<p class="form-control-static" style="padding-top:0;padding-bottom:0;margin-bottom:0;min-height:0">';
		$content .= $redirect['lastHitDate'] ? date('Y-m-d H:i:s', strtotime($redirect['lastHitDate'])) : t('Never');
		$content .= '</p></div></div>';

		$content .= '<div class="form-group" style="margin-bottom:8px">';
		$content .= '<label class="col-sm-2 control-label" style="padding-top:0">' . t('Created') . '</label>';
		$content .= '<div class="col-sm-10">';
		$content .= '<p class="form-control-static" style="padding-top:0;padding-bottom:0;margin-bottom:0;min-height:0">';
		$content .= date('Y-m-d H:i:s', strtotime($redirect['createdDate']));
		$content .= '</p></div></div>';

		$content .= '</div>';
	}

	$adminUI['CONTENT'] = $content;

	\adminUI($adminUI);
	exit;
}

/**
 * Import/Export page
 */
function adminImport(): void
{
	$message = '';
	$messageType = 'info';

	// Handle export
	if (($_REQUEST['_action'] ?? '') === 'export') {
		$filters = [];
		if ($_REQUEST['exportType'] ?? '') {
			$filters['type'] = $_REQUEST['exportType'];
		}
		if (($_REQUEST['exportActive'] ?? '') !== '') {
			$filters['active'] = (bool)$_REQUEST['exportActive'];
		}

		$csv = exportToCsv($filters);

		header('Content-Type: text/csv');
		header('Content-Disposition: attachment; filename="redirects_' . date('Y-m-d') . '.csv"');
		echo $csv;
		exit;
	}

	// Handle import
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_REQUEST['importCsv'] ?? '')) {
		\security_dieOnInvalidCsrfToken();

		if (empty($_FILES['csvFile']['tmp_name'])) {
			$message = t('Please select a CSV file to import');
			$messageType = 'warning';
		} else {
			$csvContent = file_get_contents($_FILES['csvFile']['tmp_name']);

			$options = [
				'skipDuplicates' => isset($_POST['skipDuplicates']),
				'updateExisting' => isset($_POST['updateExisting']),
				'dryRun'         => isset($_POST['dryRun']),
			];

			$results = importFromCsv($csvContent, $options);

			if ($options['dryRun']) {
				$message = sprintf(
					t('Dry run complete: %d would be imported, %d would be updated, %d would be skipped'),
					$results['imported'],
					$results['updated'],
					$results['skipped']
				);
				$messageType = 'info';
			} else {
				$message = sprintf(
					t('Import complete: %d imported, %d updated, %d skipped'),
					$results['imported'],
					$results['updated'],
					$results['skipped']
				);
				$messageType = 'success';
			}

			if (!empty($results['errors'])) {
				$message .= '<br><br><strong>' . t('Errors:') . '</strong><ul>';
				foreach (array_slice($results['errors'], 0, 10) as $error) {
					$message .= '<li>' . \htmlencode($error) . '</li>';
				}
				if (count($results['errors']) > 10) {
					$message .= '<li>... ' . t('and %d more errors', count($results['errors']) - 10) . '</li>';
				}
				$message .= '</ul>';
				$messageType = 'warning';
			}
		}
	}

	// Build content
	$adminUI = [];

	$adminUI['PAGE_TITLE'] = [
		t("Plugins") => '?menu=admin&action=plugins',
		t("Redirect Manager") => '?_pluginAction=RedirectManager\adminDashboard',
		t("Import/Export"),
	];

	$adminUI['ADVANCED_ACTIONS'] = getAdvancedActions();

	$content = '';

	// Plugin navigation
	$content .= getPluginNav('import');

	// Show message
	if ($message) {
		$content .= '<div class="alert alert-' . $messageType . '">' . $message . '</div>';
	}

	// Export Section
	$content .= '<div class="separator"><div>' . t('Export Redirects') . '</div></div>';

	$content .= '<p class="help-block" style="margin-top:0;margin-bottom:15px">' . t('Download your redirects as a CSV file for backup or transfer to another site.') . '</p>';

	$content .= '<form method="get" style="margin-bottom:20px">';
	$content .= '<input type="hidden" name="_pluginAction" value="RedirectManager\adminImport">';
	$content .= '<input type="hidden" name="_action" value="export">';

	$content .= '<div class="form-inline">';
	$content .= '<label for="exportType" style="margin-right:10px">' . t('Type:') . '</label>';
	$content .= '<select name="exportType" id="exportType" class="form-control" style="width:150px;margin-right:15px">';
	$content .= '<option value="">' . t('All Types') . '</option>';
	$content .= '<option value="301">301</option>';
	$content .= '<option value="302">302</option>';
	$content .= '</select>';

	$content .= '<label for="exportActive" style="margin-right:10px">' . t('Status:') . '</label>';
	$content .= '<select name="exportActive" id="exportActive" class="form-control" style="width:150px;margin-right:15px">';
	$content .= '<option value="">' . t('All') . '</option>';
	$content .= '<option value="1">' . t('Active Only') . '</option>';
	$content .= '<option value="0">' . t('Inactive Only') . '</option>';
	$content .= '</select>';

	$content .= '<button type="submit" class="btn btn-primary"><i class="fa-duotone fa-solid fa-file-export" aria-hidden="true"></i> ' . t('Export CSV') . '</button>';
	$content .= '</div>';
	$content .= '</form>';
	$content .= '<div style="margin-bottom:30px"></div>';

	// Import Section
	$content .= '<div class="separator"><div>' . t('Import Redirects') . '</div></div>';

	$content .= '<p class="help-block" style="margin-top:0;margin-bottom:15px">' . t('Upload a CSV file to import redirects. The CSV must have these columns: source_url, destination_url, redirect_type, is_active, notes') . '</p>';

	$content .= '<form method="post" enctype="multipart/form-data">';
	$content .= '<input type="hidden" name="_pluginAction" value="RedirectManager\adminImport">';
	$content .= '<input type="hidden" name="importCsv" value="1">';
	$content .= '<input type="hidden" name="_CSRFToken" value="' . \htmlencode($_SESSION['_CSRFToken'] ?? '') . '">';

	$content .= '<div class="form-horizontal">';

	// File upload
	$content .= '<div class="form-group">';
	$content .= '<label for="csvFile" class="col-sm-2 control-label">' . t('CSV File') . '</label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<input type="file" id="csvFile" name="csvFile" accept=".csv" class="form-control" style="max-width:400px">';
	$content .= '</div></div>';

	// Options
	$content .= '<div class="form-group">';
	$content .= '<div class="col-sm-2 control-label"></div>';
	$content .= '<div class="col-sm-10">';
	$content .= '<div class="checkbox"><label>';
	$content .= '<input type="checkbox" name="skipDuplicates" value="1" checked> ';
	$content .= t('Skip duplicate source URLs');
	$content .= '</label></div>';
	$content .= '<div class="checkbox"><label>';
	$content .= '<input type="checkbox" name="updateExisting" value="1"> ';
	$content .= t('Update existing redirects (match by source URL)');
	$content .= '</label></div>';
	$content .= '<div class="checkbox"><label>';
	$content .= '<input type="checkbox" name="dryRun" value="1"> ';
	$content .= t('Dry run (preview only, no changes)');
	$content .= '</label></div>';
	$content .= '</div></div>';

	// Submit
	$content .= '<div class="form-group">';
	$content .= '<div class="col-sm-2 control-label"></div>';
	$content .= '<div class="col-sm-10">';
	$content .= '<button type="submit" class="btn btn-success"><i class="fa-duotone fa-solid fa-file-import" aria-hidden="true"></i> ' . t('Import CSV') . '</button>';
	$content .= '</div></div>';

	$content .= '</div>'; // end form-horizontal
	$content .= '</form>';

	// CSV Format Reference
	$content .= '<div class="separator"><div>' . t('CSV Format Reference') . '</div></div>';

	$content .= '<p>' . t('Your CSV file should have the following columns:') . '</p>';

	$content .= '<div class="table-responsive">';
	$content .= '<table class="table table-bordered">';
	$content .= '<thead><tr>';
	$content .= '<th>' . t('Column') . '</th>';
	$content .= '<th>' . t('Required') . '</th>';
	$content .= '<th>' . t('Description') . '</th>';
	$content .= '<th>' . t('Example') . '</th>';
	$content .= '</tr></thead><tbody>';
	$content .= '<tr><td><code>source_url</code></td><td>' . t('Yes') . '</td><td>' . t('Source URL path (without domain)') . '</td><td><code>/old-page</code></td></tr>';
	$content .= '<tr><td><code>destination_url</code></td><td>' . t('Yes') . '</td><td>' . t('Destination URL (full or relative)') . '</td><td><code>/new-page</code></td></tr>';
	$content .= '<tr><td><code>redirect_type</code></td><td>' . t('No') . '</td><td>' . t('301, 302, 307, or 308') . '</td><td><code>301</code></td></tr>';
	$content .= '<tr><td><code>is_active</code></td><td>' . t('No') . '</td><td>' . t('1 for active, 0 for inactive') . '</td><td><code>1</code></td></tr>';
	$content .= '<tr><td><code>notes</code></td><td>' . t('No') . '</td><td>' . t('Admin notes') . '</td><td><code>Moved in redesign</code></td></tr>';
	$content .= '</tbody></table>';
	$content .= '</div>';

	$content .= '<p style="margin-top:20px"><strong>' . t('Example CSV:') . '</strong></p>';
	$content .= '<pre style="background:#f5f5f5;padding:10px;border-radius:4px">source_url,destination_url,redirect_type,is_active,notes
/old-page,/new-page,301,1,"Moved in redesign"
/blog/*,/articles/$1,301,1,"Blog section renamed"
/contact-us,/contact,302,1,"Temporary redirect"</pre>';

	$adminUI['CONTENT'] = $content;

	\adminUI($adminUI);
	exit;
}

/**
 * Settings page
 */
function adminSettings(): void
{
	$message = '';
	$messageType = 'info';

	// Handle form submission
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_REQUEST['saveSettings'] ?? '')) {
		\security_dieOnInvalidCsrfToken();

		$settings = loadPluginSettings();

		$settings['enabled'] = isset($_POST['enabled']);
		$settings['redirectMethod'] = $_POST['redirectMethod'] ?? 'php';
		$settings['defaultRedirectType'] = $_POST['defaultRedirectType'] ?? '301';
		$settings['trackHits'] = isset($_POST['trackHits']);
		$settings['logRetentionDays'] = max(0, min(365, intval($_POST['logRetentionDays'] ?? 90)));
		$settings['enableWildcards'] = isset($_POST['enableWildcards']);
		$settings['caseSensitive'] = isset($_POST['caseSensitive']);
		$settings['preserveQueryString'] = isset($_POST['preserveQueryString']);
		$settings['notificationEmail'] = trim($_POST['notificationEmail'] ?? '');
		$settings['emailOn404'] = isset($_POST['emailOn404']);
		$settings['maxRedirectChain'] = max(1, min(10, intval($_POST['maxRedirectChain'] ?? 3)));

		if (savePluginSettings($settings)) {
			$message = t('Settings saved successfully');
			$messageType = 'success';
		} else {
			$message = t('Failed to save settings. Check file permissions.');
			$messageType = 'danger';
		}
	}

	// Load settings
	$settings = loadPluginSettings();

	// Build content
	$adminUI = [];

	$adminUI['PAGE_TITLE'] = [
		t("Plugins") => '?menu=admin&action=plugins',
		t("Redirect Manager") => '?_pluginAction=RedirectManager\adminDashboard',
		t("Settings"),
	];

	$adminUI['FORM'] = ['name' => 'settingsForm', 'autocomplete' => 'off'];
	$adminUI['HIDDEN_FIELDS'] = [
		['name' => 'saveSettings', 'value' => '1'],
		['name' => '_pluginAction', 'value' => 'RedirectManager\adminSettings'],
	];
	$adminUI['BUTTONS'] = [
		['name' => '_action=save', 'label' => t('Save Settings')],
	];

	$content = '';

	// Plugin navigation
	$content .= getPluginNav('settings');

	// Show message
	if ($message) {
		$content .= '<div class="alert alert-' . $messageType . '">' . $message . '</div>';
	}

	// General Settings
	$content .= '<div class="separator"><div>' . t('General Settings') . '</div></div>';

	$content .= '<div class="form-horizontal">';

	// Plugin Enabled
	$content .= '<div class="form-group">';
	$content .= '<div class="col-sm-2 control-label">' . t('Plugin Enabled') . '</div>';
	$content .= '<div class="col-sm-10">';
	$content .= '<div class="checkbox"><label>';
	$content .= '<input type="checkbox" name="enabled" value="1"' . ($settings['enabled'] ? ' checked' : '') . '> ';
	$content .= t('Enable redirect processing');
	$content .= '</label></div>';
	$content .= '</div></div>';

	// Redirect Method
	$content .= '<div class="form-group">';
	$content .= '<label class="col-sm-2 control-label">' . t('Redirect Method') . '</label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<div class="radio"><label>';
	$content .= '<input type="radio" name="redirectMethod" value="php"' . ($settings['redirectMethod'] === 'php' ? ' checked' : '') . '> ';
	$content .= '<strong>' . t('PHP Dispatcher') . '</strong> - ' . t('Recommended. Handles redirects via PHP, supports hit tracking and patterns.');
	$content .= '</label></div>';
	$content .= '<div class="radio"><label>';
	$content .= '<input type="radio" name="redirectMethod" value="htaccess"' . ($settings['redirectMethod'] === 'htaccess' ? ' checked' : '') . '> ';
	$content .= '<strong>' . t('.htaccess Rules') . '</strong> - ' . t('Faster but requires regeneration on changes. No hit tracking.');
	$content .= '</label></div>';
	$content .= '</div></div>';

	// Default Redirect Type
	$content .= '<div class="form-group">';
	$content .= '<label for="defaultRedirectType" class="col-sm-2 control-label">' . t('Default Type') . '</label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<select name="defaultRedirectType" id="defaultRedirectType" class="form-control" style="width:150px">';
	$content .= '<option value="301"' . ($settings['defaultRedirectType'] === '301' ? ' selected' : '') . '>301 - ' . t('Permanent') . '</option>';
	$content .= '<option value="302"' . ($settings['defaultRedirectType'] === '302' ? ' selected' : '') . '>302 - ' . t('Temporary') . '</option>';
	$content .= '</select>';
	$content .= '</div></div>';

	$content .= '</div>'; // end form-horizontal

	// Matching Options
	$content .= '<div class="separator"><div>' . t('Matching Options') . '</div></div>';

	$content .= '<div class="form-horizontal">';

	// Enable Wildcards
	$content .= '<div class="form-group">';
	$content .= '<div class="col-sm-2 control-label"></div>';
	$content .= '<div class="col-sm-10">';
	$content .= '<div class="checkbox"><label>';
	$content .= '<input type="checkbox" name="enableWildcards" value="1"' . ($settings['enableWildcards'] ? ' checked' : '') . '> ';
	$content .= t('Enable wildcard patterns (e.g., /blog/*)');
	$content .= '</label></div>';
	$content .= '</div></div>';

	// Case Sensitive
	$content .= '<div class="form-group">';
	$content .= '<div class="col-sm-2 control-label"></div>';
	$content .= '<div class="col-sm-10">';
	$content .= '<div class="checkbox"><label>';
	$content .= '<input type="checkbox" name="caseSensitive" value="1"' . ($settings['caseSensitive'] ? ' checked' : '') . '> ';
	$content .= t('Case sensitive URL matching');
	$content .= '</label></div>';
	$content .= '</div></div>';

	// Preserve Query String
	$content .= '<div class="form-group">';
	$content .= '<div class="col-sm-2 control-label"></div>';
	$content .= '<div class="col-sm-10">';
	$content .= '<div class="checkbox"><label>';
	$content .= '<input type="checkbox" name="preserveQueryString" value="1"' . ($settings['preserveQueryString'] ? ' checked' : '') . '> ';
	$content .= t('Preserve query strings when redirecting');
	$content .= '</label></div>';
	$content .= '</div></div>';

	$content .= '</div>'; // end form-horizontal

	// Tracking
	$content .= '<div class="separator"><div>' . t('Tracking') . '</div></div>';

	$content .= '<div class="form-horizontal">';

	// Track Hits
	$content .= '<div class="form-group">';
	$content .= '<div class="col-sm-2 control-label"></div>';
	$content .= '<div class="col-sm-10">';
	$content .= '<div class="checkbox"><label>';
	$content .= '<input type="checkbox" name="trackHits" value="1"' . ($settings['trackHits'] ? ' checked' : '') . '> ';
	$content .= t('Track redirect hit counts');
	$content .= '</label></div>';
	$content .= '</div></div>';

	// Log Retention
	$content .= '<div class="form-group">';
	$content .= '<label for="logRetentionDays" class="col-sm-2 control-label">' . t('Log Retention') . '</label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<input type="number" class="form-control" style="width:100px;display:inline-block" name="logRetentionDays" id="logRetentionDays" value="' . intval($settings['logRetentionDays']) . '" min="0" max="365">';
	$content .= ' <span class="help-inline">' . t('days (0 = never clear)') . '</span>';
	$content .= '</div></div>';

	$content .= '</div>'; // end form-horizontal

	// Notifications
	$content .= '<div class="separator"><div>' . t('Notifications') . '</div></div>';

	$content .= '<div class="form-horizontal">';

	// Notification Email
	$content .= '<div class="form-group">';
	$content .= '<label for="notificationEmail" class="col-sm-2 control-label">' . t('Email') . '</label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<input type="email" class="form-control" style="max-width:300px" name="notificationEmail" id="notificationEmail" value="' . \htmlencode($settings['notificationEmail']) . '" placeholder="admin@example.com">';
	$content .= '<p class="help-block">' . t('Email for chain/loop warnings') . '</p>';
	$content .= '</div></div>';

	// Email on 404
	$content .= '<div class="form-group">';
	$content .= '<div class="col-sm-2 control-label"></div>';
	$content .= '<div class="col-sm-10">';
	$content .= '<div class="checkbox"><label>';
	$content .= '<input type="checkbox" name="emailOn404" value="1"' . ($settings['emailOn404'] ? ' checked' : '') . '> ';
	$content .= t('Email when 404 hits a near-match (future feature)');
	$content .= '</label></div>';
	$content .= '</div></div>';

	$content .= '</div>'; // end form-horizontal

	// Advanced
	$content .= '<div class="separator"><div>' . t('Advanced') . '</div></div>';

	$content .= '<div class="form-horizontal">';

	// Max Chain Depth
	$content .= '<div class="form-group">';
	$content .= '<label for="maxRedirectChain" class="col-sm-2 control-label">' . t('Max Chain Depth') . '</label>';
	$content .= '<div class="col-sm-10">';
	$content .= '<input type="number" class="form-control" style="width:80px;display:inline-block" name="maxRedirectChain" id="maxRedirectChain" value="' . intval($settings['maxRedirectChain']) . '" min="1" max="10">';
	$content .= ' <span class="help-inline">' . t('hops before warning') . '</span>';
	$content .= '</div></div>';

	$content .= '</div>'; // end form-horizontal

	$adminUI['CONTENT'] = $content;

	\adminUI($adminUI);
	exit;
}

/**
 * Help page - Display plugin documentation
 */
function adminHelp(): void
{
	$adminUI = [];

	$adminUI['PAGE_TITLE'] = [
		t("Plugins") => '?menu=admin&action=plugins',
		t("Redirect Manager") => '?_pluginAction=RedirectManager\adminDashboard',
		t("Help"),
	];

	$adminUI['ADVANCED_ACTIONS'] = getAdvancedActions();

	$content = '';

	// Plugin navigation
	$content .= getPluginNav('help');

	// Overview Section
	$content .= '<div class="separator"><div>' . t('Overview') . '</div></div>';

	$content .= '<p>Manage URL redirects through the admin interface instead of manually editing .htaccess files. Maintain SEO equity when URLs change and gain visibility into redirect usage.</p>';

	$content .= '<p><strong>' . t('Features:') . '</strong></p>';
	$content .= '<ul>';
	$content .= '<li><strong>301/302 Redirects</strong> - Permanent and temporary redirects for SEO</li>';
	$content .= '<li><strong>Wildcard Patterns</strong> - Use * for bulk redirects (e.g., /blog/* to /articles/$1)</li>';
	$content .= '<li><strong>Hit Tracking</strong> - Monitor how often each redirect is used</li>';
	$content .= '<li><strong>Chain Detection</strong> - Identifies redirect chains that slow down page loads</li>';
	$content .= '<li><strong>Loop Prevention</strong> - Prevents circular redirects that would break pages</li>';
	$content .= '<li><strong>Import/Export</strong> - CSV support for bulk management</li>';
	$content .= '<li><strong>URL Testing</strong> - Test URLs to see what redirect would apply</li>';
	$content .= '</ul>';

	// Installation Section
	$content .= '<div class="separator"><div>' . t('Installation') . '</div></div>';

	$content .= '<ol>';
	$content .= '<li>Copy the <code>redirectManager</code> folder to your plugins directory:<ul>';
	$content .= '<li><code>/cmsb/plugins/redirectManager/</code> (standard CMSB installation)</li>';
	$content .= '</ul></li>';
	$content .= '<li>Copy <code>redirectmanager_menu.schema.php</code> to your schema directory</li>';
	$content .= '<li>Clear the schema cache in Admin &gt; General Settings</li>';
	$content .= '<li>The plugin will automatically create required database tables</li>';
	$content .= '<li>Navigate to <strong>Redirect Manager &gt; Dashboard</strong> to get started</li>';
	$content .= '</ol>';

	// PHP Dispatcher Setup
	$content .= '<div class="separator"><div>' . t('PHP Dispatcher Setup') . '</div></div>';

	$content .= '<p>For the redirects to work automatically, add this line to your .htaccess file (adjust path to match your installation):</p>';
	$content .= '<pre style="background:#f5f5f5;padding:10px;border-radius:4px">ErrorDocument 404 /cmsb/plugins/redirectManager/dispatcher.php</pre>';
	$content .= '<p>This routes 404 errors through the redirect dispatcher, which checks for matching redirects before showing the 404 page.</p>';

	// Redirect Types Section
	$content .= '<div class="separator"><div>' . t('Redirect Types') . '</div></div>';

	$content .= '<div class="table-responsive">';
	$content .= '<table class="table table-striped">';
	$content .= '<thead><tr><th>' . t('Type') . '</th><th>' . t('Name') . '</th><th>' . t('Use Case') . '</th></tr></thead>';
	$content .= '<tbody>';
	$content .= '<tr><td><span class="badge" style="background-color:#28a745;color:#fff">301</span></td><td>Permanent</td><td>Page has permanently moved. Preserves SEO value. Use for site restructuring.</td></tr>';
	$content .= '<tr><td><span class="badge" style="background-color:#ffc107;color:#212529">302</span></td><td>Temporary</td><td>Page will return to original URL. Use for A/B testing or maintenance.</td></tr>';
	$content .= '<tr><td><span class="badge" style="background-color:#17a2b8;color:#fff">307</span></td><td>Temporary (Strict)</td><td>Same as 302 but preserves request method (POST stays POST).</td></tr>';
	$content .= '<tr><td><span class="badge" style="background-color:#007bff;color:#fff">308</span></td><td>Permanent (Strict)</td><td>Same as 301 but preserves request method.</td></tr>';
	$content .= '</tbody></table>';
	$content .= '</div>';

	// Wildcard Patterns Section
	$content .= '<div class="separator"><div>' . t('Wildcard Patterns') . '</div></div>';

	$content .= '<p>Use <code>*</code> as a wildcard to match any characters. Use <code>$1</code>, <code>$2</code>, etc. in the destination to insert captured groups.</p>';

	$content .= '<div class="table-responsive">';
	$content .= '<table class="table table-striped">';
	$content .= '<thead><tr><th>' . t('Pattern') . '</th><th>' . t('Matches') . '</th><th>' . t('Example') . '</th></tr></thead>';
	$content .= '<tbody>';
	$content .= '<tr><td><code>/blog/*</code></td><td>Anything under /blog/</td><td>/blog/post-1, /blog/2024/jan</td></tr>';
	$content .= '<tr><td><code>/page-*</code></td><td>Pages starting with page-</td><td>/page-1, /page-about</td></tr>';
	$content .= '<tr><td><code>*.html</code></td><td>Any .html file</td><td>/about.html, /contact.html</td></tr>';
	$content .= '</tbody></table>';
	$content .= '</div>';

	$content .= '<p><strong>Example with capture groups:</strong></p>';
	$content .= '<ul>';
	$content .= '<li>Source: <code>/old-blog/*</code></li>';
	$content .= '<li>Destination: <code>/new-blog/$1</code></li>';
	$content .= '<li>Result: <code>/old-blog/my-post</code> redirects to <code>/new-blog/my-post</code></li>';
	$content .= '</ul>';

	// Chain and Loop Detection Section
	$content .= '<div class="separator"><div>' . t('Chain and Loop Detection') . '</div></div>';

	$content .= '<p><strong>Redirect Chains</strong> occur when one redirect points to another redirect (A &rarr; B &rarr; C). Each hop adds latency and can hurt SEO. The plugin warns when chains exceed the configured maximum depth.</p>';

	$content .= '<p><strong>Redirect Loops</strong> occur when redirects form a circle (A &rarr; B &rarr; A). These cause infinite redirect errors and break pages. The plugin prevents you from creating loops.</p>';

	// Import/Export Section
	$content .= '<div class="separator"><div>' . t('Import/Export') . '</div></div>';

	$content .= '<p>Use CSV files to bulk import or export redirects. The CSV format is:</p>';

	$content .= '<pre style="background:#f5f5f5;padding:10px;border-radius:4px">source_url,destination_url,redirect_type,is_active,notes
/old-page,/new-page,301,1,"Moved in redesign"
/blog/*,/articles/$1,301,1,"Blog section renamed"</pre>';

	// Settings Section
	$content .= '<div class="separator"><div>' . t('Settings Reference') . '</div></div>';

	$content .= '<div class="table-responsive">';
	$content .= '<table class="table table-striped">';
	$content .= '<thead><tr><th>' . t('Setting') . '</th><th>' . t('Description') . '</th><th>' . t('Default') . '</th></tr></thead>';
	$content .= '<tbody>';
	$content .= '<tr><td>Plugin Enabled</td><td>Master enable/disable switch</td><td>Enabled</td></tr>';
	$content .= '<tr><td>Redirect Method</td><td>PHP Dispatcher (recommended) or .htaccess Rules</td><td>PHP</td></tr>';
	$content .= '<tr><td>Default Type</td><td>Default redirect type for new entries</td><td>301</td></tr>';
	$content .= '<tr><td>Track Hits</td><td>Count how many times each redirect fires</td><td>Enabled</td></tr>';
	$content .= '<tr><td>Log Retention</td><td>Days to keep hit statistics</td><td>90</td></tr>';
	$content .= '<tr><td>Enable Wildcards</td><td>Allow wildcard patterns in source URLs</td><td>Enabled</td></tr>';
	$content .= '<tr><td>Case Sensitive</td><td>Case-sensitive URL matching</td><td>Disabled</td></tr>';
	$content .= '<tr><td>Preserve Query String</td><td>Pass query strings to destination URL</td><td>Enabled</td></tr>';
	$content .= '<tr><td>Max Chain Depth</td><td>Maximum chain depth before warning</td><td>3</td></tr>';
	$content .= '</tbody></table>';
	$content .= '</div>';

	// Troubleshooting Section
	$content .= '<div class="separator"><div>' . t('Troubleshooting') . '</div></div>';

	$content .= '<p><strong>Redirects not working</strong></p>';
	$content .= '<ul>';
	$content .= '<li>Verify the plugin is enabled in Settings</li>';
	$content .= '<li>Check that the redirect is marked as Active</li>';
	$content .= '<li>For PHP method: Ensure dispatcher.php is set as ErrorDocument 404</li>';
	$content .= '<li>For .htaccess method: Regenerate rules from Dashboard</li>';
	$content .= '<li>Use the Test URL feature to verify matching</li>';
	$content .= '</ul>';

	$content .= '<p><strong>Wildcard not matching</strong></p>';
	$content .= '<ul>';
	$content .= '<li>Ensure "Enable Wildcards" is checked in Settings</li>';
	$content .= '<li>Verify the pattern syntax is correct</li>';
	$content .= '<li>Check case sensitivity settings</li>';
	$content .= '</ul>';

	// Requirements Section
	$content .= '<div class="separator"><div>' . t('Requirements') . '</div></div>';

	$content .= '<ul>';
	$content .= '<li>CMS Builder 3.50 or higher</li>';
	$content .= '<li>PHP 8.0 or higher</li>';
	$content .= '<li>MySQL 5.7+ or MariaDB 10.2+</li>';
	$content .= '<li>Apache with mod_rewrite (for .htaccess method)</li>';
	$content .= '</ul>';

	// Version Section
	$content .= '<div class="separator"><div>' . t('Version Information') . '</div></div>';

	$content .= '<p><strong>Version:</strong> ' . ($GLOBALS['REDIRECTMANAGER_VERSION'] ?? '1.00') . '</p>';
	$content .= '<p><strong>Author:</strong> <a href="https://www.sagentic.com" target="_blank" rel="noopener">Sagentic Web Design <span class="sr-only">' . t('(opens in new tab)') . '</span></a></p>';

	$adminUI['CONTENT'] = $content;

	\adminUI($adminUI);
	exit;
}

/**
 * Render redirect type badge
 *
 * @param string $type Redirect type (301, 302, etc.)
 * @return string HTML badge
 */
function renderTypeBadge(string $type): string
{
	$badges = [
		'301' => '<span class="badge" style="background-color:#28a745;color:#fff">301</span>',
		'302' => '<span class="badge" style="background-color:#ffc107;color:#212529">302</span>',
		'307' => '<span class="badge" style="background-color:#17a2b8;color:#fff">307</span>',
		'308' => '<span class="badge" style="background-color:#007bff;color:#fff">308</span>',
	];
	return $badges[$type] ?? '<span class="badge" style="background-color:#6c757d;color:#fff">' . \htmlencode($type) . '</span>';
}

/**
 * Render status toggle link
 *
 * @param int $num Redirect record number
 * @param bool $isActive Current active status
 * @return string HTML toggle link
 */
function renderStatusToggle(int $num, bool $isActive): string
{
	$csrfToken = $_SESSION['_CSRFToken'] ?? '';
	$action = $isActive ? 'deactivate' : 'activate';
	$icon = $isActive ? 'fa-toggle-on text-success' : 'fa-toggle-off text-muted';
	$title = $isActive ? t('Click to deactivate') : t('Click to activate');

	return '<a href="?_pluginAction=RedirectManager\adminRedirects&action=' . $action . '&num=' . $num . '&_CSRFToken=' . urlencode($csrfToken) . '" title="' . $title . '">'
		. '<i class="fa-duotone fa-solid ' . $icon . '" aria-hidden="true"></i>'
		. '<span class="sr-only">' . $title . '</span>'
		. '</a>';
}
