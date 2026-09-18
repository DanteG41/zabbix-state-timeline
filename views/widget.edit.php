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


/**
 * State timeline widget form view.
 *
 * @var CView $this
 * @var array $data
 */

$form = new CWidgetFormView($data);

$groupids_field = array_key_exists('groupids', $data['fields'])
	? new CWidgetFieldMultiSelectGroupView($data['fields']['groupids'])
	: null;

$hostids_field = $data['templateid'] === null
	? (new CWidgetFieldMultiSelectHostView($data['fields']['hostids']))
		->setFilterPreselect([
			'id' => $groupids_field->getId(),
			'accept' => CMultiSelect::FILTER_PRESELECT_ACCEPT_ID,
			'submit_as' => 'groupid'
		])
	: null;

$form
	->addField($groupids_field)
	->addField($hostids_field)
	->addField(array_key_exists('evaltype_host', $data['fields'])
		? new CWidgetFieldRadioButtonListView($data['fields']['evaltype_host'])
		: null
	)
	->addField(array_key_exists('host_tags', $data['fields'])
		? new CWidgetFieldTagsView($data['fields']['host_tags'])
		: null
	)
	->addField(
		(new CWidgetFieldPatternSelectItemView($data['fields']['items']))
			->setFilterPreselect($hostids_field !== null
				? [
					'id' => $hostids_field->getId(),
					'accept' => CMultiSelect::FILTER_PRESELECT_ACCEPT_ID,
					'submit_as' => 'hostid'
				]
				: []
			)
			->setFieldHint(
				makeHelpIcon([
					_('Patterns are matched against item names and/or keys, "*" matches any characters.'),
					BR(),
					_('Only numeric items are displayed: 0 is state 0, any other value is state 1.')
				])
			)
	)
	->addField(
		new CWidgetFieldRadioButtonListView($data['fields']['item_match'])
	)
	->addField(
		new CWidgetFieldRadioButtonListView($data['fields']['evaltype_item'])
	)
	->addField(
		new CWidgetFieldTagsView($data['fields']['item_tags'])
	)
	->addField(
		new CWidgetFieldSelectView($data['fields']['sortorder'])
	)
	->addField(
		(new CWidgetFieldTimePeriodView($data['fields']['time_period']))
			->setDateFormat(ZBX_FULL_DATE_TIME)
			->setFromPlaceholder(_('YYYY-MM-DD hh:mm:ss'))
			->setToPlaceholder(_('YYYY-MM-DD hh:mm:ss'))
	)
	->addField(
		new CWidgetFieldSelectView($data['fields']['style'])
	)
	->addField(
		(new CWidgetFieldCheckBoxView($data['fields']['segment_labels']))
			->setFieldHint(makeHelpIcon([
				_('State label and duration inside segments wide enough for the text.'),
				BR(),
				_('Segments style: all segments. Track and incidents style: state 0 segments (incidents) only.')
			]))
	)
	->addField(
		new CWidgetFieldCheckBoxView($data['fields']['show_tooltip'])
	)
	->addField(
		new CWidgetFieldCheckBoxView($data['fields']['show_duration'])
	)
	->addFieldset(
		(new CWidgetFormFieldsetCollapsibleView(_('Advanced configuration')))
			->addFieldsGroup(
				getStateFieldsGroupView(_('State 1'), $data['fields']['state_1_label'],
					$data['fields']['state_1_color']
				)
			)
			->addFieldsGroup(
				getStateFieldsGroupView(_('State 0'), $data['fields']['state_0_label'],
					$data['fields']['state_0_color']
				)
			)
			->addField(
				(new CWidgetFieldColorView($data['fields']['nodata_color']))
					->setFieldHint(makeHelpIcon(_('Leave empty to use the grid color of the theme.')))
			)
			->addField(
				(new CWidgetFieldTextBoxView($data['fields']['threshold']))
					->setPlaceholder(_('any non-zero value'))
					->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
					->setFieldHint(
						makeHelpIcon(_('Values greater than or equal to the threshold are state 1, other values are state 0. If empty, 0 is state 0 and any other value is state 1.'))
					)
			)
			->addField(
				(new CWidgetFieldTextBoxView($data['fields']['nodata_after']))
					->setPlaceholder(_('never'))
					->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
					->setFieldHint(
						makeHelpIcon(_('Period after the last collected value, after which the state is shown as unknown, for example, 5m. If empty, the last value is valid until the next value is collected.'))
					)
			)
	)
	->includeJsFile('widget.edit.js.php')
	->addJavaScript('widget_statetimeline_form.init();')
	->show();

function getStateFieldsGroupView(string $label, $label_field, $color_field): CWidgetFieldsGroupView {
	return (new CWidgetFieldsGroupView($label))
		->addField(
			(new CWidgetFieldTextBoxView($label_field))->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
		)
		->addField(
			new CWidgetFieldColorView($color_field)
		);
}
