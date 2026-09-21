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


namespace Modules\StateTimeline\Includes;

use API,
	CHistoryManager,
	CHousekeepingHelper,
	CMacrosResolverHelper,
	CParser,
	CSettingsHelper,
	CSimpleIntervalParser,
	Manager;

/**
 * Loads item history and converts it to state runs.
 *
 * A run is a time interval in which an item has the same raw value (and therefore the same state). Runs are sent to
 * the browser instead of history values, so that the size of the response depends on the number of state changes
 * rather than on the number of collected values.
 *
 * History is loaded in one of two ways:
 *  - exact: raw history values (API history.get, batched), if the total number of values fits the configured limit;
 *    state changes are bound to the exact timestamps of history values;
 *  - aggregated: min/max per pixel (Manager::History()->getGraphAggregationByWidth(), the same method as the Graph
 *    widget uses), with history or trends as a data source chosen the same way as the Graph widget does;
 *    state changes are bound to the pixel, a pixel with both states is marked as "mixed".
 */
class CStateTimelineHelper {

	// Run states.
	public const STATE_NODATA = -1;
	public const STATE_0 = 0;
	public const STATE_1 = 1;
	public const STATE_MIXED = 2;

	// Maximum number of history values loaded by one API request.
	private const HISTORY_CHUNK_SIZE = 50000;

	// Maximum number of distinct values (value labels) sent to the browser per item.
	private const MAX_DISTINCT_VALUES = 200;

	/**
	 * Get state runs of the given items.
	 *
	 * @param array      $items                   Items (itemid, value_type, units, valuemap, history, trends).
	 * @param array      $options
	 * @param int        $options['time_from']
	 * @param int        $options['time_to']
	 * @param int        $options['width']         Timeline width in pixels.
	 * @param float|null $options['threshold']     Value at or above which the state is 1. Null: any non-zero value.
	 * @param int|null   $options['nodata_after']  Period after the last value, after which the state is unknown.
	 * @param int        $options['raw_values_limit']
	 *
	 * @return array  Timelines, indexed by itemid.
	 */
	public static function getTimelines(array $items, array $options): array {
		$time_from = $options['time_from'];
		$time_to = $options['time_to'];
		$width = max(1, $options['width']);
		$end = min(time(), $time_to);

		if (!$items || $end <= $time_from) {
			return [];
		}

		$items = self::resolveStoragePeriods($items);

		// Aggregated trends are used for long periods and for items without history, as in the Graph widget.
		$use_history = ($time_to - $time_from) / $width <= ZBX_MAX_TREND_DIFF / ZBX_GRAPH_MAX_SKIP_CELL;

		$history_items = [];
		$exact_candidates = [];

		foreach ($items as $itemid => $item) {
			if ($item['trends'] == 0 || time() - $item['history'] < $time_from) {
				$history_items[$itemid] = $item;

				if ($use_history || $item['trends'] == 0) {
					$exact_candidates[$itemid] = $item;
				}
			}
		}

		/*
		 * Values of items are loaded in one of two ways:
		 *  - changes only: the database returns only the values differing from the previous one (SQL storage on
		 *    databases supporting window functions), which is the number of state changes instead of the number of
		 *    collected values;
		 *  - all values: history.get of items fitting the limit of raw values (other storages and databases).
		 */
		$changes = self::getChangeValues($items, $exact_candidates, $time_from, $time_to,
			$options['raw_values_limit']
		);

		$exact_itemids = $changes !== null
			? array_fill_keys(array_keys($exact_candidates), 0)
			: self::selectExactItems($exact_candidates, $time_from, $time_to, $options['raw_values_limit']);

		$aggregated_sources = [];

		foreach ($items as $itemid => $item) {
			if (array_key_exists($itemid, $exact_itemids)) {
				continue;
			}

			$aggregated_sources[$itemid] = array_key_exists($itemid, $history_items)
					&& ($use_history || $item['trends'] == 0)
				? 'history'
				: 'trends';
		}

		$values_by_item = $changes ?? self::getHistoryValues($items, array_keys($exact_itemids), $exact_itemids,
			$time_from, $time_to
		);

		// Items having a value within the first pixel of the timeline do not need the value before the period.
		$tolerance = max(1, (int) (($time_to - $time_from) / $width));
		$known_at_start = [];

		if ($changes !== null) {
			foreach ($changes as $itemid => $values) {
				if ($values && $values[0]['clock'] - $time_from <= $tolerance) {
					$known_at_start[$itemid] = true;
				}
			}
		}

		$prev_values = self::getPreviousValues($items, $known_at_start, $time_from, $options['nodata_after']);

		$timelines = [];

		foreach ($values_by_item as $itemid => $values) {
			$runs = self::buildRunsFromValues($values, $prev_values[$itemid] ?? null, $time_from, $end,
				$options['nodata_after'], $options['threshold'], array_key_exists($itemid, $known_at_start)
			);

			// Too many changes to be useful at the current zoom level: show aggregated data instead.
			if (count($runs['t']) > $width * 4) {
				$aggregated_sources[$itemid] = $use_history || $items[$itemid]['trends'] == 0 ? 'history' : 'trends';

				continue;
			}

			$timelines[$itemid] = $runs + ['exact' => true, 'source' => 'history'];
		}

		if ($aggregated_sources) {
			$aggregation_items = [];

			foreach ($aggregated_sources as $itemid => $source) {
				$aggregation_items[] = [
					'itemid' => $itemid,
					'value_type' => $items[$itemid]['value_type'],
					'source' => $source
				];
			}

			$results = Manager::History()->getGraphAggregationByWidth($aggregation_items, $time_from, $time_to,
				$width
			);

			foreach ($aggregated_sources as $itemid => $source) {
				$rows = array_key_exists($itemid, $results) ? $results[$itemid]['data'] : [];

				$timelines[$itemid] = self::buildRunsFromBuckets($rows, $prev_values[$itemid] ?? null, $time_from,
					$time_to, $end, $width, $source === 'trends' ? SEC_PER_HOUR : 0, $options['nodata_after'],
					$options['threshold']
				) + ['exact' => false, 'source' => $source];
			}
		}

		foreach ($timelines as $itemid => &$timeline) {
			$timeline['values'] = self::formatValues($timeline['values'], $items[$itemid]);
		}
		unset($timeline);

		return $timelines;
	}

	/**
	 * Get the state of the given value.
	 */
	public static function getState(float $value, ?float $threshold): int {
		if ($threshold === null) {
			return $value != 0 ? self::STATE_1 : self::STATE_0;
		}

		return $value >= $threshold ? self::STATE_1 : self::STATE_0;
	}

	/**
	 * Get the state of values in the given range. STATE_MIXED is returned, if the range contains both states.
	 */
	public static function getRangeState(float $min, float $max, ?float $threshold): int {
		if ($threshold === null) {
			if ($min == 0 && $max == 0) {
				return self::STATE_0;
			}

			return $min > 0 || $max < 0 ? self::STATE_1 : self::STATE_MIXED;
		}

		if ($min >= $threshold) {
			return self::STATE_1;
		}

		return $max < $threshold ? self::STATE_0 : self::STATE_MIXED;
	}

	/**
	 * Convert raw history values to runs.
	 *
	 * Values are treated as a step function: a value is valid until the next value is collected, or during
	 * $nodata_after seconds, if specified.
	 *
	 * @param array      $values        History values (clock, ns, value), sorted by time.
	 * @param array|null $prev_value    Last value (clock, value) before the period, or null.
	 * @param int        $time_from     Period start.
	 * @param int        $end           Period end, not later than now.
	 * @param int|null   $nodata_after
	 * @param float|null $threshold
	 * @param bool       $first_value_from_start  Whether the first value is valid from the period start. Used for
	 *                                            items having a value within the first pixel of the timeline, for
	 *                                            which the value before the period is not loaded.
	 *
	 * @return array
	 */
	public static function buildRunsFromValues(array $values, ?array $prev_value, int $time_from, int $end,
			?int $nodata_after, ?float $threshold, bool $first_value_from_start = false): array {
		$builder = new CStateRunsBuilder($time_from, $end, $nodata_after);

		if ($prev_value !== null) {
			$builder->addValue($time_from, (float) $prev_value['clock'], self::getState((float) $prev_value['value'],
				$threshold), self::normalizeValue($prev_value['value'])
			);
		}

		$from_start = $first_value_from_start && $prev_value === null;

		foreach ($values as $value) {
			$clock = $value['clock'] + $value['ns'] / 1000000000;

			$builder->addValue($from_start ? $time_from : $clock, $clock,
				self::getState((float) $value['value'], $threshold), self::normalizeValue($value['value'])
			);

			$from_start = false;
		}

		// The state at the period start is not known exactly: either the value before the period was used, or the
		// first value of the period was assumed to be valid from the period start.
		return $builder->getRuns()
			+ ['before' => ($prev_value !== null || $first_value_from_start) && !$builder->startsWithGap()];
	}

	/**
	 * Convert per-pixel aggregated values (min, max, clock as returned by getGraphAggregationByWidth) to runs.
	 *
	 * @param array      $rows          Aggregated values, sorted by time.
	 * @param array|null $prev_value    Last value (clock, value) before the period, or null.
	 * @param int        $time_from     Period start.
	 * @param int        $time_to       Period end, as requested (used to calculate pixel positions).
	 * @param int        $end           Period end, not later than now.
	 * @param int        $width         Number of pixels.
	 * @param int        $row_period    Period covered by one source row: 0 for history, 3600 for trends.
	 * @param int|null   $nodata_after
	 * @param float|null $threshold
	 *
	 * @return array
	 */
	public static function buildRunsFromBuckets(array $rows, ?array $prev_value, int $time_from, int $time_to,
			int $end, int $width, int $row_period, ?int $nodata_after, ?float $threshold): array {
		$builder = new CStateRunsBuilder($time_from, $end, $nodata_after);

		if ($prev_value !== null) {
			$builder->addValue($time_from, (float) $prev_value['clock'], self::getState((float) $prev_value['value'],
				$threshold), self::normalizeValue($prev_value['value'])
			);
		}

		$period = $time_to - $time_from;

		usort($rows, static fn(array $a, array $b): int => $a['clock'] <=> $b['clock']);

		foreach ($rows as $row) {
			// Pixel index, calculated the same way as in the SQL query of getGraphAggregationByWidth.
			$index = round($width * ($row['clock'] - $time_from) / $period);
			$start = max($time_from, $time_from + ($index - 0.5) * $period / $width);

			$min = self::normalizeValue($row['min']);
			$max = self::normalizeValue($row['max']);

			$builder->addValue($start, $row['clock'] + ($row_period > 0 ? $row_period - 1 : 0),
				self::getRangeState((float) $min, (float) $max, $threshold),
				$min === $max ? $min : [$min, $max]
			);
		}

		return $builder->getRuns() + ['before' => $prev_value !== null && !$builder->startsWithGap()];
	}

	/**
	 * Normalize a value for comparison: "1.0000" and "1" are the same value.
	 */
	private static function normalizeValue($value): string {
		$value = (string) $value;

		return strpbrk($value, '.eE') !== false ? (string) (float) $value : $value;
	}

	/**
	 * Resolve history and trends storage periods of items to seconds, taking global housekeeping overrides into
	 * account, same as the Graph widget does.
	 */
	private static function resolveStoragePeriods(array $items): array {
		$hk_history_global = CHousekeepingHelper::get(CHousekeepingHelper::HK_HISTORY_GLOBAL);
		$hk_trends_global = CHousekeepingHelper::get(CHousekeepingHelper::HK_TRENDS_GLOBAL);

		$to_resolve = [];

		if (!$hk_history_global) {
			$to_resolve[] = 'history';
		}

		if (!$hk_trends_global) {
			$to_resolve[] = 'trends';
		}

		if ($to_resolve) {
			$items = CMacrosResolverHelper::resolveTimeUnitMacros($items, $to_resolve);
		}

		$parser = new CSimpleIntervalParser();

		foreach ($items as &$item) {
			foreach (['history' => CHousekeepingHelper::HK_HISTORY, 'trends' => CHousekeepingHelper::HK_TRENDS]
					as $field => $hk_field) {
				if (in_array($field, $to_resolve)) {
					$item[$field] = $parser->parse($item[$field]) == CParser::PARSE_SUCCESS
						? (int) timeUnitToSeconds($item[$field])
						: 0;
				}
				elseif ($item[$field] != 0) {
					$item[$field] = (int) timeUnitToSeconds(CHousekeepingHelper::get($hk_field));
				}
				else {
					$item[$field] = 0;
				}
			}
		}
		unset($item);

		return $items;
	}

	/**
	 * Select items, which raw history values will be loaded. Items having less values are preferred, so that as many
	 * items as possible are displayed exactly.
	 *
	 * @return array  Number of values, indexed by itemid.
	 */
	private static function selectExactItems(array $items, int $time_from, int $time_to, int $limit): array {
		if (!$items || $limit == 0) {
			return [];
		}

		$count_items = [];

		foreach ($items as $itemid => $item) {
			$count_items[] = ['itemid' => $itemid, 'value_type' => $item['value_type'], 'source' => 'history'];
		}

		$counts = [];

		foreach (Manager::History()->getAggregatedValues($count_items, AGGREGATE_COUNT, $time_from, $time_to)
				as $itemid => $row) {
			$counts[$itemid] = (int) $row['value'];
		}

		foreach ($items as $itemid => $item) {
			$counts += [$itemid => 0];
		}

		asort($counts);

		$exact_itemids = [];
		$total = 0;

		foreach ($counts as $itemid => $count) {
			if ($total + $count > $limit) {
				break;
			}

			$exact_itemids[$itemid] = $count;
			$total += $count;
		}

		return $exact_itemids;
	}

	/**
	 * Load raw history values of the given items, in batches of at most HISTORY_CHUNK_SIZE values (as far as the
	 * number of values of a single item allows).
	 *
	 * @param array $items
	 * @param array $itemids
	 * @param array $counts     Number of values, indexed by itemid.
	 * @param int   $time_from
	 * @param int   $time_to
	 *
	 * @return \Generator  Values (clock, ns, value) sorted by time, indexed by itemid.
	 */
	private static function getHistoryValues(array $items, array $itemids, array $counts, int $time_from,
			int $time_to): \Generator {
		$chunks = [];

		foreach ($itemids as $itemid) {
			$value_type = $items[$itemid]['value_type'];
			$last = array_key_exists($value_type, $chunks) ? count($chunks[$value_type]) - 1 : -1;

			if ($last == -1 || $chunks[$value_type][$last]['count'] + $counts[$itemid] > self::HISTORY_CHUNK_SIZE) {
				$chunks[$value_type][] = ['itemids' => [], 'count' => 0];
				$last++;
			}

			$chunks[$value_type][$last]['itemids'][] = $itemid;
			$chunks[$value_type][$last]['count'] += $counts[$itemid];
		}

		foreach ($chunks as $value_type => $value_type_chunks) {
			foreach ($value_type_chunks as $chunk) {
				$values = array_fill_keys($chunk['itemids'], []);

				if ($chunk['count'] > 0) {
					$db_values = API::History()->get([
						'output' => ['itemid', 'clock', 'ns', 'value'],
						'history' => $value_type,
						'itemids' => $chunk['itemids'],
						'time_from' => $time_from,
						'time_till' => $time_to,
						'sortfield' => ['itemid', 'clock', 'ns'],
						'sortorder' => ZBX_SORT_UP
					]);

					foreach ($db_values ?: [] as $db_value) {
						$values[$db_value['itemid']][] = $db_value;
					}

					unset($db_values);
				}

				foreach ($values as $itemid => $item_values) {
					yield $itemid => $item_values;
				}
			}
		}
	}

	/**
	 * Get the last value before the period for items which state at the period start is not known otherwise.
	 *
	 * Values are searched within the "Max history display period" (Administration > General > GUI), same as for
	 * "last value" in other parts of the frontend. Items having a value within the first pixel of the timeline are
	 * skipped: the lookback query is the most expensive one on large history tables, and such items would be drawn
	 * the same way anyway.
	 *
	 * @param array    $items
	 * @param array    $known_at_start  Items which state at the period start is known, indexed by itemid.
	 * @param int      $time_from
	 * @param int|null $nodata_after
	 *
	 * @return array  Values (clock, value), indexed by itemid.
	 */
	private static function getPreviousValues(array $items, array $known_at_start, int $time_from,
			?int $nodata_after): array {
		$last_items = [];

		foreach ($items as $itemid => $item) {
			if (!array_key_exists($itemid, $known_at_start)) {
				$last_items[] = ['itemid' => $itemid, 'value_type' => $item['value_type'], 'source' => 'history'];
			}
		}

		if (!$last_items) {
			return [];
		}

		$lookback = timeUnitToSeconds(CSettingsHelper::get(CSettingsHelper::HISTORY_PERIOD));

		if ($nodata_after !== null) {
			$lookback = min($lookback, $nodata_after);
		}

		return Manager::History()->getAggregatedValues($last_items, AGGREGATE_LAST, $time_from - $lookback,
			$time_from - 1
		) ?: [];
	}

	/**
	 * Load values of items from history, returning only the values differing from the previous value of the item.
	 *
	 * The comparison is done by the database (window function), so the number of returned and processed rows is the
	 * number of value changes instead of the number of collected values. Used for SQL history storage on MySQL and
	 * PostgreSQL; other storages and databases fall back to loading all values.
	 *
	 * @param array $items
	 * @param array $history_items  Items to load, indexed by itemid.
	 * @param int   $time_from
	 * @param int   $time_to
	 * @param int   $limit          Maximum total number of returned values.
	 *
	 * @return array|null  Values (clock, ns, value) sorted by time, indexed by itemid. Null if not supported or if
	 *                     the limit is exceeded.
	 */
	private static function getChangeValues(array $items, array $history_items, int $time_from, int $time_to,
			int $limit): ?array {
		global $DB;

		if (!$history_items || $limit == 0
				|| !in_array($DB['TYPE'], [ZBX_DB_MYSQL, ZBX_DB_POSTGRESQL], true)) {
			return null;
		}

		$itemids_by_type = [];

		foreach ($history_items as $itemid => $item) {
			if (CHistoryManager::getDataSourceType($item['value_type']) != ZBX_HISTORY_SOURCE_SQL) {
				return null;
			}

			$itemids_by_type[$item['value_type']][] = $itemid;
		}

		$time_from_history = CHousekeepingHelper::get(CHousekeepingHelper::HK_HISTORY_GLOBAL)
			? max($time_from, time() - timeUnitToSeconds(CHousekeepingHelper::get(CHousekeepingHelper::HK_HISTORY)) + 1)
			: $time_from;

		if ($time_from_history > $time_to) {
			return array_fill_keys(array_keys($history_items), []);
		}

		$values = array_fill_keys(array_keys($history_items), []);
		$count = 0;

		foreach ($itemids_by_type as $value_type => $itemids) {
			$table = CHistoryManager::getTableName($value_type);

			$result = DBselect(
				'SELECT h.itemid,h.clock,h.ns,h.value'.
				' FROM ('.
					'SELECT itemid,clock,ns,value,'.
						'LAG(value) OVER (PARTITION BY itemid ORDER BY clock,ns) AS prev_value'.
					' FROM '.$table.
					' WHERE '.dbConditionInt('itemid', $itemids).
						' AND clock>='.zbx_dbstr($time_from_history).
						' AND clock<='.zbx_dbstr($time_to).
				') h'.
				' WHERE h.prev_value IS NULL'.
					' OR h.value<>h.prev_value'.
				' ORDER BY h.itemid,h.clock,h.ns',
				$limit + 1
			);

			while (($row = DBfetch($result)) !== false) {
				if (++$count > $limit) {
					return null;
				}

				$values[$row['itemid']][] = $row;
			}
		}

		return $values;
	}

	/**
	 * Format values (value map, units) for displaying in the tooltip.
	 *
	 * @param array $values  List of normalized values or [min, max] value ranges.
	 * @param array $item
	 *
	 * @return array  List of [raw value, formatted value].
	 */
	private static function formatValues(array $values, array $item): array {
		$result = [];

		foreach ($values as $index => $value) {
			if ($index >= self::MAX_DISTINCT_VALUES) {
				$result[] = is_array($value) ? [implode(' - ', $value), ''] : [$value, ''];

				continue;
			}

			if (is_array($value)) {
				$result[] = [
					implode(' - ', $value),
					formatHistoryValue($value[0], $item).' - '.formatHistoryValue($value[1], $item)
				];
			}
			else {
				$result[] = [$value, formatHistoryValue($value, $item)];
			}
		}

		return $result;
	}
}
