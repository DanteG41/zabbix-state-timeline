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


namespace Modules\StateTimeline;

use Zabbix\Core\CWidget;

class Widget extends CWidget {

	public const MAX_ITEMS_DEFAULT = 100;
	public const ITEMS_PER_PAGE_DEFAULT = 25;
	public const RAW_VALUES_LIMIT_DEFAULT = 200000;

	public function getDefaultName(): string {
		return _('State timeline');
	}

	/**
	 * Maximum number of items displayed by the widget.
	 */
	public function getMaxItems(): int {
		return max(1, (int) $this->getOption('max_items', self::MAX_ITEMS_DEFAULT));
	}

	/**
	 * Maximum number of items displayed at once. More items are reachable by the slider.
	 */
	public function getItemsPerPage(): int {
		return max(1, (int) $this->getOption('items_per_page', self::ITEMS_PER_PAGE_DEFAULT));
	}

	/**
	 * Maximum total number of raw history values loaded to show exact state changes. Items not fitting the limit are
	 * displayed using per-pixel aggregation, the same way as the Graph widget does.
	 */
	public function getRawValuesLimit(): int {
		return max(0, (int) $this->getOption('raw_values_limit', self::RAW_VALUES_LIMIT_DEFAULT));
	}

	public function getTranslationStrings(): array {
		return [
			'class.widget.js' => [
				'No data found' => _('No data found'),
				'Unexpected server error.' => _('Unexpected server error.')
			],
			'class.statetimeline.js' => [
				'Time' => _('Time'),
				'State' => _('State'),
				'Value' => _('Value'),
				'Since' => _('Since'),
				'Until' => _('Until'),
				'Duration' => _('Duration'),
				'now' => _('now'),
				'No data' => _('No data'),
				'Several changes' => _('Several changes'),
				'before %1$s' => _('before %1$s'),
				'Approximate: aggregated data, zoom in for exact time.' =>
					_('Approximate: aggregated data, zoom in for exact time.'),
				'Items %1$s-%2$s of %3$s' => _('Items %1$s-%2$s of %3$s'),
				'%1$s items match the patterns, the first %2$s are displayed.' =>
					_('%1$s items match the patterns, the first %2$s are displayed.'),
				'Too many items to sort all of them, specify more precise patterns.' =>
					_('Too many items to sort all of them, specify more precise patterns.'),
				'S_SECOND_SHORT' => _x('s', 'second short'),
				'S_MINUTE_SHORT' => _x('m', 'minute short'),
				'S_HOUR_SHORT' => _x('h', 'hour short'),
				'S_DAY_SHORT' => _x('d', 'day short'),
				'Jan' => _('Jan'), 'Feb' => _('Feb'), 'Mar' => _('Mar'), 'Apr' => _('Apr'), 'May' => _('May'),
				'Jun' => _('Jun'), 'Jul' => _('Jul'), 'Aug' => _('Aug'), 'Sep' => _('Sep'), 'Oct' => _('Oct'),
				'Nov' => _('Nov'), 'Dec' => _('Dec')
			]
		];
	}
}
