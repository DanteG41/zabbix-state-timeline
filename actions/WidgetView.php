<?php declare(strict_types = 0);
/*
** State timeline widget module for Zabbix 7.0
** Copyright (C) 2026 Mamedaliev Kirill
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
**
** This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
** without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
** See the GNU Affero General Public License for more details.
**
** You should have received a copy of the GNU Affero General Public License along with this program.
** If not, see <https://www.gnu.org/licenses/>.
**/


namespace Modules\StateTimeline\Actions;

use API,
	CArrayHelper,
	CControllerDashboardWidgetView,
	CControllerResponseData,
	CRangeTimeParser,
	CSettingsHelper,
	CUrl;

use Modules\StateTimeline\Includes\{
	CStateTimelineHelper,
	WidgetForm
};

class WidgetView extends CControllerDashboardWidgetView {

	private const WIDTH_MIN = 1;
	private const WIDTH_MAX = 65535;

	protected function init(): void {
		parent::init();

		$this->addValidationRules([
			'edit_mode' => 'in 0,1',
			'contents_width' => 'int32|ge '.self::WIDTH_MIN.'|le '.self::WIDTH_MAX,
			'timeline_width' => 'int32|ge '.self::WIDTH_MIN.'|le '.self::WIDTH_MAX,
			'has_custom_time_period' => 'in 1'
		]);
	}

	protected function doAction(): void {
		$edit_mode = $this->getInput('edit_mode', 0);
		$has_custom_time_period = $this->hasInput('has_custom_time_period');

		$range_time_parser = new CRangeTimeParser();

		$range_time_parser->parse($this->fields_values['time_period']['from']);
		$time_from = $range_time_parser->getDateTime(true)->getTimestamp();

		$range_time_parser->parse($this->fields_values['time_period']['to']);
		$time_to = $range_time_parser->getDateTime(false)->getTimestamp();

		$max_items = $this->widget->getMaxItems();

		['items' => $items, 'total' => $total, 'search_limit' => $search_limit] = $this->getItems($max_items);

		$threshold = trim($this->fields_values['threshold']) !== ''
			? WidgetForm::parseThreshold($this->fields_values['threshold'])
			: null;

		$nodata_after = trim($this->fields_values['nodata_after']) !== ''
			? WidgetForm::parseNoDataAfter($this->fields_values['nodata_after'])
			: null;

		$timelines = CStateTimelineHelper::getTimelines($items, [
			'time_from' => $time_from,
			'time_to' => $time_to,
			'width' => (int) $this->getInput('timeline_width', $this->getInput('contents_width', self::WIDTH_MIN)),
			'threshold' => $threshold,
			'nodata_after' => $nodata_after,
			'raw_values_limit' => $this->widget->getRawValuesLimit()
		]);

		$show_hosts = count(array_unique(array_column($items, 'hostid'))) > 1;
		$backurl = (new CUrl('zabbix.php'))
			->setArgument('action', 'dashboard.view')
			->getUrl();

		$rows = [];

		foreach ($items as $itemid => $item) {
			$rows[] = [
				'itemid' => $itemid,
				'hostid' => $item['hostid'],
				'host' => $item['hosts'][0]['name'],
				'name' => $item['name'],
				'key' => $item['key_'],
				'label' => $show_hosts ? $item['hosts'][0]['name'].NAME_DELIMITER.$item['name'] : $item['name'],
				'menu' => [
					'type' => 'item',
					'data' => [
						'itemid' => $itemid,
						'backurl' => $backurl
					],
					'context' => 'host'
				],
				'timeline' => $timelines[$itemid] ?? null
			];
		}

		$graph_theme = getUserGraphTheme();
		$state_1_color = $this->fields_values['state_1_color'] ?: WidgetForm::STATE_1_COLOR_DEFAULT;
		$state_0_color = $this->fields_values['state_0_color'] ?: WidgetForm::STATE_0_COLOR_DEFAULT;

		$this->setResponse(new CControllerResponseData([
			'name' => $this->getInput('name', $this->widget->getDefaultName()),
			'info' => $this->makeWidgetInfo(),
			'vars' => [
				'timeline' => [
					'rows' => $rows,
					'total' => $total,
					'search_limit' => $search_limit,
					'max_items' => $max_items,
					'items_per_page' => $this->widget->getItemsPerPage(),
					'time_period' => [
						'from' => $this->fields_values['time_period']['from'],
						'to' => $this->fields_values['time_period']['to'],
						'from_ts' => $time_from,
						'to_ts' => $time_to,
						'now_ts' => time()
					],
					'sbox' => !$has_custom_time_period && !$edit_mode,
					'min_period' => ZBX_MIN_PERIOD,
					'states' => [
						CStateTimelineHelper::STATE_1 => [
							'label' => $this->fields_values['state_1_label'],
							'color' => '#'.$state_1_color
						],
						CStateTimelineHelper::STATE_0 => [
							'label' => $this->fields_values['state_0_label'],
							'color' => '#'.$state_0_color
						]
					],
					'nodata_color' => $this->fields_values['nodata_color'] !== ''
						? '#'.$this->fields_values['nodata_color']
						: null,
					'style' => (int) $this->fields_values['style'],
					'segment_labels' => $this->fields_values['segment_labels'] == 1,
					'show_tooltip' => $this->fields_values['show_tooltip'] == 1,
					'show_duration' => $this->fields_values['show_duration'] == 1,
					'theme' => [
						'text' => '#'.$graph_theme['textcolor'],
						'grid' => '#'.$graph_theme['gridcolor']
					],
					'formats' => [
						// Time formats of the X axis of the Graph widget, from the most to the least detailed.
						'axis' => [SVG_GRAPH_DATE_FORMAT, SVG_GRAPH_DATE_FORMAT_SHORT, SVG_GRAPH_DATE_TIME_FORMAT_SHORT,
							TIME_FORMAT, TIME_FORMAT_SECONDS
						],
						'time' => TIME_FORMAT_SECONDS,
						'date_time' => DATE_TIME_FORMAT_SECONDS
					]
				]
			],
			'user' => [
				'debug_mode' => $this->getDebugMode()
			]
		]));
	}

	/**
	 * Find items matching the widget configuration.
	 *
	 * Items are sorted in a stable order (by host and item name, by item name or by item key, then by item ID), so the
	 * displayed items do not change between refreshes.
	 *
	 * @return array  'items' - up to $limit items indexed by itemid, 'total' - number of matching items,
	 *                'search_limit' - true if more than the frontend search limit of items matched, so that only
	 *                the first "search limit" items (by item ID) were sorted.
	 */
	private function getItems(int $limit): array {
		$result = ['items' => [], 'total' => 0, 'search_limit' => false];

		if ($this->isTemplateDashboard() && !$this->fields_values['hostids']) {
			return $result;
		}

		$hostids = $this->fields_values['hostids'] ?: null;

		if (!$this->isTemplateDashboard()) {
			$groupids = $this->fields_values['groupids'] ? getSubGroups($this->fields_values['groupids']) : null;
			$tags = $this->fields_values['host_tags'] ?: null;

			if ($groupids !== null || $tags !== null) {
				$db_hosts = API::Host()->get([
					'output' => [],
					'groupids' => $groupids,
					'hostids' => $hostids,
					'evaltype' => $this->fields_values['evaltype_host'],
					'tags' => $tags,
					'monitored_hosts' => true,
					'preservekeys' => true
				]);

				if (!$db_hosts) {
					return $result;
				}

				$hostids = array_keys($db_hosts);
			}
		}

		$search_field = $this->isTemplateDashboard() ? 'name' : 'name_resolved';
		$patterns = in_array('*', $this->fields_values['items'], true) ? null : $this->fields_values['items'];
		$search = null;

		if ($patterns !== null) {
			switch ($this->fields_values['item_match']) {
				case WidgetForm::ITEM_MATCH_NAME:
					$search = [$search_field => $patterns];
					break;

				case WidgetForm::ITEM_MATCH_KEY:
					$search = ['key_' => $patterns];
					break;

				default:
					$search = [$search_field => $patterns, 'key_' => $patterns];
			}
		}

		$options = [
			'hostids' => $hostids,
			'webitems' => true,
			'monitored' => true,
			'evaltype' => $this->fields_values['evaltype_item'],
			'tags' => $this->fields_values['item_tags'] ?: null,
			'filter' => [
				'value_type' => [ITEM_VALUE_TYPE_UINT64, ITEM_VALUE_TYPE_FLOAT],
				'status' => ITEM_STATUS_ACTIVE
			],
			'search' => $search,
			'searchWildcardsEnabled' => true,
			'searchByAny' => true
		];

		$search_limit = (int) CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);

		$db_items = API::Item()->get($options + [
			'output' => ['itemid', 'hostid', 'name_resolved', 'key_', 'value_type', 'units', 'history', 'trends'],
			'selectHosts' => ['name'],
			'selectValueMap' => ['mappings'],
			'sortfield' => 'itemid',
			'sortorder' => ZBX_SORT_UP,
			'limit' => $search_limit,
			'preservekeys' => true
		]);

		if (!$db_items) {
			return $result;
		}

		// The number of matching items is counted separately only if the search limit is reached.
		$result['search_limit'] = count($db_items) == $search_limit;
		$result['total'] = $result['search_limit']
			? (int) API::Item()->get($options + ['countOutput' => true])
			: count($db_items);

		foreach ($db_items as &$db_item) {
			$db_item['name'] = $db_item['name_resolved'];
			$db_item['hostname'] = $db_item['hosts'][0]['name'];
		}
		unset($db_item);

		switch ($this->fields_values['sortorder']) {
			case WidgetForm::SORT_NAME:
				$sort_fields = ['name', 'hostname', 'itemid'];
				break;

			case WidgetForm::SORT_KEY:
				$sort_fields = ['key_', 'hostname', 'itemid'];
				break;

			default:
				$sort_fields = ['hostname', 'name', 'itemid'];
		}

		CArrayHelper::sort($db_items, $sort_fields);

		$result['items'] = array_column(array_slice($db_items, 0, $limit), null, 'itemid');

		return $result;
	}

	/**
	 * Make widget specific info to show in widget's header.
	 */
	private function makeWidgetInfo(): array {
		$info = [];

		if ($this->hasInput('has_custom_time_period')) {
			$info[] = [
				'icon' => ZBX_ICON_TIME_PERIOD,
				'hint' => relativeDateToText($this->fields_values['time_period']['from'],
					$this->fields_values['time_period']['to']
				)
			];
		}

		return $info;
	}
}
