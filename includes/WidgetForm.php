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

use CNumberParser,
	CParser,
	CSimpleIntervalParser,
	CWidgetsData;

use Zabbix\Widgets\{
	CWidgetField,
	CWidgetForm
};

use Zabbix\Widgets\Fields\{
	CWidgetFieldCheckBox,
	CWidgetFieldColor,
	CWidgetFieldMultiSelectGroup,
	CWidgetFieldMultiSelectHost,
	CWidgetFieldPatternSelectItem,
	CWidgetFieldRadioButtonList,
	CWidgetFieldSelect,
	CWidgetFieldTags,
	CWidgetFieldTextBox,
	CWidgetFieldTimePeriod
};

/**
 * State timeline widget form.
 *
 * Host and item selection follows the Honeycomb widget, time period follows the Graph widget.
 */
class WidgetForm extends CWidgetForm {

	public const ITEM_MATCH_NAME_OR_KEY = 0;
	public const ITEM_MATCH_NAME = 1;
	public const ITEM_MATCH_KEY = 2;

	public const SORT_HOST_NAME = 0;
	public const SORT_NAME = 1;
	public const SORT_KEY = 2;

	// Row styles.
	public const STYLE_SEGMENTS = 0;
	public const STYLE_FLAT = 1;
	public const STYLE_CAPSULES = 2;
	public const STYLE_UPTIME_BARS = 3;
	public const STYLE_GLOSSY = 4;
	public const STYLE_TRACK = 5;
	public const STYLE_HATCHED = 6;

	// Default colors: "OK" and "PROBLEM" (Disaster severity) colors of Zabbix.
	public const STATE_1_COLOR_DEFAULT = '59DB8F';
	public const STATE_0_COLOR_DEFAULT = 'E45959';

	public function addFields(): self {
		return $this
			->addField($this->isTemplateDashboard()
				? null
				: new CWidgetFieldMultiSelectGroup('groupids', _('Host groups'))
			)
			->addField(
				(new CWidgetFieldMultiSelectHost('hostids', _('Hosts')))
					->setDefault($this->isTemplateDashboard()
						? [
							CWidgetField::FOREIGN_REFERENCE_KEY => CWidgetField::createTypedReference(
								CWidgetField::REFERENCE_DASHBOARD, CWidgetsData::DATA_TYPE_HOST_IDS
							)
						]
						: []
					)
			)
			->addField($this->isTemplateDashboard()
				? null
				: (new CWidgetFieldRadioButtonList('evaltype_host', _('Host tags'), [
					TAG_EVAL_TYPE_AND_OR => _('And/Or'),
					TAG_EVAL_TYPE_OR => _('Or')
				]))->setDefault(TAG_EVAL_TYPE_AND_OR)
			)
			->addField($this->isTemplateDashboard()
				? null
				: new CWidgetFieldTags('host_tags')
			)
			->addField(
				(new CWidgetFieldPatternSelectItem('items', _('Item patterns')))
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				(new CWidgetFieldRadioButtonList('item_match', _('Match patterns by'), [
					self::ITEM_MATCH_NAME_OR_KEY => _('Name or key'),
					self::ITEM_MATCH_NAME => _('Name'),
					self::ITEM_MATCH_KEY => _('Key')
				]))->setDefault(self::ITEM_MATCH_NAME_OR_KEY)
			)
			->addField(
				(new CWidgetFieldRadioButtonList('evaltype_item', _('Item tags'), [
					TAG_EVAL_TYPE_AND_OR => _('And/Or'),
					TAG_EVAL_TYPE_OR => _('Or')
				]))->setDefault(TAG_EVAL_TYPE_AND_OR)
			)
			->addField(
				new CWidgetFieldTags('item_tags')
			)
			->addField(
				(new CWidgetFieldSelect('sortorder', _('Sort by'), [
					self::SORT_HOST_NAME => _('Host, item name'),
					self::SORT_NAME => _('Item name'),
					self::SORT_KEY => _('Item key')
				]))->setDefault(self::SORT_HOST_NAME)
			)
			->addField(
				(new CWidgetFieldTimePeriod('time_period', _('Time period')))
					->setDefault([
						CWidgetField::FOREIGN_REFERENCE_KEY => CWidgetField::createTypedReference(
							CWidgetField::REFERENCE_DASHBOARD, CWidgetsData::DATA_TYPE_TIME_PERIOD
						)
					])
					->setDefaultPeriod(['from' => 'now-1h', 'to' => 'now'])
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				(new CWidgetFieldSelect('style', _('Style'), [
					self::STYLE_SEGMENTS => _('Segments'),
					self::STYLE_FLAT => _('Flat'),
					self::STYLE_CAPSULES => _('Capsules'),
					self::STYLE_UPTIME_BARS => _('Uptime bars'),
					self::STYLE_GLOSSY => _('Glossy'),
					self::STYLE_TRACK => _('Track and incidents'),
					self::STYLE_HATCHED => _('Hatched incidents')
				]))->setDefault(self::STYLE_SEGMENTS)
			)
			->addField(
				(new CWidgetFieldCheckBox('segment_labels', _('Show state labels')))->setDefault(1)
			)
			->addField(
				new CWidgetFieldTextBox('threshold', _('State 1 threshold'))
			)
			->addField(
				new CWidgetFieldTextBox('nodata_after', _('No data after'))
			)
			->addField(
				(new CWidgetFieldTextBox('state_1_label', _('Label')))
					->setDefault(_('UP'))
					->prefixLabel(_('State 1'))
			)
			->addField(
				(new CWidgetFieldColor('state_1_color', _('Color')))
					->setDefault(self::STATE_1_COLOR_DEFAULT)
					->prefixLabel(_('State 1'))
			)
			->addField(
				(new CWidgetFieldTextBox('state_0_label', _('Label')))
					->setDefault(_('DOWN'))
					->prefixLabel(_('State 0'))
			)
			->addField(
				(new CWidgetFieldColor('state_0_color', _('Color')))
					->setDefault(self::STATE_0_COLOR_DEFAULT)
					->prefixLabel(_('State 0'))
			)
			->addField(
				new CWidgetFieldColor('nodata_color', _('No data color'))
			)
			->addField(
				(new CWidgetFieldCheckBox('show_tooltip', _('Show tooltip')))->setDefault(1)
			)
			->addField(
				(new CWidgetFieldCheckBox('show_duration', _('Show state duration')))->setDefault(1)
			);
	}

	public function validate(bool $strict = false): array {
		if ($strict && $this->isTemplateDashboard()) {
			$this->getField('hostids')->setValue([
				CWidgetField::FOREIGN_REFERENCE_KEY => CWidgetField::createTypedReference(
					CWidgetField::REFERENCE_DASHBOARD, CWidgetsData::DATA_TYPE_HOST_IDS
				)
			]);
		}

		$errors = parent::validate($strict);

		if ($errors) {
			return $errors;
		}

		$threshold = trim($this->getFieldValue('threshold'));

		if ($threshold !== '' && self::parseThreshold($threshold) === null) {
			$errors[] = _s('Invalid parameter "%1$s": %2$s.', _('State 1 threshold'), _('a number is expected'));
		}

		$nodata_after = trim($this->getFieldValue('nodata_after'));

		if ($nodata_after !== '' && self::parseNoDataAfter($nodata_after) === null) {
			$errors[] = _s('Invalid parameter "%1$s": %2$s.', _('No data after'), _('a time unit is expected'));
		}

		return $errors;
	}

	/**
	 * Parse threshold. Numbers with size (K, M, G, T) and time (s, m, h, d, w) suffixes are supported.
	 *
	 * @return float|null  Null if value is invalid.
	 */
	public static function parseThreshold(string $value): ?float {
		$parser = new CNumberParser(['with_size_suffix' => true, 'with_time_suffix' => true]);

		if ($parser->parse(trim($value)) != CParser::PARSE_SUCCESS) {
			return null;
		}

		$threshold = (float) $parser->calcValue();

		return is_finite($threshold) ? $threshold : null;
	}

	/**
	 * Parse "No data after" period, for example "90", "5m" or "1h".
	 *
	 * @return int|null  Period in seconds, null if value is invalid or not positive.
	 */
	public static function parseNoDataAfter(string $value): ?int {
		$parser = new CSimpleIntervalParser();

		if ($parser->parse(trim($value)) != CParser::PARSE_SUCCESS) {
			return null;
		}

		$seconds = (int) timeUnitToSeconds(trim($value));

		return $seconds > 0 ? $seconds : null;
	}
}
